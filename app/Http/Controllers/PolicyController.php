<?php

namespace App\Http\Controllers;

use App\AcademicRules\Executors\Attendance;
use App\AcademicRules\Executors\Decision;
use App\AcademicRules\Executors\ExamRounds;
use App\AcademicRules\Executors\Gpa;
use App\AcademicRules\Executors\GradeScale;
use App\AcademicRules\Executors\Progression;
use App\AcademicRules\Executors\Rounding;
use App\AcademicRules\Executors\WeightedTotal;
use App\AcademicRules\Policy;
use App\AcademicRules\RulesException;
use App\Models\AcademicPolicy;
use App\Support\AuditContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PolicyController extends Controller
{
    private const EXECUTORS = [
        'grade_scale@1' => GradeScale::class,
        'rounding@1' => Rounding::class,
        'total@1' => WeightedTotal::class,
        'rounds@1' => ExamRounds::class,
        'gpa@1' => Gpa::class,
        'progression@1' => Progression::class,
        'attendance@1' => Attendance::class,
        'decision@1' => Decision::class,
    ];

    public const SYSTEMS = ['bologna' => 'بولونيا', 'semester' => 'فصلي', 'annual' => 'سنوي', 'any' => 'جميع الأنظمة'];

    public function index(Request $request): Response
    {
        $policies = AcademicPolicy::with('creator:id,name')->orderBy('study_system')->orderBy('type')->orderByDesc('version')->get();

        return Inertia::render('Policies/Index', [
            'policies' => $policies->map(fn (AcademicPolicy $p) => $this->present($p)),
            'types' => AcademicPolicy::TYPES,
            'systems' => self::SYSTEMS,
            'canManage' => Gate::allows('manage-policies'),
        ]);
    }

    public function store(Request $request, AcademicPolicy $policy): RedirectResponse
    {
        Gate::authorize('manage-policies');
        $data = $request->validate([
            'parameters' => ['required', 'json'],
            'effective_from' => ['required', 'date'],
            'source_reference' => ['nullable', 'string', 'max:255'],
            'approving_authority' => ['nullable', 'string', 'max:255'],
            'is_official' => ['boolean'],
            'changelog' => ['required', 'string', 'max:2000'],
        ], [], ['parameters' => 'المعاملات', 'effective_from' => 'تاريخ النفاذ', 'changelog' => 'وصف التغيير']);

        if (($data['is_official'] ?? false) && empty($data['source_reference'])) {
            throw ValidationException::withMessages(['source_reference' => 'السياسة الرسمية تحتاج رقم الكتاب الرسمي وتاريخه.']);
        }
        $params = json_decode($data['parameters'], true);
        $this->validateExecutor($policy->executor, $policy->type, $params);

        $next = AcademicPolicy::where('type', $policy->type)->where('study_system', $policy->study_system)
            ->where('scope_type', $policy->scope_type)->where('scope_id', $policy->scope_id)->max('version') + 1;

        AcademicPolicy::create([
            'type' => $policy->type, 'name_ar' => $policy->name_ar, 'version' => $next, 'executor' => $policy->executor,
            'parameters' => $params, 'study_system' => $policy->study_system, 'scope_type' => $policy->scope_type,
            'scope_id' => $policy->scope_id, 'effective_from' => $data['effective_from'],
            'source_reference' => $data['source_reference'] ?? null, 'approving_authority' => $data['approving_authority'] ?? null,
            'is_official' => $data['is_official'] ?? false, 'status' => 'draft', 'changelog' => $data['changelog'],
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', "أُنشئ الإصدار {$next} كمسودة. لن يُطبق قبل اعتماده.");
    }

    public function approve(Request $request, AcademicPolicy $policy): RedirectResponse
    {
        Gate::authorize('manage-policies');
        abort_unless($policy->status === 'draft', 422);
        $this->validateExecutor($policy->executor, $policy->type, $policy->parameters);

        DB::transaction(fn () => AuditContext::as($request->user()->id, 'اعتماد سياسة أكاديمية', function () use ($policy, $request) {
            // The previous approved version stops the day before this one takes effect; history stays.
            AcademicPolicy::where('type', $policy->type)->where('study_system', $policy->study_system)
                ->where('scope_type', $policy->scope_type)->where('scope_id', $policy->scope_id)
                ->where('status', 'approved')->whereNull('effective_to')
                ->whereDate('effective_from', '<', $policy->effective_from)
                ->each(fn ($old) => $old->update(['effective_to' => $policy->effective_from->copy()->subDay()]));
            $policy->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now()]);
        }));

        return back()->with('success', 'اعتُمد الإصدار '.$policy->version.'. يُطبق على الاحتسابات من '.$policy->effective_from->format('Y/m/d').'.');
    }

    public function destroy(AcademicPolicy $policy): RedirectResponse
    {
        Gate::authorize('manage-policies');
        abort_unless($policy->status === 'draft', 422);
        $policy->delete();

        return back()->with('success', 'حُذفت المسودة.');
    }

    private function validateExecutor(string $executor, string $type, array $params): void
    {
        $class = self::EXECUTORS[$executor] ?? null;
        try {
            if (! $class) {
                throw new RulesException("Unknown executor {$executor}");
            }
            $instance = new $class(new Policy(0, $type, 0, $executor, $params, false));
            if ($instance instanceof GradeScale) {
                $instance->bands();
            }
        } catch (RulesException|\TypeError $e) {
            throw ValidationException::withMessages(['parameters' => 'المعاملات غير صالحة لهذا النوع: '.$e->getMessage()]);
        }
    }

    private function present(AcademicPolicy $p): array
    {
        return [
            'id' => $p->id, 'type' => $p->type, 'type_label' => AcademicPolicy::TYPES[$p->type] ?? $p->type,
            'name' => $p->name_ar, 'version' => $p->version, 'executor' => $p->executor, 'parameters' => $p->parameters,
            'system' => $p->study_system, 'scope' => $p->scope_type, 'from' => $p->effective_from?->toDateString(),
            'to' => $p->effective_to?->toDateString(), 'reference' => $p->source_reference, 'authority' => $p->approving_authority,
            'official' => $p->is_official, 'status' => $p->status, 'changelog' => $p->changelog, 'by' => $p->creator?->name,
            'approved_at' => $p->approved_at?->toDateString(),
        ];
    }
}
