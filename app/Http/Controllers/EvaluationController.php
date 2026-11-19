<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
use App\Models\College;
use App\Models\CourseOffering;
use App\Models\EvaluationForm;
use App\Models\EvaluationRound;
use App\Models\Student;
use App\Services\EvaluationService;
use App\Services\WorkflowException;
use App\Support\AuditContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Course and teacher evaluation by students, run by the quality assurance unit. */
class EvaluationController extends Controller
{
    public function __construct(private readonly EvaluationService $evaluations) {}

    /** Every staff member sees their own results here; quality, deans and heads also see the rounds they cover. */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $canView = Gate::allows('view-evaluations');
        $canManage = Gate::allows('manage-evaluations');
        $colleges = $user->collegeScope();

        $rounds = $canView ? EvaluationRound::with(['term:id,name_ar', 'college:id,name_ar', 'form:id,name,is_official,questions'])
            ->when($colleges !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('college_id')->orWhereIn('college_id', $colleges)))
            ->latest('opens_on')->get() : collect();
        $selected = $canView ? ($rounds->firstWhere('id', $request->integer('round')) ?? $rounds->first()) : null;

        return Inertia::render('Evaluations/Index', [
            'rounds' => $rounds->map(fn (EvaluationRound $r) => [
                'id' => $r->id, 'name' => $r->name, 'term' => $r->term?->name_ar, 'college' => $r->college?->name_ar ?? 'كل الكليات',
                'opens_on' => $r->opens_on->toDateString(), 'closes_on' => $r->closes_on->toDateString(), 'state' => $r->state(),
                'form' => $r->form->name, 'official' => $r->form->is_official, 'min' => $r->min_responses,
            ]),
            'selected' => $selected?->id,
            'results' => $selected ? $this->evaluations->results($selected, $user) : null,
            'mine' => $this->evaluations->forTeacher($user),
            'forms' => $canManage ? EvaluationForm::orderByDesc('id')->get()->map(fn (EvaluationForm $f) => [
                'id' => $f->id, 'name' => $f->name, 'questions' => $f->questions, 'is_official' => $f->is_official, 'source_reference' => $f->source_reference,
                'is_active' => $f->is_active, 'used' => EvaluationRound::where('evaluation_form_id', $f->id)->exists(),
            ]) : [],
            'terms' => $canManage ? AcademicTerm::with('year:id,name')->orderByDesc('starts_on')->limit(12)->get()->map(fn ($t) => ['id' => $t->id, 'name' => $t->name_ar.' — '.$t->year?->name]) : [],
            'colleges' => $canManage ? College::visibleTo($user)->orderBy('name_ar')->get(['id', 'name_ar']) : [],
            'perm' => ['view' => $canView, 'manage' => $canManage, 'allColleges' => $colleges === null],
        ]);
    }

    public function storeForm(Request $request): RedirectResponse
    {
        Gate::authorize('manage-evaluations');
        $data = $this->validateForm($request);
        AuditContext::as($request->user()->id, 'استمارة تقييم جديدة', fn () => EvaluationForm::create($data + ['created_by' => $request->user()->id]));

        return back()->with('success', 'حُفظت الاستمارة.');
    }

    public function updateForm(Request $request, EvaluationForm $form): RedirectResponse
    {
        Gate::authorize('manage-evaluations');
        $data = $this->validateForm($request);
        if (EvaluationRound::where('evaluation_form_id', $form->id)->exists() && $data['questions'] !== $form->questions) {
            throw new WorkflowException('استُعملت هذه الاستمارة في فترة تقييم، فلا تُغيَّر أسئلتها. أنشئ استمارة جديدة.');
        }
        AuditContext::as($request->user()->id, 'تعديل استمارة تقييم', fn () => $form->update($data));

        return back()->with('success', 'حُفظت الاستمارة.');
    }

    public function storeRound(Request $request): RedirectResponse
    {
        Gate::authorize('manage-evaluations');
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'evaluation_form_id' => ['required', Rule::exists('evaluation_forms', 'id')->where('is_active', true)],
            'academic_term_id' => ['required', 'exists:academic_terms,id'],
            'college_id' => ['nullable', 'exists:colleges,id'],
            'opens_on' => ['required', 'date'],
            'closes_on' => ['required', 'date', 'after_or_equal:opens_on'],
            'min_responses' => ['required', 'integer', 'min:3', 'max:50'],
        ]);
        $scope = $user->collegeScope();
        if ($scope !== null && (! $data['college_id'] || ! in_array((int) $data['college_id'], $scope, true))) {
            throw new WorkflowException('اختر كليتك.');
        }
        $this->evaluations->createRound($data, $user);

        return back()->with('success', 'فُتحت فترة التقييم.');
    }

    public function updateRound(Request $request, EvaluationRound $round): RedirectResponse
    {
        Gate::authorize('manage-evaluations');
        $scope = $request->user()->collegeScope();
        abort_unless($scope === null || in_array($round->college_id, $scope, true), 403);
        $data = $request->validate(['closes_on' => ['required', 'date', 'after_or_equal:'.$round->opens_on->toDateString()]]);
        AuditContext::as($request->user()->id, 'تعديل موعد غلق التقييم', fn () => $round->update($data));

        return back()->with('success', 'حُدّث موعد الغلق.');
    }

    // ---- the student ----

    public function submit(Request $request): RedirectResponse
    {
        $student = Student::where('user_id', $request->user()->id)->firstOrFail();
        $data = $request->validate([
            'round' => ['required', 'exists:evaluation_rounds,id'],
            'offering' => ['required', 'exists:course_offerings,id'],
            'answers' => ['required', 'array'],
            'answers.*' => ['nullable', 'max:1000'],
        ]);
        $round = EvaluationRound::with('form')->findOrFail($data['round']);
        $this->evaluations->submit($student, $round, CourseOffering::findOrFail($data['offering']), $data['answers']);

        return back()->with('success', 'شكراً، وصل تقييمك. لا يظهر اسمك لأحد.');
    }

    /** @return array{name: string, questions: list<array{text: string, kind: string}>, is_official: bool, source_reference: ?string, is_active: bool} */
    private function validateForm(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'questions' => ['required', 'array', 'min:1', 'max:40'],
            'questions.*.text' => ['required', 'string', 'max:300'],
            'questions.*.kind' => ['required', Rule::in(['scale', 'text'])],
            'is_official' => ['boolean'],
            'source_reference' => ['nullable', 'string', 'max:250', 'required_if:is_official,true'],
            'is_active' => ['boolean'],
        ]);
        $data['questions'] = array_values(array_map(fn ($q) => ['text' => trim($q['text']), 'kind' => $q['kind']], $data['questions']));

        return $data + ['is_official' => false, 'is_active' => true];
    }
}
