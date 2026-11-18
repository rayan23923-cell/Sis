<?php

namespace Tests\Unit\AcademicRules;

use App\AcademicRules\Data\DecisionItem;
use App\AcademicRules\Executors\Decision;
use App\AcademicRules\Policy;
use PHPUnit\Framework\TestCase;

class DecisionTest extends TestCase
{
    private function decision(array $over = []): Decision
    {
        return new Decision(new Policy(1, 'decision', 1, 'decision@1', $over + [
            'amounts' => ['1' => 5, '2' => 0], 'modes' => ['1' => 'benefit', '2' => 'benefit'], 'carried_first' => true,
            'priorities' => ['reduce_failures', 'more_units', 'closest_specialty', 'lower_coursework'], 'pass_mark' => 50, 'resit_deduction' => 10,
        ], false));
    }

    private function item(string $k, float $mark, float $units = 3, int $rank = 5, bool $carried = false, float $cw = 30): DecisionItem
    {
        return new DecisionItem($k, $mark, $cw, $units, $rank, $carried);
    }

    public function test_lifts_the_courses_closest_to_passing_first_until_the_amount_runs_out(): void
    {
        $r = $this->decision()->apply([$this->item('A', 46), $this->item('B', 48), $this->item('C', 47), $this->item('D', 60)], 5);
        // B needs 2, C needs 3 → 5 used; A (needs 4) gets nothing
        $this->assertSame(['B' => 2.0, 'C' => 3.0], $r['awards']);
        $this->assertEquals(5, $r['used']);
    }

    public function test_skips_a_course_it_cannot_lift_and_tries_the_next(): void
    {
        $d = $this->decision(['priorities' => ['more_units', 'reduce_failures', 'closest_specialty', 'lower_coursework']]);
        $r = $d->apply([$this->item('BIG', 40, 6), $this->item('SMALL', 47, 2)], 5);
        $this->assertSame(['SMALL' => 3.0], $r['awards']);
    }

    public function test_carried_courses_come_first_and_ties_use_the_next_priority(): void
    {
        $r = $this->decision()->apply([$this->item('OWN', 48), $this->item('CAR', 46, carried: true)], 5);
        $this->assertSame(['CAR' => 4.0], $r['awards']);

        $r = $this->decision()->apply([$this->item('X', 47, 2, 3), $this->item('Y', 47, 2, 1)], 3);
        $this->assertSame(['Y' => 3.0], $r['awards']);   // same mark and units: closer to the specialty wins
    }

    public function test_status_mode_gives_nothing_unless_every_failure_is_cleared(): void
    {
        $d = $this->decision();
        $this->assertSame([], $d->apply([$this->item('A', 48), $this->item('B', 40)], 5, 'status')['awards']);
        $this->assertSame(['A' => 2.0, 'B' => 3.0], $d->apply([$this->item('A', 48), $this->item('B', 47)], 5, 'status')['awards']);
    }

    public function test_second_round_entitlement_deducts_ten_but_never_below_fifty(): void
    {
        $d = $this->decision();
        $this->assertEquals(62, $d->entitlement(72, true));
        $this->assertEquals(50, $d->entitlement(55, true));
        $this->assertEquals(45, $d->entitlement(45, true));
        $this->assertEquals(72, $d->entitlement(72, false));
    }
}
