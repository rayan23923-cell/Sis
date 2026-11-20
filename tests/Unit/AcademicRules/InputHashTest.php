<?php

namespace Tests\Unit\AcademicRules;

use App\AcademicRules\CourseResultCalculator;
use App\AcademicRules\Data\ComponentMark;
use PHPUnit\Framework\TestCase;

/** F-G3-1: the input hash is what submit compares, so it must be stable for equal inputs and differ for any change. */
class InputHashTest extends TestCase
{
    private function hash(array $rows, float $credits = 3): string
    {
        return CourseResultCalculator::hash(array_map(fn ($r) => new ComponentMark(...$r), $rows), $credits);
    }

    public function test_component_order_does_not_change_the_hash(): void
    {
        $a = $this->hash([['coursework', 40, 40, 30.0], ['final', 60, 60, 14.0]]);
        $b = $this->hash([['final', 60, 60, 14.0], ['coursework', 40, 40, 30.0]]);
        $this->assertSame($a, $b);
    }

    public function test_equal_numbers_hash_alike_however_they_were_written(): void
    {
        // the database returns 14, the form "14", the cast 14.0: the model casts all of them to the same float
        $this->assertSame($this->hash([['final', 60, 60, 14.0]]), $this->hash([['final', 60.0, 60.0, (float) '14.00']]));
        $this->assertSame($this->hash([['final', 60, 60, 0.1 + 0.2]]), $this->hash([['final', 60, 60, 0.1 + 0.2]]), 'repeatable');
    }

    public function test_every_kind_of_change_changes_the_hash(): void
    {
        $base = $this->hash([['coursework', 40, 40, 30.0], ['final', 60, 60, 14.0]]);
        $changes = [
            'a mark' => $this->hash([['coursework', 40, 40, 30.0], ['final', 60, 60, 15.0]]),
            'a mark cleared' => $this->hash([['coursework', 40, 40, 30.0], ['final', 60, 60, null]]),
            'absent instead of zero' => $this->hash([['coursework', 40, 40, 30.0], ['final', 60, 60, null, true]]),
            'zero instead of empty' => $this->hash([['coursework', 40, 40, 30.0], ['final', 60, 60, 0.0]]),
            'a component removed' => $this->hash([['coursework', 40, 40, 30.0]]),
            'a weight' => $this->hash([['coursework', 30, 40, 30.0], ['final', 70, 60, 14.0]]),
            'a maximum' => $this->hash([['coursework', 40, 50, 30.0], ['final', 60, 60, 14.0]]),
            'the credits' => $this->hash([['coursework', 40, 40, 30.0], ['final', 60, 60, 14.0]], 4),
        ];
        foreach ($changes as $what => $h) {
            $this->assertNotSame($base, $h, $what);
        }
        $this->assertNotSame($changes['a mark cleared'], $changes['zero instead of empty']);
        $this->assertNotSame($changes['a mark cleared'], $changes['absent instead of zero']);
    }
}
