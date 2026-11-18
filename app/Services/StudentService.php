<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\AdministrativeOrder;
use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentProgram;
use App\Models\StudentStatusChange;
use App\Models\User;
use App\Support\AuditContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Admission and status changes. One person is one student row; every status change is a
 * dated record pointing at the administrative order that made it.
 */
class StudentService
{
    /** Allowed status moves and the order types that may make them. */
    public const TRANSITIONS = [
        'active' => ['postponed' => 'postponement', 'transferred_out' => 'transfer', 'hosted' => 'hosting', 'dismissed' => 'dismissal', 'withdrawn' => 'withdrawal', 'graduated' => 'other'],
        'postponed' => ['active' => 'reinstatement', 'dismissed' => 'dismissal', 'withdrawn' => 'withdrawal'],
        'hosted' => ['active' => 'reinstatement'],
        'dismissed' => ['active' => 'reinstatement'],
        'withdrawn' => ['active' => 'reinstatement'],
        'transferred_out' => [],
        'graduated' => [],
    ];

    /** Possible duplicates of a new person: same national ID, or same name + birth date + mother. */
    public function possibleDuplicates(array $data): Collection
    {
        return Student::query()
            ->where(function ($q) use ($data) {
                if (! empty($data['national_id'])) {
                    $q->orWhere('national_id', $data['national_id']);
                }
                if (! empty($data['birth_date'])) {
                    $q->orWhere(fn ($w) => $w->where('name_ar', trim($data['name_ar']))
                        ->whereDate('birth_date', $data['birth_date'])
                        ->when(! empty($data['mother_name_ar']), fn ($m) => $m->where(fn ($x) => $x->where('mother_name_ar', trim($data['mother_name_ar']))->orWhereNull('mother_name_ar'))));
                }
            })
            ->when(empty($data['national_id']) && empty($data['birth_date']), fn ($q) => $q->whereRaw('1 = 0'))
            ->limit(5)->get();
    }

    public function admit(array $data, Program $program, ?CurriculumVersion $curriculum, AcademicYear $year, User $user, ?AdministrativeOrder $order = null): Student
    {
        $curriculum ??= $program->curricula()->where('status', 'approved')->orderByDesc('version')->first();
        if (! $curriculum) {
            throw new WorkflowException('لا يوجد منهج معتمد لهذا البرنامج. اعتمد منهجاً قبل قبول الطلبة.');
        }
        if (! ($data['allow_duplicate'] ?? false) && $this->possibleDuplicates($data)->isNotEmpty()) {
            throw new WorkflowException('يوجد طالب بالبيانات نفسها. راجع قائمة التشابه قبل الحفظ.');
        }

        return DB::transaction(fn () => AuditContext::as($user->id, $order ? 'قبول — '.$order->reference() : 'قبول', function () use ($data, $program, $curriculum, $year, $user, $order) {
            $student = Student::create([
                'university_number' => $this->nextNumber($program, $year),
                'national_id' => $data['national_id'] ?? null,
                'name_ar' => trim($data['name_ar']),
                'name_en' => $data['name_en'] ?? null,
                'mother_name_ar' => isset($data['mother_name_ar']) ? trim($data['mother_name_ar']) : null,
                'birth_date' => $data['birth_date'] ?? null,
                'gender' => $data['gender'] ?? null,
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'status' => 'active',
                'admission_year_id' => $year->id,
                'admission_channel' => $data['admission_channel'] ?? null,
                'study_shift' => Student::shiftFor($data['admission_channel'] ?? null, $data['study_shift'] ?? null),
            ]);
            $student->programs()->create([
                'program_id' => $program->id,
                'curriculum_version_id' => $curriculum->id,
                'stage' => (int) ($data['stage'] ?? 1),
                'start_year_id' => $year->id,
                'is_current' => true,
                'administrative_order_id' => $order?->id,
            ]);
            StudentStatusChange::create([
                'student_id' => $student->id, 'from_status' => null, 'to_status' => 'active',
                'effective_on' => $order?->issued_on ?? now()->toDateString(),
                'administrative_order_id' => $order?->id, 'notes' => 'قبول', 'created_by' => $user->id,
            ]);

            return $student;
        }));
    }

