<?php

namespace Tests\Unit\AcademicRules;

use App\AcademicRules\Data\GpaItem;
use App\AcademicRules\Executors\Gpa;
use App\AcademicRules\Executors\Progression;
use App\AcademicRules\RulesException;

class GpaAndProgressionTest extends RulesTestCase
{
    public function test_ects_weighted_mark_average(): void
    {
        $gpa = new Gpa($this->defaults('bologna')->get('gpa'));
        $value = $gpa->compute([
            new GpaItem('C1', 80, null, 3, 6, true),
            new GpaItem('C2', 60, null, 2, 4, true),
        ]);
        $this->assertSame(72.0, $value); // (80×6 + 60×4) / 10
    }

    public function test_excluding_failed_courses(): void
    {
        $gpa = new Gpa($this->policy('gpa', 'gpa@1', ['basis' => 'marks', 'weight' => 'credit_hours', 'include_failed' => false]));
        $this->assertSame(80.0, $gpa->compute([new GpaItem('C1', 80, null, 3, null, true), new GpaItem('C2', 30, null, 3, null, false)]));
    }

    public function test_no_courses_gives_no_average(): void
    {
        $this->assertNull((new Gpa($this->defaults('annual')->get('gpa')))->compute([]));
    }

    public function test_missing_weight_is_refused(): void
    {
        $this->expectException(RulesException::class);
        (new Gpa($this->defaults('bologna')->get('gpa')))->compute([new GpaItem('C1', 80, null, 3, null, true)]);
    }

    public function test_progression(): void
    {
        $p = new Progression($this->defaults('annual')->get('progression'));
        $this->assertSame('promoted', $p->decide(0, false));
        $this->assertSame('graduate', $p->decide(0, true));
        $this->assertSame('promoted_with_carried', $p->decide(2, false));
        $this->assertSame('repeat', $p->decide(3, false));
        $this->assertSame('repeat', $p->decide(1, true));
    }
}
