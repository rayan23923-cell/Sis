<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\CourseOffering;
use App\Models\Room;
use App\Models\Student;
use App\Models\TimetableSlot;
use App\Models\User;
use App\Support\AuditContext;
use Illuminate\Support\Collection;

/**
 * Places sections on the weekly timetable and refuses a clash:
 *  - a room holds one class at a time;
 *  - a teacher teaches one class at a time;
 *  - a cohort (program, stage, section, shift) attends one class at a time.
 * A room smaller than the section is allowed with a warning.
 */
class TimetableService
{
    public const EARLIEST = '07:00';

    public const LATEST = '22:00';

    /**
     * @param  array{day: int, starts_at: string, ends_at: string, room_id: ?int, kind: string}  $data
     * @return list<string> warnings
     */
    public function place(CourseOffering $offering, array $data, User $user, ?TimetableSlot $slot = null): array
    {
        $data['starts_at'] = substr($data['starts_at'], 0, 5);
        $data['ends_at'] = substr($data['ends_at'], 0, 5);
        if ($data['ends_at'] <= $data['starts_at']) {
            throw new WorkflowException('وقت الانتهاء يجب أن يكون بعد وقت البدء.');
        }
        if ($data['starts_at'] < self::EARLIEST || $data['ends_at'] > self::LATEST) {
            throw new WorkflowException('المحاضرات بين '.self::EARLIEST.' و'.self::LATEST.'.');
        }
        $room = $data['room_id'] ? Room::findOrFail($data['room_id']) : null;
        if ($room && $room->college_id !== $offering->collegeId()) {
            throw new WorkflowException('القاعة ليست من قاعات كلية المادة.');
        }

        $problems = $this->clashes($offering, (int) $data['day'], $data['starts_at'], $data['ends_at'], $room?->id, $slot?->id);
        if ($problems) {
            throw (new WorkflowException('يتعارض هذا الوقت مع مواعيد أخرى.'))->withProblems($problems);
        }

        $warnings = [];
        $size = $offering->enrollments()->where('status', '!=', 'withdrawn')->count();
        if ($room && $room->capacity && $size > $room->capacity) {
            $warnings[] = "سعة القاعة {$room->capacity} وعدد طلبة الشعبة {$size}.";
        }

        AuditContext::as($user->id, $slot ? 'تعديل موعد في الجدول الأسبوعي' : 'إضافة موعد إلى الجدول الأسبوعي', function () use ($offering, $data, $user, $slot) {
            $values = ['day' => $data['day'], 'starts_at' => $data['starts_at'], 'ends_at' => $data['ends_at'], 'room_id' => $data['room_id'] ?: null, 'kind' => $data['kind']];
            $slot ? $slot->update($values) : $offering->slots()->create($values + ['created_by' => $user->id]);
        });

        return $warnings;
    }

    public function remove(TimetableSlot $slot, User $user): void
    {
        AuditContext::as($user->id, 'حذف موعد من الجدول الأسبوعي', fn () => $slot->delete());
    }

