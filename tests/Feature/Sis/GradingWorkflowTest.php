<?php

namespace Tests\Feature\Sis;

use App\AcademicRules\PolicySet;
use App\Models\AcademicPolicy;
use App\Models\AdministrativeOrder;
use App\Models\AssessmentMark;
use App\Models\AuditLog;
use App\Models\FinalResult;
use App\Services\GradebookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class GradingWorkflowTest extends TestCase
{
    use RefreshDatabase, SisFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildUniversity();
    }

    private function runToLocked(array $finals): void
    {
        $o = $this->offeringA;
        $this->actingAs($this->teacherA)->post(route('offerings.marks', $o), ['rows' => $this->marksFor($o, $finals), 'compute' => true])->assertSessionHasNoErrors();
        $this->actingAs($this->teacherA)->post(route('offerings.submit', $o))->assertSessionHas('success');
        $this->actingAs($this->committeeA)->post(route('offerings.decide', $o), ['action' => 'approve'])->assertSessionHas('success');
        $this->actingAs($this->committeeA)->post(route('offerings.decide', $o), ['action' => 'lock'])->assertSessionHas('success');
    }

    public function test_marks_flow_to_locked_results_with_policy_snapshot(): void
    {
        // coursework 30/40 → 30; finals out of 60: 55 → 85, 20 → 50, 14 → 44 (F), 17 → 47 (FX, resit)
        $this->runToLocked([55, 20, 14, 17]);

        $results = FinalResult::orderBy('enrollment_id')->get();
        $this->assertSame([85.0, 50.0, 44.0, 47.0], $results->pluck('rounded_mark')->all());
        $this->assertSame(['pass', 'pass', 'fail', 'resit'], $results->pluck('outcome')->all());
        $this->assertTrue($results->every(fn ($r) => $r->status === 'locked'));
        $this->assertArrayHasKey('grade_scale', $results[0]->policy_snapshot);
        $this->assertFalse($results[0]->isOfficial(), 'placeholder policies must mark results unofficial');
        $this->assertSame('locked', $this->offeringA->fresh()->status);
    }

    public function test_teacher_cannot_change_marks_after_submitting(): void
    {
        $o = $this->offeringA;
        $this->actingAs($this->teacherA)->post(route('offerings.marks', $o), ['rows' => $this->marksFor($o, [50, 50, 50, 50]), 'compute' => true]);
        $this->actingAs($this->teacherA)->post(route('offerings.submit', $o));
        $this->actingAs($this->teacherA)->post(route('offerings.marks', $o), ['rows' => $this->marksFor($o, [10, 10, 10, 10])])
            ->assertSessionHas('error');
        $this->assertSame(0, FinalResult::where('rounded_mark', '<', 50)->count());
    }

    /** F-G3-1: results that no longer match the marks (or the year's policies) cannot be submitted. */
    public function test_results_computed_before_a_mark_changed_cannot_be_submitted(): void
    {
        $o = $this->offeringA;
        $this->actingAs($this->teacherA)->post(route('offerings.marks', $o), ['rows' => $this->marksFor($o, [55, 20, 14, 17]), 'compute' => true]);
        // a mark changes after the computation, and the teacher submits without computing again
        $this->actingAs($this->teacherA)->post(route('offerings.marks', $o), ['rows' => $this->marksFor($o, [55, 20, 30, 17])]);

        $this->actingAs($this->teacherA)->post(route('offerings.submit', $o))->assertSessionHas('error');
        $this->assertSame('open', $o->fresh()->status);
        $this->assertSame(44.0, FinalResult::orderBy('enrollment_id')->skip(2)->first()->rounded_mark, 'the stale draft is still 44, not 60');

        // computing again brings the results in line, then submitting works
        $this->actingAs($this->teacherA)->post(route('offerings.marks', $o), ['rows' => $this->marksFor($o, [55, 20, 30, 17]), 'compute' => true]);
        $this->actingAs($this->teacherA)->post(route('offerings.submit', $o))->assertSessionHas('success');
        $this->assertSame(60.0, FinalResult::orderBy('enrollment_id')->skip(2)->first()->rounded_mark);
    }

    public function test_results_computed_before_a_policy_of_their_year_was_approved_cannot_be_submitted(): void
    {
        $o = $this->offeringA;
        $this->actingAs($this->teacherA)->post(route('offerings.marks', $o), ['rows' => $this->marksFor($o, [55, 20, 14, 17]), 'compute' => true]);
        $this->widerResitScale('2025-09-01'); // approved for the same year after the drafts were computed

        $this->actingAs($this->teacherA)->post(route('offerings.submit', $o))->assertSessionHas('error');
        $this->assertSame('open', $o->fresh()->status);
    }

    public function test_several_changed_marks_block_submit_and_are_counted(): void
    {
        $o = $this->offeringA;
        $this->actingAs($this->teacherA)->post(route('offerings.marks', $o), ['rows' => $this->marksFor($o, [55, 20, 14, 17]), 'compute' => true]);
        $this->actingAs($this->teacherA)->post(route('offerings.marks', $o), ['rows' => $this->marksFor($o, [56, 20, 30, 18])]);

        $this->actingAs($this->teacherA)->post(route('offerings.submit', $o))->assertSessionHas('error');
        $this->assertStringContainsString('3', session('error'), 'three of the four students changed');
        $this->assertSame('open', $o->fresh()->status);

        $this->actingAs($this->teacherA)->post(route('offerings.compute', $o));
        $this->actingAs($this->teacherA)->post(route('offerings.submit', $o))->assertSessionHas('success');
        $this->assertSame([86.0, 50.0, 60.0, 48.0], FinalResult::orderBy('enrollment_id')->pluck('rounded_mark')->all());
    }

    public function test_a_cleared_mark_blocks_submit(): void
    {
        $o = $this->offeringA;
        $this->actingAs($this->teacherA)->post(route('offerings.marks', $o), ['rows' => $this->marksFor($o, [55, 20, 14, 17]), 'compute' => true]);
        $final = $o->components()->where('code', 'final')->first();
        $first = $o->enrollments()->orderBy('id')->first();
        $this->actingAs($this->teacherA)->post(route('offerings.marks', $o), ['rows' => [$first->id => [$final->id => ['mark' => '']]]]);

        $this->actingAs($this->teacherA)->post(route('offerings.submit', $o))->assertSessionHas('error');
        $this->assertSame('open', $o->fresh()->status);
    }

    public function test_a_deleted_mark_blocks_submit(): void
    {
        $o = $this->offeringA;
        $this->actingAs($this->teacherA)->post(route('offerings.marks', $o), ['rows' => $this->marksFor($o, [55, 20, 14, 17]), 'compute' => true]);
        AssessmentMark::where('enrollment_id', $o->enrollments()->orderBy('id')->first()->id)->first()->delete(); // e.g. removed in the database

        $this->actingAs($this->teacherA)->post(route('offerings.submit', $o))->assertSessionHas('error');
        $this->assertSame('open', $o->fresh()->status);
    }

    public function test_a_refused_submit_leaves_nothing_behind(): void
    {
        $o = $this->offeringA;
        $this->actingAs($this->teacherA)->post(route('offerings.marks', $o), ['rows' => $this->marksFor($o, [55, 20, 14, 17]), 'compute' => true]);
        $this->actingAs($this->teacherA)->post(route('offerings.marks', $o), ['rows' => $this->marksFor($o, [55, 20, 30, 17])]);
        $snapshot = fn () => FinalResult::orderBy('id')->get()->map(fn ($r) => [$r->id, $r->status, $r->rounded_mark, $r->input_hash, (string) $r->updated_at])->all();
        $drafts = $snapshot();
        $audits = AuditLog::count();

        $this->actingAs($this->teacherA)->post(route('offerings.submit', $o))->assertSessionHas('error');

        $this->assertSame('open', $o->fresh()->status);
        $this->assertSame($drafts, $snapshot());
        $this->assertSame($audits, AuditLog::count(), 'no audit row for a submission that did not happen');
    }

    public function test_results_that_went_stale_after_submission_cannot_be_approved(): void
    {
        $o = $this->offeringA;
        $this->actingAs($this->teacherA)->post(route('offerings.marks', $o), ['rows' => $this->marksFor($o, [55, 20, 14, 17]), 'compute' => true]);
        $this->actingAs($this->teacherA)->post(route('offerings.submit', $o))->assertSessionHas('success');
        $this->widerResitScale('2025-09-01'); // approved while the committee is reviewing

        $this->actingAs($this->committeeA)->post(route('offerings.decide', $o), ['action' => 'approve'])->assertSessionHas('error');
        $this->assertSame('submitted', $o->fresh()->status);
        $this->assertSame(0, FinalResult::where('status', 'approved')->count());

        // the way out: return, compute, submit, approve
        $this->actingAs($this->committeeA)->post(route('offerings.decide', $o), ['action' => 'return', 'reason' => 'سياسة جديدة'])->assertSessionHas('success');
        $this->actingAs($this->teacherA)->post(route('offerings.compute', $o));
        $this->actingAs($this->teacherA)->post(route('offerings.submit', $o))->assertSessionHas('success');
        $this->actingAs($this->committeeA)->post(route('offerings.decide', $o), ['action' => 'approve'])->assertSessionHas('success');
        $this->assertSame('resit', FinalResult::orderBy('enrollment_id')->skip(2)->first()->outcome, '44 is FX under the new scale');
    }

    public function test_an_earlier_round_cannot_be_corrected_while_a_later_round_is_in_progress(): void
    {
        $this->runToLocked([55, 20, 14, 17]);
        $o = $this->offeringA->fresh();
        $this->actingAs($this->committeeA)->post(route('offerings.decide', $o), ['action' => 'next_round'])->assertSessionHas('success');
        $o->refresh();
        $resit = app(GradebookService::class)->eligible($o, 2)[0];
        $r2 = $o->components()->where('code', 'final_r2')->first();
        $this->actingAs($this->teacherA)->post(route('offerings.marks', $o), ['rows' => [$resit->id => [$r2->id => ['mark' => 15]]], 'compute' => true]);

        // correcting round 1 to a pass would leave the round-2 draft (a fail) to be approved and locked
        $r1 = FinalResult::where('enrollment_id', $resit->id)->where('exam_round', 1)->first();
        $final = $o->components()->where('code', 'final')->first();
        $order = AdministrativeOrder::create(['number' => '78', 'issued_on' => now()->toDateString(), 'type' => 'result_correction', 'title' => 'تصحيح', 'issued_by_authority' => 'العمادة']);
        $this->actingAs($this->committeeA)->post(route('offerings.correct', [$o, $r1]), [
            'order_id' => $order->id, 'reason' => 'خطأ', 'marks' => [$final->id => ['mark' => 26]],
        ])->assertSessionHas('error');
        $this->assertTrue($r1->fresh()->is_current);
        $this->assertSame(1, FinalResult::where('enrollment_id', $resit->id)->where('exam_round', 1)->count());
    }

    public function test_a_draft_of_a_student_no_longer_in_the_round_blocks_submit(): void
    {
        $this->runToLocked([55, 20, 14, 17]);
        $o = $this->offeringA->fresh();
        $this->actingAs($this->committeeA)->post(route('offerings.decide', $o), ['action' => 'next_round']);
        $o->refresh();
        $resit = app(GradebookService::class)->eligible($o, 2)[0];
        $r2 = $o->components()->where('code', 'final_r2')->first();
        $this->actingAs($this->teacherA)->post(route('offerings.marks', $o), ['rows' => [$resit->id => [$r2->id => ['mark' => 15]]], 'compute' => true]);
        // a round-2 draft for a student who passed round 1 (as an earlier correction could have left behind)
        $passed = $o->enrollments()->orderBy('id')->first();
        FinalResult::create(FinalResult::where('enrollment_id', $resit->id)->where('exam_round', 2)->first()->only([
            'total_mark', 'rounded_mark', 'grade_code', 'grade_name_ar', 'grade_points', 'passed', 'outcome', 'credits_earned', 'policy_snapshot', 'input_hash',
        ]) + ['enrollment_id' => $passed->id, 'exam_round' => 2, 'version' => 1, 'status' => 'draft', 'is_current' => true]);

        $this->actingAs($this->teacherA)->post(route('offerings.submit', $o))->assertSessionHas('error');
        $this->assertSame('open', $o->fresh()->status);
    }

    public function test_only_the_course_teacher_enters_marks(): void
    {
        $this->actingAs($this->committeeA)->post(route('offerings.marks', $this->offeringA), ['rows' => $this->marksFor($this->offeringA, [50])])->assertForbidden();
    }

    public function test_locked_result_is_immutable(): void
    {
        $this->runToLocked([55, 20, 14, 17]);
        $this->expectException(LogicException::class);
        FinalResult::first()->update(['rounded_mark' => 99]);
    }

    public function test_correction_creates_new_version_backed_by_order(): void
    {
        $this->runToLocked([55, 20, 14, 17]);
        $old = FinalResult::orderBy('enrollment_id')->skip(2)->first(); // 44, fail
        $final = $this->offeringA->components()->where('code', 'final')->first();
        $order = AdministrativeOrder::create(['number' => '77', 'issued_on' => now()->toDateString(), 'type' => 'result_correction', 'title' => 'تصحيح', 'issued_by_authority' => 'العمادة']);

        $this->actingAs($this->committeeA)->post(route('offerings.correct', [$this->offeringA, $old]), [
            'order_id' => $order->id, 'reason' => 'خطأ في الجمع', 'marks' => [$final->id => ['mark' => 26]],
        ])->assertSessionHas('success');

        $old->refresh();
        $new = FinalResult::where('supersedes_id', $old->id)->firstOrFail();
        $this->assertFalse($old->is_current);
        $this->assertSame(44.0, $old->rounded_mark, 'the superseded result keeps its original mark');
        $this->assertSame(56.0, $new->rounded_mark);
        $this->assertSame(2, $new->version);
        $this->assertSame($order->id, $new->administrative_order_id);
        $this->assertSame('locked', $new->status);
        $this->assertTrue(AuditLog::where('auditable_type', FinalResult::class)->where('reason', 'like', 'خطأ في الجمع%')->exists());
    }

    /** A grade scale for Bologna in which 40–49 is FX (resit), so 44 would no longer be a plain fail. */
    private function widerResitScale(string $from): AcademicPolicy
    {
        $current = AcademicPolicy::where('type', 'grade_scale')->where('study_system', 'bologna')->firstOrFail();
        $bands = collect($current->parameters['bands'])->map(fn ($b) => match ($b['code']) {
            'FX' => ['min' => 40] + $b,
            'F' => ['max' => 39] + $b,
            default => $b,
        })->all();

        return AcademicPolicy::create([
            'type' => 'grade_scale', 'name_ar' => 'سلم أوسع للمعالجة', 'version' => $current->version + 1,
            'executor' => $current->executor, 'parameters' => ['bands' => $bands] + $current->parameters,
            'study_system' => 'bologna', 'scope_type' => 'university', 'effective_from' => $from,
            'is_official' => false, 'status' => 'approved', 'approved_at' => now(),
        ]);
    }

    public function test_results_use_the_policies_of_the_offerings_year_not_today(): void
    {
        // the fixture year is 2025-2026 (ends 2026-08-31); this version only starts the year after
        $this->widerResitScale('2026-09-01');
        $this->runToLocked([55, 20, 14, 17]);

        $r = FinalResult::orderBy('enrollment_id')->skip(2)->first(); // 30 + 14 = 44
        $this->assertSame('fail', $r->outcome);
        $this->assertSame(1, $r->policy_snapshot['grade_scale']['version']);
    }

    public function test_correction_keeps_the_policies_of_the_corrected_result(): void
    {
        $this->runToLocked([55, 20, 14, 17]);
        // a new scale approved afterwards, effective for the same year
        $this->widerResitScale('2025-09-01');
        $old = FinalResult::orderBy('enrollment_id')->skip(2)->first(); // 44, fail
        $final = $this->offeringA->components()->where('code', 'final')->first();
        $order = AdministrativeOrder::create(['number' => '78', 'issued_on' => now()->toDateString(), 'type' => 'result_correction', 'title' => 'تصحيح', 'issued_by_authority' => 'العمادة']);

        $this->actingAs($this->committeeA)->post(route('offerings.correct', [$this->offeringA, $old]), [
            'order_id' => $order->id, 'reason' => 'خطأ في الجمع', 'marks' => [$final->id => ['mark' => 12]],
        ])->assertSessionHas('success');

        $new = FinalResult::where('supersedes_id', $old->id)->firstOrFail();
        $this->assertSame(42.0, $new->rounded_mark);
        $this->assertSame('fail', $new->outcome, 'the new scale would make 42 a resit; the correction must not change the rules');
        $this->assertSame($old->policy_snapshot['grade_scale'], $new->policy_snapshot['grade_scale']);
    }

    public function test_policy_set_is_rebuilt_from_a_result_snapshot(): void
    {
        $this->runToLocked([55, 20, 14, 17]);
        $snapshot = FinalResult::first()->policy_snapshot;
        $snapshot['evaluative'] = ['final' => 30.0, 'threshold' => 20.0, 'decision' => null];

        $set = PolicySet::fromResultSnapshot($snapshot);
        foreach (['grade_scale', 'rounding', 'total', 'rounds'] as $type) {
            $this->assertSame($snapshot[$type], $set->get($type)->snapshot());
        }
        $this->assertFalse($set->has('evaluative'));
        $this->assertFalse($set->has('decision'));
    }

    public function test_second_round_only_for_resit_students(): void
    {
        $this->runToLocked([55, 20, 14, 17]);
        $o = $this->offeringA->fresh();
        $this->actingAs($this->committeeA)->post(route('offerings.decide', $o), ['action' => 'next_round'])->assertSessionHas('success');
        $o->refresh();
        $this->assertSame(2, $o->current_round);

        $eligible = app(GradebookService::class)->eligible($o, 2);
        $this->assertCount(1, $eligible);

        $r2 = $o->components()->where('code', 'final_r2')->first();
        $this->actingAs($this->teacherA)->post(route('offerings.marks', $o), ['rows' => [$eligible[0]->id => [$r2->id => ['mark' => 15]]], 'compute' => true]);
        $result = FinalResult::where('exam_round', 2)->firstOrFail();
        $this->assertSame(45.0, $result->rounded_mark); // 30 + 15/60*60
        $this->assertSame('fail', $result->outcome, 'a resit band in the last round is a fail');
    }
}
