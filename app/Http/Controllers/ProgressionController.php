<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\AdministrativeOrder;
use App\Models\Program;
use App\Models\ProgressionDecision;
use App\Services\ProgressionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProgressionController extends Controller
{
    public function __construct(private readonly ProgressionService $service) {}

    public function index(Request $request): Response
    {
        Gate::authorize('view-progression');
        $user = $request->user();
        $programs = Program::visibleTo($user)->with('department:id,name_ar,college_id')->orderBy('name_ar')->get();
        $year = AcademicYear::find($request->integer('year')) ?? AcademicYear::where('is_current', true)->first() ?? AcademicYear::latest('starts_on')->first();
        $program = $programs->firstWhere('id', $request->integer('program')) ?? $programs->first();
        $stage = max(1, $request->integer('stage', 1));

        $rows = $program && $year ? $this->service->preview($program, $stage, $year) : collect();

        return Inertia::render('Progression/Index', [
            'years' => AcademicYear::orderByDesc('starts_on')->get(['id', 'name']),
            'programs' => $programs->map(fn ($p) => ['id' => $p->id, 'name' => $p->name_ar, 'department' => $p->department->name_ar, 'years' => $p->duration_years, 'system' => $p->study_system]),
            'selected' => ['year' => $year?->id, 'program' => $program?->id, 'stage' => $stage],
            'rows' => $rows,
            'decisions' => ProgressionDecision::DECISIONS,
            'canDecide' => $program ? Gate::allows('decide-progression', $program) : false,
            'orders' => AdministrativeOrder::where('type', 'year_results')->latest('issued_on')->limit(30)->get()->map(fn ($o) => ['id' => $o->id, 'label' => $o->reference().' — '.$o->title]),
            'orderTypes' => AdministrativeOrder::TYPES,
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        [$program, $stage, $year] = $this->target($request);
        $n = $this->service->save($program, $stage, $year, $request->user());

        return back()->with('success', "حُفظت {$n} قرارات كمسودة. راجعها ثم طبّقها بأمر إداري.");
    }

    public function apply(Request $request): RedirectResponse
    {
        [$program, $stage, $year] = $this->target($request);
        $order = AdministrativeOrder::findOrFail($request->validate(['order_id' => ['required', 'exists:administrative_orders,id']], [], ['order_id' => 'الأمر الإداري'])['order_id']);
        $n = $this->service->apply($program, $stage, $year, $order, $request->user());

        return back()->with('success', "طُبّقت {$n} قرارات: نُقل المرفّعون للمرحلة التالية وسُجّل المتخرجون.");
    }

    private function target(Request $request): array
    {
        $data = $request->validate(['program' => ['required', 'exists:programs,id'], 'stage' => ['required', 'integer', 'min:1'], 'year' => ['required', 'exists:academic_years,id']]);
        $program = Program::visibleTo($request->user())->findOrFail($data['program']);
        Gate::authorize('decide-progression', $program);

        return [$program, (int) $data['stage'], AcademicYear::findOrFail($data['year'])];
    }
}