    public function changeStatus(Student $student, string $to, AdministrativeOrder $order, User $user, ?string $notes = null, ?string $effectiveOn = null): void
    {
        $allowed = self::TRANSITIONS[$student->status] ?? [];
        if (! array_key_exists($to, $allowed)) {
            throw new WorkflowException('لا يمكن نقل الطالب من «'.Student::STATUSES[$student->status].'» إلى «'.(Student::STATUSES[$to] ?? $to).'».');
        }
        if ($order->type !== $allowed[$to] && $order->type !== 'other') {
            throw new WorkflowException('نوع الأمر الإداري لا يناسب هذا التغيير. المطلوب: «'.AdministrativeOrder::TYPES[$allowed[$to]].'».');
        }

        DB::transaction(fn () => AuditContext::as($user->id, $order->reference(), function () use ($student, $to, $order, $user, $notes, $effectiveOn) {
            StudentStatusChange::create([
                'student_id' => $student->id, 'from_status' => $student->status, 'to_status' => $to,
                'effective_on' => $effectiveOn ?? $order->issued_on, 'administrative_order_id' => $order->id,
                'notes' => $notes, 'created_by' => $user->id,
            ]);
            $student->update(['status' => $to]);
        }));
    }

    /**
     * Transfer to another department/program inside the university: the person keeps one record and number;
     * the old program is closed (kept in history) and the new one starts on its latest approved curriculum.
     * Course equivalence is a policy decision and is not done here; earlier results stay on the transcript.
     */
    public function changeProgram(Student $student, Program $program, int $stage, AdministrativeOrder $order, User $user, ?string $notes = null): StudentProgram
    {
        if ($student->status !== 'active') {
            throw new WorkflowException('لا يُنقل بين الأقسام إلا الطالب المستمر.');
        }
        if (! in_array($order->type, ['program_change', 'other'], true)) {
            throw new WorkflowException('نوع الأمر الإداري لا يناسب هذا التغيير. المطلوب: «'.AdministrativeOrder::TYPES['program_change'].'».');
        }
        $current = $student->programs()->where('is_current', true)->with('program')->first();
        if ($current?->program_id === $program->id) {
            throw new WorkflowException('الطالب مسجل أصلاً في هذا البرنامج.');
        }
        if ($stage > $program->duration_years) {
            throw new WorkflowException('المرحلة أكبر من مدة دراسة البرنامج.');
        }
        $curriculum = $program->curricula()->where('status', 'approved')->orderByDesc('version')->first()
            ?? throw new WorkflowException('لا يوجد منهج معتمد للبرنامج الجديد.');
        $year = AcademicYear::where('is_current', true)->first() ?? AcademicYear::latest('starts_on')->firstOrFail();

        return DB::transaction(fn () => AuditContext::as($user->id, $order->reference(), function () use ($student, $program, $stage, $order, $user, $notes, $current, $curriculum, $year) {
            $current?->update(['is_current' => false, 'end_year_id' => $year->id, 'end_reason' => 'program_change']);
            $new = $student->programs()->create([
                'program_id' => $program->id, 'curriculum_version_id' => $curriculum->id, 'stage' => $stage,
                'start_year_id' => $year->id, 'is_current' => true, 'administrative_order_id' => $order->id,
            ]);
            StudentStatusChange::create([
                'student_id' => $student->id, 'from_status' => 'active', 'to_status' => 'active',
                'effective_on' => $order->issued_on, 'administrative_order_id' => $order->id, 'created_by' => $user->id,
                'notes' => trim('تغيير قسم/برنامج: '.($current?->program?->name_ar ?? '—').' ← '.$program->name_ar.' (المرحلة '.$stage.'). '.($notes ?? '')),
            ]);

            return $new;
        }));
    }

    /** {admission year}{2-digit college}{sequence}, e.g. 2025 03 00042 → 20250300042. */
    public function nextNumber(Program $program, AcademicYear $year): string
    {
        $prefix = substr($year->name, 0, 4).str_pad((string) ($program->collegeId() % 100), 2, '0', STR_PAD_LEFT);
        $digits = (int) config('sis.student_number.sequence_digits', 5);
        $last = Student::where('university_number', 'like', $prefix.'%')->lockForUpdate()->max('university_number');
        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $seq, $digits, '0', STR_PAD_LEFT);
    }
}
