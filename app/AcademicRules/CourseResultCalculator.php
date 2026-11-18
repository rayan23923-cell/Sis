<?php

namespace App\AcademicRules;

use App\AcademicRules\Data\ComponentMark;
use App\AcademicRules\Data\CourseResult;
use App\AcademicRules\Executors\GradeScale;
use App\AcademicRules\Executors\Rounding;
use App\AcademicRules\Executors\WeightedTotal;

/**
 * Computes one course result: weighted total → rounding → grade band → credits earned.
 * Pure: the same marks and the same policy set always give the same result (and the same hash).
 */
final class CourseResultCalculator
{
    /** @param list<ComponentMark> $components */
    public function calculate(PolicySet $policies, array $components, float $courseCredits): CourseResult
    {
        $total = (new WeightedTotal($policies->get('total')))->total($components);
        $rounded = (new Rounding($policies->get('rounding')))->apply($total);
        $band = (new GradeScale($policies->get('grade_scale')))->band($rounded);

        $snapshot = [
            'grade_scale' => $policies->get('grade_scale')->snapshot(),
            'rounding' => $policies->get('rounding')->snapshot(),
            'total' => $policies->get('total')->snapshot(),
        ];

        return new CourseResult(
            total: $total,
            rounded: $rounded,
            band: $band,
            creditsEarned: $band->passed ? $courseCredits : 0.0,
            policySnapshot: $snapshot,
            inputHash: self::hash($components, $courseCredits),
            official: $policies->allOfficial(),
        );
    }

    /** @param list<ComponentMark> $components */
    public static function hash(array $components, float $credits): string
    {
        $rows = array_map(fn (ComponentMark $c) => $c->toArray(), $components);
        usort($rows, fn ($a, $b) => strcmp($a['code'], $b['code']));

        return hash('sha256', json_encode(['components' => $rows, 'credits' => $credits], JSON_PRESERVE_ZERO_FRACTION));
    }
}
