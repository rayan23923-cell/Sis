<?php

namespace Tests\Feature\Sis;

use App\Models\AcademicPolicy;
use App\Models\AdministrativeOrder;
use App\Models\FinalResult;
use App\Services\GradebookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Policy-date edge cases found in the PR #2 review (DOC-P3-01 §4, R-3). */
class PolicyDateTest extends TestCase
{
    use RefreshDatabase, SisFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildUniversity();
    }

    private function newVersion(string $type, string $system, string $from, callable $params): AcademicPolicy
    {
        $cur = AcademicPolicy::where('type', $type)->when($system !== '*', fn ($q) => $q->whereIn('study_system', [$system, 'any']))->orderByDesc('version')->firstOrFail();
        $this->app->forgetScopedInstances(); // a new request or job

        return AcademicPolicy::create([
            'type' => $type, 'name_ar' => 'نسخة مراجعة', 'version' => $cur->version + 1, 'executor' => $cur->executor,
            'parameters' => $params($cur->parameters), 'study_system' => $cur->study_system, 'scope_type' => 'university',
            'effective_from' => $from, 'is_official' => false, 'status' => 'approved', 'approved_at' => now(),
        ]);
    }

    private function lockB(array $marks): void
    {
        $o = $this->offeringB;
        $c = $o->components()->where('exam_round', 1)->get()->keyBy('code');
        $rows = [];
        foreach ($o->enrollments()->orderBy('id')->get()->values() as $i => $e) {
            [$cw, $final] = $marks[$i] ?? [30, 40];
            $rows[$e->id] = [$c['coursework']->id => ['mark' => $cw], $c['final']->id => ['mark' => $final]];
        }
        $g = app(GradebookService::class);
        $g->saveMarks($o, $o->teacher, $rows);
        $g->compute($o->fresh(), $o->teacher);
        $g->submit($o->fresh(), $o->teacher);
        $g->approve($o->fresh(), $this->admin);
        $g->lock($o->fresh(), $this->admin);
    }

    /** Evaluative course: a later decision version (lower threshold) must not leak into a correction. */
    public function test_evaluative_correction_keeps_original_threshold(): void
    {
        $this->offeringB->update(['is_evaluative' => true]);
        foreach (AcademicPolicy::where('type', 'decision')->get() as $p) {
            \DB::table('academic_policies')->where('id', $p->id)->update(['parameters' => json_encode(['evaluative' => ['fail_below' => 20, 'out_of' => 60, 'treatment' => 5]] + $p->parameters)]);
        }
        $this->lockB([[30, 50], [40, 18], [40, 15], [30, 17]]);
        $old = $this->offeringB->enrollments()->orderBy('id')->get()[2]->currentResult;
        $this->assertFalse($old->passed);
        $this->assertSame(20.0, (float) $old->policy_snapshot['evaluative']['threshold']);

        $this->newVersion('decision', '*', '2025-09-01', fn ($p) => ['evaluative' => ['fail_below' => 10, 'out_of' => 60, 'treatment' => 5]] + $p);
        $final = $this->offeringB->components()->where('exam_round', 1)->where('code', 'final')->first();
        $order = AdministrativeOrder::create(['number' => '9', 'issued_on' => now()->toDateString(), 'type' => 'result_correction', 'title' => 't', 'issued_by_authority' => 'x']);
        $new = app(GradebookService::class)->correct($old, [$final->id => ['mark' => 16]], $order, $this->admin, 'مراجعة');

        $this->assertFalse($new->passed, '16 < 20 under the original rule');
        $this->assertSame($old->policy_snapshot['evaluative']['decision'], $new->policy_snapshot['evaluative']['decision']);
    }

    /**
     * Documents the current semantics, pending the university's decision (DOC-P3-01 R-3): the policy date is the end of
     * the academic year, so a version in force from mid-year applies to the whole year. Not an official rule.
     */
    public function test_mid_year_policy_applies_to_whole_year(): void
    {
        $this->newVersion('grade_scale', 'bologna', '2026-03-01', fn ($p) => ['bands' => collect($p['bands'])->map(fn ($b) => match ($b['code']) {
            'FX' => ['min' => 40] + $b, 'F' => ['max' => 39] + $b, default => $b
        })->all()] + $p);
        $o = $this->offeringA;
        $this->actingAs($this->teacherA)->post(route('offerings.marks', $o), ['rows' => $this->marksFor($o, [55, 20, 14, 17]), 'compute' => true]);
        $r = FinalResult::orderBy('enrollment_id')->skip(2)->first();
        $this->assertSame(2, $r->policy_snapshot['grade_scale']['version']);
        $this->assertSame('resit', $r->outcome);
    }
}
