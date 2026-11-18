<?php

namespace App\Services;

use App\AcademicRules\Executors\Attendance;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\AuditLog;
use App\Models\CourseOffering;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use App\Support\AuditContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lecture-by-lecture attendance. The teacher records who was absent; the absence percentage of
 * each student is the absent hours over the hours held so far. Warnings come from the
 * "attendance" policy. Until an official limit exists the system only warns (FR: no invented rules).
 */
class AttendanceService
{
    public function __construct(private readonly PolicyResolver $policies) {}

    /**
     * Saves one lecture. $statuses maps enrollment id → present|absent|excused|late; anyone omitted is present.
     *
     * @param  array{held_on: string, period?: int, hours: float, kind?: string, topic?: ?string}  $data
     * @param  array<int|string, string>  $statuses
     */
    public function record(CourseOffering $offering, User $user, array $data, array $statuses, ?AttendanceSession $session = null): AttendanceSession
    {
        if ($offering->status === 'locked') {
            throw new WorkflowException('الشعبة مقفلة، لا يمكن تعديل الحضور.');
        }
        $day = Carbon::parse($data['held_on'])->startOfDay();
        if ($day->isAfter(today())) {
            throw new WorkflowException('لا يمكن تسجيل حضور محاضرة في تاريخ لاحق.');
        }
        $period = (int) ($data['period'] ?? 1);
        $clash = AttendanceSession::where('course_offering_id', $offering->id)->whereDate('held_on', $day)->where('period', $period)
            ->when($session, fn ($q) => $q->whereKeyNot($session->id))->exists();
        if ($clash) {
            throw new WorkflowException("سُجّلت المحاضرة {$period} لهذا اليوم مسبقاً. اختر رقم محاضرة آخر أو عدّل المسجلة.");
        }

        $enrollments = $offering->enrollments()->where('status', '!=', 'withdrawn')->pluck('id');
        $unknown = collect(array_keys($statuses))->map(fn ($k) => (int) $k)->diff($enrollments);
        if ($unknown->isNotEmpty()) {
            throw new WorkflowException('بعض الطلبة غير مسجلين في هذه الشعبة.');
        }
        foreach ($statuses as $s) {
            if (! array_key_exists($s, AttendanceRecord::STATUSES)) {
                throw new WorkflowException('حالة حضور غير معروفة.');
            }
        }

        return DB::transaction(function () use ($offering, $user, $data, $statuses, $session, $day, $period, $enrollments) {
            $attrs = ['held_on' => $day->toDateString(), 'period' => $period, 'hours' => (float) $data['hours'], 'kind' => $data['kind'] ?? 'lecture', 'topic' => $data['topic'] ?? null];
            if ($session) {
                $session->update($attrs);
            } else {
                $session = AttendanceSession::create($attrs + ['course_offering_id' => $offering->id, 'recorded_by' => $user->id]);
            }

            $before = $session->records()->pluck('status', 'enrollment_id');
            $changed = [];
            foreach ($enrollments as $id) {
                $status = $statuses[$id] ?? $statuses[(string) $id] ?? 'present';
                if (($before[$id] ?? null) === $status) {
                    continue;
                }
                AttendanceRecord::updateOrCreate(['attendance_session_id' => $session->id, 'enrollment_id' => $id], ['status' => $status]);
                if (isset($before[$id])) {
                    $changed[$id] = [$before[$id], $status];
                }
            }
            // A change to an earlier register is kept in the audit log as one row per save.
            if ($changed) {
                AuditLog::create([
                    'user_id' => $user->id, 'action' => 'updated',
                    'auditable_type' => $session->getMorphClass(), 'auditable_id' => $session->id,
                    'old_values' => ['records' => array_map(fn ($c) => $c[0], $changed)],
                    'new_values' => ['records' => array_map(fn ($c) => $c[1], $changed)],
                    'reason' => AuditContext::reason(), 'ip' => AuditContext::ip(), 'user_agent' => AuditContext::userAgent(),
                ]);
            }

            return $session;
        });
    }

    public function delete(AttendanceSession $session): void
    {
        if ($session->offering->status === 'locked') {
            throw new WorkflowException('الشعبة مقفلة، لا يمكن تعديل الحضور.');
        }
        $session->delete();
    }

