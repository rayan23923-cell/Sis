<?php

namespace Tests\Unit\AcademicRules;

use App\AcademicRules\Executors\Rounding;
use App\AcademicRules\RulesException;
use PHPUnit\Framework\Attributes\DataProvider;

class RoundingTest extends RulesTestCase
{
    public static function halfUp(): array
    {
        return [[49.5, 50.0], [49.49, 49.0], [44.5, 45.0], [89.5, 90.0], [0.0, 0.0], [100.0, 100.0], [49.499999999997, 50.0]];
    }

    #[DataProvider('halfUp')]
    public function test_half_up_to_whole_marks(float $in, float $out): void
    {
        $r = new Rounding($this->policy('rounding', 'rounding@1', ['mode' => 'half_up', 'precision' => 0]));
        $this->assertSame($out, $r->apply($in));
    }

    public function test_none_keeps_the_mark(): void
    {
        $r = new Rounding($this->policy('rounding', 'rounding@1', ['mode' => 'none', 'precision' => 2]));
        $this->assertSame(49.57, $r->apply(49.57));
    }

    public function test_floor_and_precision(): void
    {
        $r = new Rounding($this->policy('rounding', 'rounding@1', ['mode' => 'floor', 'precision' => 1]));
        $this->assertSame(49.9, $r->apply(49.99));
    }

    public function test_unknown_mode_is_refused(): void
    {
        $this->expectException(RulesException::class);
        (new Rounding($this->policy('rounding', 'rounding@1', ['mode' => 'banker'])))->apply(1.5);
    }

    public function test_wrong_executor_is_refused(): void
    {
        $this->expectException(RulesException::class);
        new Rounding($this->policy('rounding', 'grade_scale@1', []));
    }
}
