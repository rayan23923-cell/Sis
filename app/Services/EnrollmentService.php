<?php

namespace App\Services;

use App\Models\CourseOffering;
use App\Models\CurriculumCourse;
use App\Models\Enrollment;
use App\Models\FinalResult;
use App\Models\Student;
use App\Models\User;
use App\Support\AuditContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class EnrollmentService
{
    /** Why this student cannot take this offering, or null if they can. */
    public function blocker(Student $student, CourseOffering $offering): ?string
    {
        if ($student->status !== 'active') {
            return 'الطالب ليس مستمراً ('.Student::STATUSES[$student->status].').';
        }
        $sp = $student->currentProgram;
        if (! $sp) {
            return 'لا يوجد برنامج حالي للطالب.';
        }
        if (($student->study_shift ?? 'morning') !== ($offering->shift ?? 'morning')) {
            return ($student->study_shift ?? 'morning') === 'evening'
                ? 'دراسة الطالب مسائية والشعبة صباحية.'
                : 'دراسة الطالب صباحية والشعبة مسائية.';
        }
        if (Enrollment::where('student_id', $student->id)->where('course_offering_id', $offering->id)->exists()) {
            return 'الطالب مسجل في هذه الشعبة.';
        }
        if ($offering->enrollments()->where('status', '!=', 'withdrawn')->count() >= $offering->capacity) {
            return 'الشعبة ممتلئة.';
        }
        $cc = CurriculumCourse::where('curriculum_version_id', $sp->curriculum_version_id)
            ->where('course_id', $offering->course_id)->with('prerequisites')->first();
        if ($cc) {
            foreach ($cc->prerequisites as $pre) {
                $passed = FinalResult::where('is_current', true)->where('passed', true)->where('status', 'locked')
                    ->whereHas('enrollment', fn ($e) => $e->where('student_id', $student->id)
                        ->whereHas('offering', fn ($o) => $o->where('course_id', $pre->id)))->exists();
                if (! $passed) {
                    return 'لم يجتز المتطلب السابق: '.$pre->name_ar.'.';
                }
            }
        }

        return null;
    }

    public function enroll(Student $student, CourseOffering $offering, User $user, bool $carried = false): Enrollment
    {
        // B-06 (FR-CC-01): one enrollment at a time per offering. The offering row is locked, then capacity,
        // duplicates and prerequisites are checked under that lock, so two requests cannot both take the last seat.
        return DB::transaction(function () use ($student, $offering, $user, $carried) {
            $offering = CourseOffering::whereKey($offering->id)->lockForUpdate()->firstOrFail();
            if ($reason = $this->blocker($student, $offering)) {
                throw new WorkflowException($reason);
            }
            $attempt = Enrollment::where('student_id', $student->id)
                ->whereHas('offering', fn ($o) => $o->where('course_id', $offering->course_id))->count() + 1;

            try {
                return AuditContext::as($user->id, null, fn () => Enrollment::create([
                    'student_id' => $student->id,
                    'course_offering_id' => $offering->id,
                    'student_program_id' => $student->currentProgram->id,
                    'attempt_no' => $attempt,
                    'is_carried' => $carried,
                    'status' => 'enrolled',
                ]));
            } catch (UniqueConstraintViolationException) {
                // the database's own guard (unique student + offering), should anything bypass the lock
                throw new WorkflowException('الطالب مسجل في هذه الشعبة.');
            }
        });
    }

    /**
     * Enroll every active student of the offering's program and stage.
     *
     * @return array{enrolled: int, skipped: array<string, string>}
     */
    public function enrollCohort(CourseOffering $offering, User $user): array
    {
        if (! $offering->program_id || ! $offering->stage) {
            throw new WorkflowException('حدد البرنامج والمرحلة للشعبة أولاً.');
        }
        $students = Student::where('status', 'active')
            ->where('study_shift', $offering->shift ?? 'morning')
            ->whereHas('currentProgram', fn ($q) => $q->where('program_id', $offering->program_id)->where('stage', $offering->stage))
            ->with('currentProgram')->orderBy('name_ar')->get();
        $enrolled = 0;
        $skipped = [];
        DB::transaction(function () use ($students, $offering, $user, &$enrolled, &$skipped) {
            foreach ($students as $s) {
                $reason = $this->blocker($s, $offering);
                if ($reason === 'الطالب مسجل في هذه الشعبة.') {
                    continue;
                }
                if ($reason) {
                    $skipped[$s->name_ar] = $reason;

                    continue;
                }
                $this->enroll($s, $offering, $user);
                $enrolled++;
            }
        });

        return ['enrolled' => $enrolled, 'skipped' => $skipped];
    }
}