    /**
     * Absence summary of every enrollment in an offering, keyed by enrollment id.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function summary(CourseOffering $offering): Collection
    {
        $sessions = $offering->attendanceSessions()->with('records')->get();
        $rule = $this->ruleFor($offering);
        $held = (float) $sessions->sum('hours');
        $byEnrollment = [];
        foreach ($sessions as $s) {
            foreach ($s->records as $r) {
                $row = &$byEnrollment[$r->enrollment_id];
                $row ??= ['absent' => 0, 'excused' => 0, 'late' => 0, 'absence_hours' => 0.0];
                if ($r->status !== 'present') {
                    $row[$r->status]++;
                }
                $row['absence_hours'] += $this->absenceHours($rule, $r->status, $s->hours);
                unset($row);
            }
        }

        return $offering->enrollments()->where('status', '!=', 'withdrawn')->pluck('id')->mapWithKeys(function ($id) use ($byEnrollment, $held, $rule) {
            $row = $byEnrollment[$id] ?? ['absent' => 0, 'excused' => 0, 'late' => 0, 'absence_hours' => 0.0];

            return [$id => $this->present($row, $held, $rule)];
        });
    }

    /**
     * Absence per course for a student's current-year enrollments.
     *
     * @param  Collection<int, Enrollment>  $enrollments  loaded with offering.course
     * @return Collection<int, array<string, mixed>> keyed by enrollment id
     */
    public function forEnrollments(Collection $enrollments): Collection
    {
        return $enrollments->mapWithKeys(function (Enrollment $e) {
            $offering = $e->offering;
            $sessions = $offering->attendanceSessions()->get(['id', 'hours']);
            $rule = $this->ruleFor($offering);
            $records = AttendanceRecord::where('enrollment_id', $e->id)->whereIn('attendance_session_id', $sessions->pluck('id'))->get();
            $hours = $sessions->pluck('hours', 'id');
            $row = ['absent' => 0, 'excused' => 0, 'late' => 0, 'absence_hours' => 0.0];
            foreach ($records as $r) {
                if ($r->status !== 'present') {
                    $row[$r->status]++;
                }
                $row['absence_hours'] += $this->absenceHours($rule, $r->status, (float) $hours[$r->attendance_session_id]);
            }

            return [$e->id => $this->present($row, (float) $sessions->sum('hours'), $rule) + ['sessions' => $sessions->count()]];
        });
    }

    /** @return array{official: bool, warnings: array, limit: ?float}|null */
    public function policyInfo(CourseOffering $offering): ?array
    {
        $program = $this->programOf($offering);
        $policy = $program ? $this->policies->one($program, 'attendance') : null;
        if (! $policy) {
            return null;
        }
        $rule = new Attendance($policy);

        return ['official' => $policy->isOfficial, 'warnings' => $rule->warnings(), 'limit' => $rule->limit(), 'count_excused' => (bool) ($policy->parameters['count_excused'] ?? false)];
    }

    private function present(array $row, float $held, ?Attendance $rule): array
    {
        $percent = $held > 0 ? round($row['absence_hours'] / $held * 100, 1) : 0.0;
        $assessment = $rule?->assess($percent) ?? ['level' => 0, 'name_ar' => null, 'name_en' => null, 'over_limit' => false];

        return [
            'absent' => $row['absent'], 'excused' => $row['excused'], 'late' => $row['late'],
            'absence_hours' => round($row['absence_hours'], 1), 'held_hours' => $held, 'percent' => $percent,
            'level' => $assessment['level'], 'warning' => app()->getLocale() === 'en' ? ($assessment['name_en'] ?? $assessment['name_ar']) : $assessment['name_ar'],
            'over_limit' => $assessment['over_limit'],
        ];
    }

    private function absenceHours(?Attendance $rule, string $status, float $hours): float
    {
        return $rule ? $rule->absenceHours($status, $hours) : ($status === 'absent' ? $hours : 0.0);
    }

    /** @var array<int, ?Attendance> */
    private array $rules = [];

    private function ruleFor(CourseOffering $offering): ?Attendance
    {
        $program = $this->programOf($offering);
        if (! $program) {
            return null;
        }
        if (! array_key_exists($program->id, $this->rules)) {
            $policy = $this->policies->one($program, 'attendance');
            $this->rules[$program->id] = $policy ? new Attendance($policy) : null;
        }

        return $this->rules[$program->id];
    }

    private function programOf(CourseOffering $offering): ?Program
    {
        return $offering->program ?? $offering->enrollments()->first()?->studentProgram?->program;
    }

    /** Current-year enrollments of a student, with the offering and course. */
    public function currentEnrollments(Student $student): Collection
    {
        return Enrollment::where('student_id', $student->id)->where('status', '!=', 'withdrawn')
            ->whereHas('offering.term.year', fn ($y) => $y->where('is_current', true))
            ->with('offering.course:id,code,name_ar,name_en')->get();
    }
}
