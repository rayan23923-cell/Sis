<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\CurriculumCourse;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use App\Services\EnrollmentService;
use App\Services\GradebookService;
use App\Services\WorkflowException;
use App\Support\AuditContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Opening course sections, assigning teachers and managing who sits in them. */
class OfferingAdminController extends Controller
{
    public function __construct(private readonly GradebookService $gradebook, private readonly EnrollmentService $enrollments) {}

    public function create(Request $request): Response
    {
        Gate::authorize('manage-offerings');
        $user = $request->user();

        return Inertia::render('Offerings/Create', [
            'terms' => AcademicTerm::with('year:id,name')->orderByDesc('id')->get()->map(fn ($t) => ['id' => $t->id, 'name' => $t->label(), 'code' => $t->code, 'current' => (bool) $t->year?->is_current]),
            'programs' => Program::visibleTo($user)->where('is_active', true)->with('department:id,name_ar,college_id')->orderBy('name_ar')->get()
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name_ar, 'department' => $p->department->name_ar, 'department_id' => $p->department_id, 'system' => $p->study_system, 'years' => $p->duration_years]),
            'courses' => Course::visibleTo($user)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name_ar', 'department_id']),
            'teachers' => $this->teachers($user),
        ]);
    }

    /** Opens one section, or every curriculum course of a stage in a term at once. */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-offerings');
        $data = $request->validate([
            'mode' => ['required', Rule::in(['single', 'stage'])],
            'academic_term_id' => ['required', 'exists:academic_terms,id'],
            'program_id' => ['required', 'exists:programs,id'],
            'stage' => ['required', 'integer', 'min:1', 'max:7'],
            'course_id' => ['required_if:mode,single', 'nullable', 'exists:courses,id'],
            'section' => ['required', 'string', 'max:10'],
            'shift' => ['nullable', Rule::in(array_keys(Student::SHIFTS))],
            'teacher_id' => ['nullable', 'exists:users,id'],
            'capacity' => ['required', 'integer', 'min:1', 'max:1000'],
            'enroll' => ['boolean'],
        ], [], ['course_id' => 'المادة', 'program_id' => 'البرنامج', 'academic_term_id' => 'الفصل']);
        $program = Program::visibleTo($request->user())->findOrFail($data['program_id']);
        $term = AcademicTerm::findOrFail($data['academic_term_id']);

        $courseIds = $data['mode'] === 'single' ? [(int) $data['course_id']] : $this->stageCourses($program, (int) $data['stage'], $term);
        if ($courseIds === []) {
            throw new WorkflowException('لا توجد مواد في المنهج المعتمد لهذه المرحلة وهذا الفصل.');
        }

        $created = 0;
        $enrolled = 0;
        // the plan's evaluative-exam flag is the default; the registrar adjusts it per year on the offering
        $evaluative = CurriculumCourse::whereHas('curriculum', fn ($c) => $c->where('program_id', $program->id)->where('status', 'approved'))
            ->where('is_evaluative', true)->pluck('course_id')->all();
        DB::transaction(function () use ($courseIds, $data, $term, $request, $evaluative, &$created, &$enrolled) {
            foreach ($courseIds as $courseId) {
                $exists = CourseOffering::where('course_id', $courseId)->where('academic_term_id', $term->id)->where('section', $data['section'])->exists();
                if ($exists) {
                    continue;
                }
                $o = CourseOffering::create([
                    'course_id' => $courseId, 'academic_term_id' => $term->id, 'program_id' => $data['program_id'],
                    'stage' => $data['stage'], 'section' => $data['section'], 'shift' => $data['shift'] ?? 'morning', 'capacity' => $data['capacity'],
                    'teacher_id' => $data['mode'] === 'single' ? ($data['teacher_id'] ?? null) : null, 'status' => 'open', 'current_round' => 1,
                    'is_evaluative' => in_array((int) $courseId, array_map('intval', $evaluative), true),
                ]);
                $this->gradebook->ensureComponents($o);
                $created++;
                if ($data['enroll'] ?? false) {
                    $enrolled += $this->enrollments->enrollCohort($o, $request->user())['enrolled'];
                }
            }
        });

        $msg = $created ? "فُتحت {$created} شعبة" : 'الشعب موجودة مسبقاً';
        $msg .= $enrolled ? " وسُجّل فيها {$enrolled} تسجيلاً." : '.';

        return redirect()->route('offerings.index', ['term' => $term->id])->with('success', $msg);
    }

    public function update(Request $request, CourseOffering $offering): RedirectResponse
    {
        $this->authorizeOffering($request, $offering);
        $data = $request->validate([
            'teacher_id' => ['nullable', 'exists:users,id'],
            'capacity' => ['required', 'integer', 'min:1', 'max:1000'],
            'shift' => ['required', Rule::in(array_keys(Student::SHIFTS))],
            'is_evaluative' => ['boolean'],
            'section' => ['required', 'string', 'max:10', Rule::unique('course_offerings')->where('course_id', $offering->course_id)->where('academic_term_id', $offering->academic_term_id)->ignore($offering->id)],
        ], [], ['section' => 'الشعبة', 'capacity' => 'السعة']);
        if (isset($data['is_evaluative']) && $data['is_evaluative'] !== $offering->is_evaluative && in_array($offering->status, ['approved', 'locked'], true)) {
            throw new WorkflowException('لا يمكن تغيير شمول المادة بالامتحان التقويمي بعد مصادقة النتائج.');
        }
        AuditContext::as($request->user()->id, 'تعديل بيانات الشعبة', fn () => $offering->update($data));

        return back()->with('success', 'حُفظت بيانات الشعبة.');
    }

    /** Component weights may change only before any mark is entered. */
    public function components(Request $request, CourseOffering $offering): RedirectResponse
    {
        $this->authorizeOffering($request, $offering);
        $data = $request->validate([
            'components' => ['required', 'array', 'min:1'],
            'components.*.id' => ['nullable', 'integer'],
            'components.*.code' => ['required', 'string', 'max:20', 'regex:/^[a-z0-9_]+$/'],
            'components.*.name_ar' => ['required', 'string', 'max:100'],
            'components.*.weight' => ['required', 'numeric', 'min:0', 'max:100'],
            'components.*.max_mark' => ['required', 'numeric', 'min:1', 'max:1000'],
            'components.*.exam_round' => ['required', 'integer', 'min:1', 'max:3'],
        ], ['components.*.code.regex' => 'الرمز بأحرف إنكليزية صغيرة وأرقام فقط.']);

        if ($offering->enrollments()->whereHas('marks')->exists()) {
            throw new WorkflowException('لا يمكن تغيير المكونات بعد إدخال أي درجة.');
        }
        $rows = collect($data['components']);
        if ($rows->pluck('code')->duplicates()->isNotEmpty()) {
            throw new WorkflowException('رموز المكونات يجب ألا تتكرر.');
        }
        DB::transaction(function () use ($offering, $rows) {
            $offering->components()->delete();
            foreach ($rows->values() as $i => $c) {
                $offering->components()->create(collect($c)->only(['code', 'name_ar', 'weight', 'max_mark', 'exam_round'])->all() + ['sort' => $i]);
            }
            foreach ([1, 2, 3] as $round) {
                if (! $offering->components()->where('exam_round', $round)->exists()) {
                    continue;
                }
                $sum = $this->gradebook->componentsForRound($offering, $round)->sum('weight');
                if (abs($sum - 100) > 0.001) {
                    throw new WorkflowException("مجموع أوزان مكونات الدور {$round} يساوي {$sum} وليس 100. مكوّن الدور الثاني يجب أن يحمل رمز المكوّن الذي يحل محله مع _r2.");
                }
            }
        });

        return back()->with('success', 'حُفظت مكونات التقييم.');
    }

    public function searchStudents(Request $request, CourseOffering $offering)
    {
        $this->authorizeOffering($request, $offering);
        $q = trim((string) $request->query('q'));
        if (mb_strlen($q) < 2) {
            return [];
        }

        return Student::visibleTo($request->user())->where('status', 'active')
            ->where(fn ($w) => $w->where('name_ar', 'like', "%{$q}%")->orWhere('university_number', 'like', "{$q}%"))
            ->whereDoesntHave('enrollments', fn ($e) => $e->where('course_offering_id', $offering->id))
            ->with('currentProgram.program:id,name_ar')->limit(10)->get()
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name_ar, 'number' => $s->university_number, 'program' => $s->currentProgram?->program?->name_ar, 'stage' => $s->currentProgram?->stage]);
    }

    public function enroll(Request $request, CourseOffering $offering): RedirectResponse
    {
        $this->authorizeOffering($request, $offering);
        $data = $request->validate(['student_id' => ['required', 'exists:students,id'], 'carried' => ['boolean']]);
        if ($offering->current_round > 1 || $offering->status !== 'open') {
            throw new WorkflowException('التسجيل متاح فقط والشعبة مفتوحة في الدور الأول.');
        }
        $student = Student::visibleTo($request->user())->findOrFail($data['student_id']);
        $this->enrollments->enroll($student, $offering, $request->user(), $data['carried'] ?? false);

        return back()->with('success', 'سُجّل '.$student->name_ar.' في الشعبة.');
    }

    public function withdraw(Request $request, CourseOffering $offering, Enrollment $enrollment): RedirectResponse
    {
        $this->authorizeOffering($request, $offering);
        abort_unless($enrollment->course_offering_id === $offering->id, 404);
        if ($enrollment->results()->whereIn('status', ['approved', 'locked'])->exists()) {
            throw new WorkflowException('لا يمكن سحب طالب له نتيجة مصادق عليها أو مقفلة.');
        }
        $reason = $request->validate(['reason' => ['required', 'string', 'max:300']], [], ['reason' => 'السبب'])['reason'];
        AuditContext::as($request->user()->id, $reason, function () use ($enrollment) {
            $enrollment->results()->where('status', 'draft')->get()->each->delete();
            $enrollment->update(['status' => 'withdrawn']);
        });

        return back()->with('success', 'سُحب الطالب من الشعبة.');
    }

    private function authorizeOffering(Request $request, CourseOffering $offering): void
    {
        Gate::authorize('manage-offerings');
        abort_unless($request->user()->collegeScope() === null || Course::visibleTo($request->user())->whereKey($offering->course_id)->exists(), 403);
    }

    /** Curriculum courses of a stage that run in this term (S1 → term 1, S2 → term 2, Y → annual). */
    private function stageCourses(Program $program, int $stage, AcademicTerm $term): array
    {
        $cv = $program->curricula()->where('status', 'approved')->orderByDesc('version')->first();
        if (! $cv) {
            return [];
        }
        $termNo = match ($term->code) {
            'S1' => 1, 'S2' => 2, default => null
        };

        return CurriculumCourse::where('curriculum_version_id', $cv->id)->where('stage', $stage)
            ->when($termNo, fn ($q) => $q->where('term_no', $termNo), fn ($q) => $q->whereNull('term_no'))
            ->pluck('course_id')->all();
    }

    private function teachers(User $user)
    {
        return User::where('is_active', true)
            ->whereHas('roleAssignments', fn ($a) => $a->where('role', 'teacher')
                ->when($user->collegeScope() !== null, fn ($q) => $q->whereHas('department', fn ($d) => $d->visibleTo($user))))
            ->with(['roleAssignments' => fn ($a) => $a->where('role', 'teacher')->with('department:id,name_ar')])
            ->orderBy('name')->get()
            ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'department_ids' => $t->roleAssignments->pluck('department_id')->filter()->values(), 'department' => $t->roleAssignments->first()?->department?->name_ar]);
    }

    public function teacherOptions(Request $request)
    {
        Gate::authorize('manage-offerings');

        return $this->teachers($request->user());
    }
}
