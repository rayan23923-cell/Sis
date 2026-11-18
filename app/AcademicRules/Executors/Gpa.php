<?php

namespace App\AcademicRules\Executors;

use App\AcademicRules\Data\GpaItem;
use App\AcademicRules\Policy;
use App\AcademicRules\RulesException;

/**
 * gpa@1 — a credit-weighted average.
 * Parameters: basis (points | marks), weight (ects | credit_hours), precision (decimals, default 2),
 * include_failed (bool, default true: failed courses count with their points/marks).
 */
final class Gpa
{
    public function __construct(private readonly Policy $policy)
    {
        if ($policy->executor !== 'gpa@1') {
            throw new RulesException("Gpa cannot run executor {$policy->executor}.");
        }
    }

    /** @param list<GpaItem> $items */
    public function compute(array $items): ?float
    {
        $basis = $this->policy->parameters['basis'] ?? 'points';
        $weightBy = $this->policy->parameters['weight'] ?? 'credit_hours';
        $precision = (int) ($this->policy->parameters['precision'] ?? 2);
        $includeFailed = (bool) ($this->policy->parameters['include_failed'] ?? true);

        $sum = 0.0;
        $weights = 0.0;
        foreach ($items as $item) {
            if (! $item->passed && ! $includeFailed) {
                continue;
            }
            $w = $weightBy === 'ects' ? $item->ects : $item->creditHours;
            $value = $basis === 'marks' ? $item->mark : $item->points;
            if ($w === null || $value === null) {
                throw new RulesException("Course {$item->courseCode} has no {$weightBy} or {$basis} for the average.");
            }
            $sum += $value * $w;
            $weights += $w;
        }

        if ($weights <= 0) {
            return null;
        }

        return round($sum / $weights, $precision);
    }
}
