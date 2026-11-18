<?php

namespace App\Http\Controllers;

use App\AcademicRules\CourseResultCalculator;
use App\AcademicRules\Data\ComponentMark;
use App\AcademicRules\PolicySet;
use App\Models\AcademicTerm;
use App\Models\AdministrativeOrder;
use App\Models\College;
use App\Models\CourseOffering;
use App\Models\FinalResult;
use App\Services\EnrollmentService;
use App\Services\GradebookService;
use App\Services\PolicyResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OfferingController extends Controller
{
    public function __construct(private readonly GradebookService $gradebook) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $filters = $request->only(['q', 'term', 'college', 'status', 'mine']);
        $currentTerms = AcademicTerm::whereHas('year', fn ($y) => $y->where('is_current', true))->pluck('id');

        $offerings = CourseOffering::query()
            ->where(fn ($q) => $q->visibleTo($user)->orWhere('teacher_id', $user->id))
            ->with(['course:id,code,name_ar,department_id,credit_hours,ects', 'course.department:id,name_ar,college_id', 'term:id,name_ar,academic_year_id', 'term.year:id,name', 'teacher:id,name', 'program:id,name_ar'])
            ->withCount(['enrollments' => fn ($q) => $q->where('status', '!=', 'withdrawn')])
            ->when($filters['term'] ?? null, fn ($q, $t) => $q->where('academic_term_id', $t), fn ($q) => $q->whereIn('academic_term_id', $currentTerms))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['college'] ?? null, fn ($q, $c) => $q->whereHas('course.department', fn ($d) => $d->where('college_id', $c)))
            ->when($filters['mine'] ?? null, fn ($q) => $q->where('teacher_id', $user->id))
            ->when($filters['q'] ?? null, fn ($q, $s) => $q->whereHas('course', fn ($c) => $c->where('name_ar', 'like', "%{$s}%")->orWhere('code', 'like', "{$s}%")))
            ->orderBy('academic_term_id')->orderBy('id')
            ->paginate(30)->withQueryString()
            ->through(fn (CourseOffering $o) => [
                'id' => $o->id,
                'code' => $o->course->code,
                'name' => $o->course->name_ar,
                'department' => $o->course->department->name_ar,
                'program' => $o->program?->name_ar,
                'stage' => $o->stage,
                'section' => $o->section,
                'term' => $o->term->label(),
                'teacher' => $o->teacher?->name,
                'students' => $o->enrollments_count,
                'capacity' => $o->capacity,
                'status' => $o->status,
                'round' => $o->current_round,
                'mine' => $o->teacher_id === $user->id,
            ]);

        return Inertia::render('Offerings/Index', [
            'offerings' => $offerings,
            'filters' => $filters,
            'terms' => AcademicTerm::with('year:id,name')->orderByDesc('id')->get()->map(fn ($t) => ['id' => $t->id, 'name' => $t->label(), 'current' => $currentTerms->contains($t->id)]),
            'colleges' => College::visibleTo($user)->orderBy('name_ar')->get(['id', 'name_ar']),
            'statuses' => CourseOffering::STATUSES,
        ]);
    }

    public function show(Request $request, CourseOffering $offering): Response
    {
        Gate::authorize('view-offering', $offering);
        $user = $request->user();
        $offering->load(['course.department.college', 'term.year', 'teacher:id,name', 'program.department']);
        $round = (int) $request->integer('round', $offering->current_round);
        $round = max(1, min($round, $offering->current_round));

        $components = $this->gradebook->componentsForRound($offering, $round);
        $editable = $this->gradebook->editableComponents($offering, $round)->pluck('id');
        $credits = (float) $offering->course->credit_hours;
        $rows = $this->gradebook->eligible($offering, $round)->map(function ($e) use ($round, $components, $credits) {
            $marks = $e->marks->keyBy('assessment_component_id');
            $result = $e->results->first(fn ($r) => $r->exam_round === $round && $r->is_current);
            $history = $e->results->where('exam_round', $round)->where('is_current', false)->count();

            return [
                'enrollment_id' => $e->id,
                'student_id' => $e->student_id,
                'number' => $e->student->university_number,
                'name' => $e->student->name_ar,
                'carried' => $e->is_carried,
                'attempt' => $e->attempt_no,
                'marks' => $components->mapWithKeys(fn ($c) => [$c->id => [
                    'mark' => $marks->get($c->id)?->mark,
                    'absent' => (bool) $marks->get($c->id)?->is_absent,
                ]]),
                'result' => $result ? [
                    'id' => $result->id,
                    'total' => $result->total_mark,
                    'rounded' => $result->rounded_mark,
                    'grade' => $result->grade_name_ar,
                    'code' => $result->grade_code,
                    'outcome' => $result->outcome,
                    'status' => $result->status,
                    'version' => $result->version,
                    'corrected' => $result->administrative_order_id !== null,
                    'order' => $result->order?->reference(),
                    'stale' => $result->status === 'draft' && $result->input_hash !== $this->hashFor($e, $components, $credits),
                ] : null,
                'corrections' => $history,
            ];
        });

        $isTeacher = Gate::allows('enter-marks', $offering);
        $isCommittee = Gate::allows('approve-results', $offering);
        $program = $offering->program ?? $offering->enrollments()->first()?->studentProgram?->program;
        $policies = $program ? app(PolicyResolver::class)->forProgram($program) : new PolicySet;

        return Inertia::render('Offerings/Show', [
            'offering' => [
                'id' => $offering->id,
                'code' => $offering->course->code,
                'name' => $offering->course->name_ar,
                'name_en' => $offering->course->name_en,
                'department' => $offering->course->department->name_ar,
                'college' => $offering->course->department->college->name_ar,
                'program' => $offering->program?->name_ar,
                'system' => $offering->program?->study_system,
                'stage' => $offering->stage,
                'section' => $offering->section,
                'term' => $offering->term->label(),
                'teacher' => $offering->teacher?->name,
                'credits' => (float) $offering->course->credit_hours,
                'ects' => $offering->course->ects ? (float) $offering->course->ects : null,
                'capacity' => $offering->capacity,
                'status' => $offering->status,
                'current_round' => $offering->current_round,
            ],
            'round' => $round,
            'components' => $components->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name_ar, 'weight' => (float) $c->weight, 'max' => (float) $c->max_mark, 'round' => $c->exam_round, 'editable' => $editable->contains($c->id)])->values(),
            'rows' => $rows,
            'gradeScale' => $policies->has('grade_scale') ? collect($policies->get('grade_scale')->parameters['bands'] ?? [])->map(fn ($b) => ['code' => $b['code'], 'name' => $b['name_ar'], 'min' => $b['min'], 'max' => $b['max'], 'outcome' => $b['outcome']]) : [],
            'official' => $policies->allOfficial(),
            'perm' => [
                'edit' => $isTeacher && $offering->status === 'open' && $round === $offering->current_round,
                'submit' => $isTeacher && $offering->status === 'open',
                'review' => $isCommittee && $offering->status === 'submitted',
                'lock' => $isCommittee && $offering->status === 'approved',
                'nextRound' => $isCommittee && $this->gradebook->canOpenNextRound($offering),
                'correct' => Gate::allows('correct-results', $offering),
                'enroll' => Gate::allows('manage-offerings') && $offering->status === 'open',
            ],
            'orders' => Gate::allows('correct-results', $offering)
                ? AdministrativeOrder::where('type', 'result_correction')->latest('issued_on')->limit(30)->get()->map(fn ($o) => ['id' => $o->id, 'label' => $o->reference().' — '.$o->title])
                : [],
            'statuses' => CourseOffering::STATUSES,
        ]);
    }

    public function saveMarks(Request $request, CourseOffering $offering): RedirectResponse
    {
        Gate::authorize('enter-marks', $offering);
        $data = $request->validate(['rows' => ['required', 'array'], 'compute' => ['boolean']]);
        $saved = $this->gradebook->saveMarks($offering, $request->user(), $data['rows']);
        if ($request->boolean('compute')) {
            return $this->computeAndReport($offering, $request, "حُفظت {$saved} درجة. ");
        }

        return back()->with('success', $saved ? "حُفظت {$saved} درجة." : 'لا توجد تغييرات للحفظ.');
    }

    public function compute(Request $request, CourseOffering $offering): RedirectResponse
    {
        Gate::authorize('enter-marks', $offering);

        return $this->computeAndReport($offering, $request);
    }

    public function submit(Request $request, CourseOffering $offering): RedirectResponse
    {
        Gate::authorize('enter-marks', $offering);
        $this->gradebook->submit($offering, $request->user());

        return back()->with('success', 'رُفعت النتائج إلى اللجنة الامتحانية. لا يمكن تعديلها إلا إذا أعادتها اللجنة.');
    }

    public function decide(Request $request, CourseOffering $offering): RedirectResponse
    {
        Gate::authorize('approve-results', $offering);
        $data = $request->validate([
            'action' => ['required', Rule::in(['approve', 'return', 'lock', 'next_round'])],
            'reason' => ['required_if:action,return', 'nullable', 'string', 'max:500'],
        ], [], ['reason' => 'سبب الإعادة']);

        $message = match ($data['action']) {
            'approve' => $this->gradebook->approve($offering, $request->user()) ?? 'تمت المصادقة على النتائج. الخطوة التالية: القفل.',
            'return' => $this->gradebook->returnToTeacher($offering, $request->user(), $data['reason']) ?? 'أُعيدت النتائج إلى التدريسي للتعديل.',
            'lock' => $this->gradebook->lock($offering, $request->user()) ?? 'قُفلت النتائج. أي تعديل بعد الآن يحتاج أمراً إدارياً.',
            'next_round' => $this->gradebook->openNextRound($offering, $request->user()) ?? 'فُتح الدور الثاني لطلبة الإعادة.',
        };

        return back()->with('success', $message);
    }

    public function enrollCohort(Request $request, CourseOffering $offering, EnrollmentService $service): RedirectResponse
    {
        Gate::authorize('manage-offerings');
        $r = $service->enrollCohort($offering, $request->user());
        $this->gradebook->ensureComponents($offering);

        return back()->with('success', "سُجّل {$r['enrolled']} طالب.".($r['skipped'] ? ' تخطي '.count($r['skipped']).' لعدم استيفاء الشروط.' : ''));
    }

    public function correct(Request $request, CourseOffering $offering, FinalResult $result): RedirectResponse
    {
        Gate::authorize('correct-results', $offering);
        abort_unless($result->enrollment->course_offering_id === $offering->id, 404);
        $data = $request->validate([
            'order_id' => ['required', 'exists:administrative_orders,id'],
            'reason' => ['required', 'string', 'max:500'],
            'marks' => ['required', 'array'],
        ], [], ['order_id' => 'الأمر الإداري', 'reason' => 'سبب التصحيح']);

        $new = $this->gradebook->correct($result, $data['marks'], AdministrativeOrder::findOrFail($data['order_id']), $request->user(), $data['reason']);

        return back()->with('success', 'سُجّل التصحيح كإصدار '.$new->version.' مع الاحتفاظ بالنتيجة السابقة في السجل.');
    }

    private function computeAndReport(CourseOffering $offering, Request $request, string $prefix = ''): RedirectResponse
    {
        $r = $this->gradebook->compute($offering, $request->user());
        $msg = $prefix."احتُسبت نتائج {$r['computed']} طالب.";
        if ($r['problems']) {
            return back()->with('error', $msg.' لم تُحتسب '.count($r['problems']).' نتيجة لنقص الدرجات.')->with('problems', $r['problems']);
        }

        return back()->with('success', $msg);
    }

    private function hashFor($enrollment, $components, float $credits): string
    {
        $marks = $enrollment->marks->keyBy('assessment_component_id');

        return CourseResultCalculator::hash($components->map(fn ($c) => new ComponentMark(
            $c->code, (float) $c->weight, (float) $c->max_mark, $marks->get($c->id)?->mark, (bool) $marks->get($c->id)?->is_absent,
        ))->all(), $credits);
    }
}