    /** @return list<string> */
    public function clashes(CourseOffering $offering, int $day, string $from, string $to, ?int $roomId, ?int $ignore = null): array
    {
        $overlapping = TimetableSlot::where('day', $day)->where('starts_at', '<', $to)->where('ends_at', '>', $from)
            ->when($ignore, fn ($q) => $q->where('id', '!=', $ignore))
            ->whereHas('offering', fn ($o) => $o->whereIn('academic_term_id', $this->overlappingTerms($offering->term)))
            ->with(['offering.course:id,code,name_ar', 'offering.teacher:id,name', 'room:id,code,name_ar'])->get();

        $out = [];
        foreach ($overlapping as $s) {
            $o = $s->offering;
            $what = $o->course->name_ar.' (شعبة '.$o->section.') '.substr($s->starts_at, 0, 5).'–'.substr($s->ends_at, 0, 5);
            if ($roomId && $s->room_id === $roomId) {
                $out[] = 'القاعة '.$s->room->name_ar.' محجوزة لـ '.$what;
            }
            if ($offering->teacher_id && $o->teacher_id === $offering->teacher_id && $o->id !== $offering->id) {
                $out[] = 'التدريسي '.$o->teacher->name.' عنده '.$what;
            }
            if ($o->program_id && $o->program_id === $offering->program_id && $o->stage === $offering->stage
                && $o->section === $offering->section && ($o->shift ?? 'morning') === ($offering->shift ?? 'morning')) {
                $out[] = 'طلبة المرحلة نفسها عندهم '.$what;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * A year term (annual programs) runs alongside the semesters, so a room or teacher is busy across all of them.
     *
     * @return list<int>
     */
    public function overlappingTerms(AcademicTerm $term): array
    {
        if (! $term->starts_on || ! $term->ends_on) {
            return [$term->id];
        }

        return AcademicTerm::where('academic_year_id', $term->academic_year_id)
            ->where(fn ($q) => $q->whereKey($term->id)->orWhere(fn ($d) => $d->whereDate('starts_on', '<=', $term->ends_on)->whereDate('ends_on', '>=', $term->starts_on)))
            ->pluck('id')->all();
    }

    /** @return Collection<int, array> slots as the week grid draws them */
    public function present(Collection $slots): Collection
    {
        return $slots->map(fn (TimetableSlot $s) => [
            'id' => $s->id, 'offering_id' => $s->course_offering_id, 'day' => $s->day,
            'starts_at' => substr($s->starts_at, 0, 5), 'ends_at' => substr($s->ends_at, 0, 5), 'kind' => $s->kind,
            'code' => $s->offering->course->code, 'name' => $s->offering->course->name_ar,
            'section' => $s->offering->section, 'stage' => $s->offering->stage, 'shift' => $s->offering->shift,
            'teacher' => $s->offering->teacher?->name, 'room' => $s->room?->name_ar, 'room_id' => $s->room_id,
            'program' => $s->offering->program?->name_ar,
        ])->values();
    }

    /** The week of one student: the sections they are enrolled in this term. */
    /** @param  list<int>  $termIds */
    public function forStudent(Student $student, array $termIds): Collection
    {
        return TimetableSlot::whereHas('offering', fn ($o) => $o->whereIn('academic_term_id', $termIds)
            ->whereHas('enrollments', fn ($e) => $e->where('student_id', $student->id)->where('status', '!=', 'withdrawn')))
            ->with(self::WITH)->orderBy('day')->orderBy('starts_at')->get();
    }

    /** @param  list<int>  $termIds */
    public function forTeacher(User $user, array $termIds): Collection
    {
        return TimetableSlot::whereHas('offering', fn ($o) => $o->whereIn('academic_term_id', $termIds)->where('teacher_id', $user->id))
            ->with(self::WITH)->orderBy('day')->orderBy('starts_at')->get();
    }

    /**
     * The terms in session today (an annual year term and a semester can run together); between terms, the
     * year term and the latest semester of the current year.
     *
     * @return Collection<int, AcademicTerm>
     */
    public static function currentTerms(): Collection
    {
        $terms = AcademicTerm::whereHas('year', fn ($y) => $y->where('is_current', true))->orderBy('starts_on')->get();
        $today = $terms->filter(fn ($t) => $t->starts_on && $t->ends_on && now()->between($t->starts_on, $t->ends_on->copy()->endOfDay()));
        if ($today->isNotEmpty()) {
            return $today->values();
        }

        return collect([$terms->firstWhere('code', 'Y'), $terms->where('code', '!=', 'Y')->sortByDesc('starts_on')->first()])->filter()->values();
    }

    public static function currentTerm(): ?AcademicTerm
    {
        return self::currentTerms()->first();
    }

    public const WITH = ['offering.course:id,code,name_ar', 'offering.teacher:id,name', 'offering.program:id,name_ar', 'room:id,code,name_ar'];
}
