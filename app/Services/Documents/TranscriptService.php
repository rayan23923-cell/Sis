<?php

namespace App\Services\Documents;

use App\Models\Enrollment;
use App\Models\FinalResult;
use App\Models\Program;
use App\Models\ProgressionDecision;
use App\Models\Student;

/** Locked results of a student grouped by academic year; shared by the PDF transcript and the student portal. */
class TranscriptService
{
    public const OUTCOMES = ['pass' => 'ناجح', 'fail' => 'راسب', 'resit' => 'دور ثانٍ', 'absent' => 'غائب', 'withdrawn' => 'منسحب'];

    public function build(Student $student): array
    {
        $student->loadMissing(['admissionYear:id,name', 'currentProgram.program.department.college']);
        $enrollments = Enrollment::where('student_id', $student->id)->where('status', '!=', 'withdrawn')
            ->with(['offering.course', 'offering.term.year', 'results' => fn ($q) => $q->where('is_current', true)->where('status', 'locked')])
            ->get();
        $decisions = ProgressionDecision::whereHas('studentProgram', fn ($q) => $q->where('student_id', $student->id))->get()->keyBy('academic_year_id');

        $official = true;
        $years = $enrollments->groupBy(fn ($e) => $e->offering->term->academic_year_id)
            ->sortBy(fn ($g) => $g->first()->offering->term->year->starts_on)
            ->map(function ($group, $yearId) use ($decisions, &$official) {
                $rows = $group->sortBy(fn ($e) => [$e->offering->term->code, $e->offering->course->code])->values()->map(function ($e) use (&$official) {
                    /** @var FinalResult|null $r */
                    $r = $e->results->sortByDesc('exam_round')->first();
                    if ($r && ! $r->isOfficial()) {
                        $official = false;
                    }

                    return [
                        'code' => $e->offering->course->code, 'name' => $e->offering->course->name_ar, 'name_en' => $e->offering->course->name_en,
                        'term' => $e->offering->term->name_ar, 'credits' => (float) $e->offering->course->credit_hours,
                        'mark' => $r ? (float) $r->rounded_mark : null, 'grade' => $r?->grade_name_ar, 'grade_code' => $r?->grade_code,
                        'round' => $r ? ($r->exam_round === 1 ? 'الأول' : 'الثاني') : '—', 'outcome_code' => $r?->outcome,
                        'outcome' => $r ? (self::OUTCOMES[$r->outcome] ?? $r->outcome) : 'لم تُعلن', 'failed' => $r && ! $r->passed,
                        'carried' => (bool) $e->is_carried, 'corrected' => $r?->administrative_order_id !== null,
                    ];
                });
                $d = $decisions->get($yearId);

                return [
                    'id' => $yearId, 'name' => $group->first()->offering->term->year->name, 'rows' => $rows->all(),
                    'gpa' => $d?->gpa !== null ? (float) $d->gpa : null,
                    'decision' => $d && $d->status === 'applied' ? ProgressionDecision::DECISIONS[$d->decision] : null,
                    'credits' => $rows->where('failed', false)->whereNotNull('mark')->sum('credits'),
                    'pending' => $rows->whereNull('mark')->count(),
                ];
            })->values();

        /** @var Program|null $program */
        $program = $student->currentProgram?->program;

        return [
            'student' => $student, 'program' => $program, 'stage' => $student->currentProgram?->stage,
            'status' => Student::STATUSES[$student->status] ?? $student->status,
            'system' => ['bologna' => 'بولونيا', 'semester' => 'فصلي', 'annual' => 'سنوي'][$program?->study_system] ?? '—',
            'years' => $years->all(), 'official' => $official,
        ];
    }
}
