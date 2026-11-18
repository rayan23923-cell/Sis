<?php

namespace App\Services\Reports;

use App\Models\AcademicYear;
use App\Models\College;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Statistics for the university and the ministry, limited to the colleges the user may see.
 * Every table has the same shape so the page and the Excel export share it.
 */
class ReportService
{
    public const TABLES = [
        'students_by_college' => 'الطلبة حسب الكلية والحالة',
        'students_by_shift' => 'الطلبة المستمرون حسب نوع الدراسة وقناة القبول',
        'students_by_stage' => 'الطلبة المستمرون حسب البرنامج والمرحلة',
        'admissions' => 'المقبولون في العام حسب قناة القبول',
        'results_by_college' => 'النتائج المقفلة في العام حسب الكلية',
        'hardest_courses' => 'المواد الأعلى رسوباً في العام',
        'graduates' => 'الخريجون في العام',
    ];

    private const CHANNELS = ['central' => 'مركزي', 'parallel' => 'موازي', 'evening' => 'مسائي', 'transfer' => 'انتقال'];

    /** @return array{key: string, title: string, columns: array<int, string>, rows: array<int, array<int, mixed>>, total: ?array<int, mixed>} */
    public function table(string $key, User $user, AcademicYear $year): array
    {
        $colleges = College::visibleTo($user)->orderBy('name_ar')->pluck('name_ar', 'id');
        [$columns, $rows, $total] = match ($key) {
            'students_by_college' => $this->studentsByCollege($colleges),
            'students_by_shift' => $this->studentsByShift($colleges),
            'students_by_stage' => $this->studentsByStage($colleges),
            'admissions' => $this->admissions($colleges, $year),
            'results_by_college' => $this->resultsByCollege($colleges, $year),
            'hardest_courses' => $this->hardestCourses($colleges, $year),
            'graduates' => $this->graduates($colleges, $year),
        };

        return ['key' => $key, 'title' => self::TABLES[$key], 'columns' => $columns, 'rows' => $rows, 'total' => $total];
    }

    /** Students joined to their current program's college. */
    private function students(Collection $colleges): Builder
    {
        return DB::table('students')
            ->join('student_programs as sp', fn ($j) => $j->on('sp.student_id', '=', 'students.id')->where('sp.is_current', true))
            ->join('programs', 'programs.id', '=', 'sp.program_id')
            ->join('departments', 'departments.id', '=', 'programs.department_id')
            ->whereIn('departments.college_id', $colleges->keys());
    }

    private function studentsByCollege(Collection $colleges): array
    {
        $statuses = ['active', 'postponed', 'hosted', 'graduated'];
        $data = $this->students($colleges)->select('departments.college_id', 'students.status', 'students.gender', DB::raw('count(*) as n'))
            ->groupBy('departments.college_id', 'students.status', 'students.gender')->get();
        $rows = $colleges->map(function ($name, $id) use ($data, $statuses) {
            $c = $data->where('college_id', $id);
            $by = fn ($s) => (int) $c->where('status', $s)->sum('n');

            return [$name, ...array_map($by, $statuses), (int) $c->whereNotIn('status', $statuses)->sum('n'),
                (int) $c->sum('n'), (int) $c->where('gender', 'm')->sum('n'), (int) $c->where('gender', 'f')->sum('n')];
        })->values()->all();

        return [['الكلية', ...array_map(fn ($s) => Student::STATUSES[$s], $statuses), 'منسحب/مرقن/منقول', 'المجموع', 'ذكور', 'إناث'], $rows, $this->sumRow($rows)];
    }

    private function studentsByShift(Collection $colleges): array
    {
        $data = $this->students($colleges)->where('students.status', 'active')
            ->select('departments.college_id', 'students.study_shift as shift', 'students.admission_channel as ch', DB::raw('count(*) as n'))
            ->groupBy('departments.college_id', 'students.study_shift', 'students.admission_channel')->get();
        $rows = $colleges->map(function ($name, $id) use ($data) {
            $c = $data->where('college_id', $id);
            $m = $c->where('shift', 'morning');

            return [$name, (int) $m->where('ch', 'central')->sum('n'), (int) $m->where('ch', 'parallel')->sum('n'), (int) $m->whereNotIn('ch', ['central', 'parallel'])->sum('n'),
                (int) $m->sum('n'), (int) $c->where('shift', 'evening')->sum('n'), (int) $c->sum('n')];
        })->values()->all();

        return [['الكلية', 'صباحي مركزي', 'صباحي موازي', 'صباحي (أخرى)', 'مجموع الصباحي', 'المسائي', 'المجموع'], $rows, $this->sumRow($rows)];
    }

    private function studentsByStage(Collection $colleges): array
    {
        $data = $this->students($colleges)->where('students.status', 'active')
            ->select('departments.college_id', 'programs.name_ar as program', 'sp.stage', DB::raw('count(*) as n'))
            ->groupBy('departments.college_id', 'programs.name_ar', 'sp.stage')->get();
        $max = max(4, (int) $data->max('stage'));
        $rows = $data->groupBy(fn ($r) => $r->college_id.'|'.$r->program)->map(function ($g) use ($colleges, $max) {
            $cells = [];
            for ($s = 1; $s <= $max; $s++) {
                $cells[] = (int) $g->where('stage', $s)->sum('n');
            }

            return [$colleges[$g->first()->college_id], $g->first()->program, ...$cells, array_sum($cells)];
        })->sortBy(fn ($r) => $r[0].$r[1])->values()->all();

        return [['الكلية', 'البرنامج', ...array_map(fn ($s) => 'المرحلة '.$s, range(1, $max)), 'المجموع'], $rows, $this->sumRow($rows, 2)];
    }

