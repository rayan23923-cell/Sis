<?php

namespace Tests\Unit\AcademicRules;

use App\AcademicRules\Data\DecisionItem;
use App\AcademicRules\Evaluative;
use App\AcademicRules\Executors\Decision;
use App\AcademicRules\Policy;
use PHPUnit\Framework\TestCase;

class EvaluativeTest extends TestCase
{
    private function policy(array $evaluative = [], array $over = []): Policy
    {
        return new Policy(1, 'decision', 3, 'decision@1', $over + [
            'amounts' => ['1' => 5, '2' => 0], 'modes' => ['1' => 'benefit', '2' => 'benefit'], 'pass_mark' => 50, 'resit_deduction' => 10,
            'evaluative' => $evaluative,
        ], false);
    }

    public function test_fails_below_twenty_of_sixty_and_scales_to_the_real_final_weight(): void
    {
        $e = new Evaluative($this->policy());
        $this->assertTrue($e->fails(19, 60));
        $this->assertFalse($e->fails(20, 60));
        $this->assertEquals(16.67, $e->threshold(50));
        $this->assertFalse($e->fails(null, 60));   // no exam mark yet: nothing to judge
    }

    public function test_treatment_covers_a_small_gap_only_and_never_for_excluded_colleges(): void
    {
        // 5 marks out of 100 on a final weighted 60 → 3 points
        $e = new Evaluative($this->policy(['treatment' => 5, 'excluded_colleges' => ['MED']]));
        $this->assertSame(['failed' => false, 'treated' => 2.0, 'threshold' => 20.0], $e->assess(18, 60, false, 'ENG'));
        $this->assertTrue($e->assess(16, 60, false, 'ENG')['failed']);   // needs 4 > 3
        $this->assertTrue($e->assess(18, 60, false, 'MED')['failed']);
        // the final-stage bonus adds to the allowance; "mark" scale takes the marks as given
        $f = new Evaluative($this->policy(['treatment' => 5, 'final_stage_bonus' => 1, 'treatment_scale' => 'mark']));
        $this->assertFalse($f->assess(14, 60, true, null)['failed']);
        $this->assertTrue($f->assess(14, 60, false, null)['failed']);
    }

    public function test_the_decision_skips_a_blocked_evaluative_failure(): void
    {
        $d = new Decision($this->policy(over: ['final_stage_extra' => 5, 'no_fail_year' => true]));
        $r = $d->apply([new DecisionItem('EV', 48, 30, 3, 1, blocked: true), new DecisionItem('B', 47, 30, 3, 2)], 5);
        $this->assertSame(['B' => 3.0], $r['awards']);
        $this->assertEquals(5, $d->finalStageExtra());
        $this->assertTrue($d->noFailYear());
        $this->assertEquals(0, $d->evaluative->treatment());
    }
}
