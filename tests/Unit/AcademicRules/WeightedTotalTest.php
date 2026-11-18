<?php

namespace Tests\Unit\AcademicRules;

use App\AcademicRules\Data\ComponentMark;
use App\AcademicRules\Executors\WeightedTotal;
use App\AcademicRules\RulesException;

class WeightedTotalTest extends RulesTestCase
{
    private function calc(array $params = []): WeightedTotal
    {
        return new WeightedTotal($this->policy('total', 'total@1', $params + ['absent_counts_as_zero' => true, 'require_all_marks' => true]));
    }

    public function test_coursework_and_final(): void
    {
        $total = $this->calc()->total([
            new ComponentMark('coursework', 40, 40, 30),
            new ComponentMark('final', 60, 100, 70),
        ]);
        $this->assertEqualsWithDelta(72.0, $total, 1e-9); // 30 + 0.7 × 60
    }

    public function test_absent_counts_as_zero(): void
    {
        $total = $this->calc()->total([
            new ComponentMark('coursework', 40, 40, 40),
            new ComponentMark('final', 60, 100, null, absent: true),
        ]);
        $this->assertEqualsWithDelta(40.0, $total, 1e-9);
    }

    public function test_missing_mark_is_refused(): void
    {
        $this->expectException(RulesException::class);
        $this->calc()->total([new ComponentMark('coursework', 40, 40, 30), new ComponentMark('final', 60, 100, null)]);
    }

    public function test_weights_must_add_to_100(): void
    {
        $this->expectException(RulesException::class);
        $this->calc()->total([new ComponentMark('coursework', 30, 30, 30), new ComponentMark('final', 60, 100, 60)]);
    }

    public function test_mark_above_maximum_is_refused(): void
    {
        $this->expectException(RulesException::class);
        $this->calc()->total([new ComponentMark('coursework', 40, 40, 41), new ComponentMark('final', 60, 100, 60)]);
    }
}
