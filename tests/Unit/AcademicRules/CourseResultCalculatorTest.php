<?php

namespace Tests\Unit\AcademicRules;

use App\AcademicRules\CourseResultCalculator;
use App\AcademicRules\Data\ComponentMark;
use App\AcademicRules\Executors\ExamRounds;
use App\AcademicRules\PolicySet;

class CourseResultCalculatorTest extends RulesTestCase
{
    private function marks(float $coursework, ?float $final, bool $absent = false): array
    {
        return [new ComponentMark('coursework', 40, 40, $coursework), new ComponentMark('final', 60, 100, $final, $absent)];
    }

    public function test_rounding_lifts_49_5_to_a_pass(): void
    {
        // 19.5 + 0.5 × 60 = 49.5 → 50 → E (pass)
        $r = (new CourseResultCalculator)->calculate($this->defaults('bologna'), $this->marks(19.5, 50), 6);
        $this->assertSame(49.5, $r->total);
        $this->assertSame(50.0, $r->rounded);
        $this->assertSame('E', $r->band->code);
        $this->assertTrue($r->passed());
        $this->assertSame(6.0, $r->creditsEarned);
    }

    public function test_fx_earns_no_credit_and_may_resit(): void
    {
        $r = (new CourseResultCalculator)->calculate($this->defaults('bologna'), $this->marks(20, 45), 6);
        $this->assertSame('FX', $r->band->code);
        $this->assertSame(0.0, $r->creditsEarned);
        $rounds = new ExamRounds($this->defaults('bologna')->get('rounds'));
        $this->assertSame('resit', $rounds->finalOutcome($r->outcome(), 1));
        $this->assertSame('fail', $rounds->finalOutcome($r->outcome(), 2));
    }

    public function test_same_input_same_hash_and_snapshot_replays(): void
    {
        $calc = new CourseResultCalculator;
        $a = $calc->calculate($this->defaults('annual'), $this->marks(30, 70), 4);
        $b = $calc->calculate(PolicySet::fromSnapshot($a->policySnapshot), $this->marks(30, 70), 4);
        $this->assertSame($a->inputHash, $b->inputHash);
        $this->assertSame($a->rounded, $b->rounded);
        $this->assertSame($a->band->code, $b->band->code);
    }

    public function test_changed_mark_changes_hash(): void
    {
        $calc = new CourseResultCalculator;
        $a = $calc->calculate($this->defaults('annual'), $this->marks(30, 70), 4);
        $b = $calc->calculate($this->defaults('annual'), $this->marks(30, 71), 4);
        $this->assertNotSame($a->inputHash, $b->inputHash);
    }

    public function test_default_policies_are_not_official(): void
    {
        $r = (new CourseResultCalculator)->calculate($this->defaults('bologna'), $this->marks(40, 100), 6);
        $this->assertFalse($r->official);
        $this->assertSame('A', $r->band->code);
    }
}
