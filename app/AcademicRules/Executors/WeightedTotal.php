<?php

namespace App\AcademicRules\Executors;

use App\AcademicRules\Data\ComponentMark;
use App\AcademicRules\Policy;
use App\AcademicRules\RulesException;

/**
 * total@1 — the course total out of 100 from weighted components.
 * Each component adds (mark / max_mark) × weight. Weights must add up to 100.
 * Parameters: absent_counts_as_zero (bool, default true), require_all_marks (bool, default true).
 */
final class WeightedTotal
{
    public function __construct(private readonly Policy $policy)
    {
        if ($policy->executor !== 'total@1') {
            throw new RulesException("WeightedTotal cannot run executor {$policy->executor}.");
        }
    }

    /** @param list<ComponentMark> $components */
    public function total(array $components): float
    {
        if ($components === []) {
            throw new RulesException('A course needs at least one assessment component.');
        }

        $weights = array_sum(array_map(fn (ComponentMark $c) => $c->weight, $components));
        if (abs($weights - 100) > 0.001) {
            throw new RulesException("Component weights add up to {$weights}, not 100.");
        }

        $absentAsZero = (bool) ($this->policy->parameters['absent_counts_as_zero'] ?? true);
        $requireAll = (bool) ($this->policy->parameters['require_all_marks'] ?? true);

        $total = 0.0;
        foreach ($components as $c) {
            if ($c->absent) {
                if (! $absentAsZero) {
                    throw new RulesException("Absence in '{$c->code}' has no rule in this policy.");
                }

                continue;
            }
            if ($c->mark === null) {
                if ($requireAll) {
                    throw new RulesException("Mark for '{$c->code}' is missing.");
                }

                continue;
            }
            if ($c->mark < 0 || $c->mark > $c->maxMark) {
                throw new RulesException("Mark {$c->mark} for '{$c->code}' is outside 0–{$c->maxMark}.");
            }
            $total += ($c->mark / $c->maxMark) * $c->weight;
        }

        return round($total, 6);
    }
}
