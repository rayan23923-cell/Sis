<?php

namespace Tests\Unit\AcademicRules;

use App\AcademicRules\Executors\GradeScale;
use App\AcademicRules\RulesException;
use PHPUnit\Framework\Attributes\DataProvider;

class GradeScaleTest extends RulesTestCase
{
    public static function bologna(): array
    {
        return [
            [100, 'A', 'pass'], [90, 'A', 'pass'], [89, 'B', 'pass'], [70, 'C', 'pass'], [69, 'D', 'pass'],
            [50, 'E', 'pass'], [49, 'FX', 'resit'], [45, 'FX', 'resit'], [44, 'F', 'fail'], [0, 'F', 'fail'],
            [49.6, 'FX', 'resit'], // unrounded marks stay below the next band
        ];
    }

    #[DataProvider('bologna')]
    public function test_bologna_bands(float $mark, string $code, string $outcome): void
    {
        $band = (new GradeScale($this->defaults('bologna')->get('grade_scale')))->band($mark);
        $this->assertSame($code, $band->code);
        $this->assertSame($outcome, $band->outcome);
    }

    public function test_traditional_pass_line_is_50(): void
    {
        $scale = new GradeScale($this->defaults('annual')->get('grade_scale'));
        $this->assertTrue($scale->band(50)->passed);
        $this->assertFalse($scale->band(49)->passed);
    }

    public function test_gap_in_bands_is_refused(): void
    {
        $this->expectException(RulesException::class);
        new GradeScale($this->policy('grade_scale', 'grade_scale@1', ['bands' => [
            ['code' => 'P', 'name_ar' => 'ناجح', 'min' => 60, 'max' => 100, 'passed' => true],
            ['code' => 'F', 'name_ar' => 'راسب', 'min' => 0, 'max' => 49, 'passed' => false],
        ]]));
    }

    public function test_scale_must_reach_100(): void
    {
        $this->expectException(RulesException::class);
        new GradeScale($this->policy('grade_scale', 'grade_scale@1', ['bands' => [
            ['code' => 'P', 'name_ar' => 'ناجح', 'min' => 50, 'max' => 90, 'passed' => true],
            ['code' => 'F', 'name_ar' => 'راسب', 'min' => 0, 'max' => 49, 'passed' => false],
        ]]));
    }
}