    private function admissions(Collection $colleges, AcademicYear $year): array
    {
        $data = $this->students($colleges)->where('students.admission_year_id', $year->id)
            ->select('departments.college_id', 'students.admission_channel as ch', DB::raw('count(*) as n'))
            ->groupBy('departments.college_id', 'students.admission_channel')->get();
        $rows = $colleges->map(function ($name, $id) use ($data) {
            $c = $data->where('college_id', $id);
            $cells = array_map(fn ($ch) => (int) $c->where('ch', $ch)->sum('n'), array_keys(self::CHANNELS));

            return [$name, ...$cells, (int) $c->whereNotIn('ch', array_keys(self::CHANNELS))->sum('n'), (int) $c->sum('n')];
        })->values()->all();

        return [['الكلية', ...array_values(self::CHANNELS), 'أخرى', 'المجموع'], $rows, $this->sumRow($rows)];
    }

    /** Current locked results of offerings in the year's terms. */
    private function results(Collection $colleges, AcademicYear $year): Builder
    {
        return DB::table('final_results as fr')
            ->join('enrollments as e', 'e.id', '=', 'fr.enrollment_id')
            ->join('course_offerings as o', 'o.id', '=', 'e.course_offering_id')
            ->join('academic_terms as t', 't.id', '=', 'o.academic_term_id')
            ->join('courses as c', 'c.id', '=', 'o.course_id')
            ->join('departments as d', 'd.id', '=', 'c.department_id')
            ->where('t.academic_year_id', $year->id)->where('fr.is_current', true)->where('fr.status', 'locked')
            ->whereIn('d.college_id', $colleges->keys())
            // the latest round only
            ->whereRaw('fr.exam_round = (select max(f2.exam_round) from final_results f2 where f2.enrollment_id = fr.enrollment_id and f2.is_current = ? and f2.status = ?)', [true, 'locked']);
    }

    private function resultsByCollege(Collection $colleges, AcademicYear $year): array
    {
        $data = $this->results($colleges, $year)->select('d.college_id', 'fr.outcome', DB::raw('count(*) as n'))->groupBy('d.college_id', 'fr.outcome')->get();
        $rows = $colleges->map(function ($name, $id) use ($data) {
            $c = $data->where('college_id', $id);
            $all = (int) $c->sum('n');
            $pass = (int) $c->where('outcome', 'pass')->sum('n');

            return [$name, $all, $pass, (int) $c->where('outcome', 'fail')->sum('n'), (int) $c->where('outcome', 'resit')->sum('n'),
                (int) $c->whereIn('outcome', ['absent', 'withdrawn'])->sum('n'), $all ? round($pass * 100 / $all, 1).'%' : '—'];
        })->values()->all();
        $total = $this->sumRow($rows, 1, 5);
        if ($total) {
            $total[] = $total[1] ? round($total[2] * 100 / $total[1], 1).'%' : '—';
        }

        return [['الكلية', 'النتائج', 'ناجح', 'راسب', 'دور ثانٍ', 'غائب/منسحب', 'نسبة النجاح'], $rows, $total];
    }

    private function hardestCourses(Collection $colleges, AcademicYear $year): array
    {
        $rows = $this->results($colleges, $year)
            ->select('c.code', 'c.name_ar', 'd.name_ar as dept', DB::raw('count(*) as n'), DB::raw('sum(case when fr.passed then 0 else 1 end) as failed'))
            ->groupBy('c.code', 'c.name_ar', 'd.name_ar')->havingRaw('count(*) >= 5')->get()
            ->map(fn ($r) => [$r->code, $r->name_ar, $r->dept, (int) $r->n, (int) $r->failed, round($r->failed * 100 / $r->n, 1)])
            ->sortByDesc(5)->take(15)->map(fn ($r) => [...array_slice($r, 0, 5), $r[5].'%'])->values()->all();

        return [['الرمز', 'المادة', 'القسم', 'الطلبة', 'غير الناجحين', 'نسبة عدم النجاح'], $rows, null];
    }

    private function graduates(Collection $colleges, AcademicYear $year): array
    {
        $data = $this->students($colleges)
            ->join('student_status_changes as sc', fn ($j) => $j->on('sc.student_id', '=', 'students.id')->where('sc.to_status', 'graduated'))
            ->whereBetween('sc.effective_on', [$year->starts_on, $year->ends_on])
            ->select('departments.college_id', 'programs.name_ar as program', 'students.gender', DB::raw('count(distinct students.id) as n'))
            ->groupBy('departments.college_id', 'programs.name_ar', 'students.gender')->get();
        $rows = $data->groupBy(fn ($r) => $r->college_id.'|'.$r->program)->map(fn ($g) => [
            $colleges[$g->first()->college_id], $g->first()->program, (int) $g->sum('n'), (int) $g->where('gender', 'm')->sum('n'), (int) $g->where('gender', 'f')->sum('n'),
        ])->values()->all();

        return [['الكلية', 'البرنامج', 'الخريجون', 'ذكور', 'إناث'], $rows, $this->sumRow($rows, 2)];
    }

    /** "المجموع" row summing the numeric columns from $from (to $to inclusive). */
    private function sumRow(array $rows, int $from = 1, ?int $to = null): ?array
    {
        if (count($rows) < 2) {
            return null;
        }
        $width = count($rows[0]);
        $to ??= $width - 1;
        $total = array_fill(0, $to + 1, '');
        $total[0] = 'المجموع';
        for ($i = $from; $i <= $to; $i++) {
            $total[$i] = array_sum(array_map(fn ($r) => is_numeric($r[$i]) ? $r[$i] : 0, $rows));
        }

        return $total;
    }
}
