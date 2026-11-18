<?php

namespace App\AcademicRules\Executors;

use App\AcademicRules\Data\GradeBand;
use App\AcademicRules\Policy;
use App\AcademicRules\RulesException;

/**
 * grade_scale@1 — maps a rounded mark to a band.
 * Parameters: bands: [{code, name_ar, name_en, min, max, points, passed, outcome}], where outcome is
 * pass | fail | resit (resit = may sit the next round, e.g. FX). Bands must cover 0–100 with no gaps.
 */
final class GradeScale
{
    /** @var list<GradeBand> */
    private array $bands;

    public function __construct(private readonly Policy $policy)
    {
        if ($policy->executor !== 'grade_scale@1') {
            throw new RulesException("GradeScale cannot run executor {$policy->executor}.");
        }

        $bands = array_map(fn (array $b) => GradeBand::fromArray($b), $policy->parameters['bands'] ?? []);
        usort($bands, fn (GradeBand $a, GradeBand $b) => $a->min <=> $b->min);
        if ($bands === [] || $bands[0]->min > 0 || end($bands)->max < 100) {
            throw new RulesException('Grade scale must cover marks 0 to 100.');
        }
        for ($i = 1; $i < count($bands); $i++) {
            $gap = $bands[$i]->min - $bands[$i - 1]->max;
            if ($gap > 1.0001 || $gap <= 0) {
                throw new RulesException("Grade bands {$bands[$i - 1]->code} and {$bands[$i]->code} overlap or leave a gap.");
            }
        }
        $this->bands = $bands;
    }

    public function band(float $roundedMark): GradeBand
    {
        // Bands are written in whole marks (50–59, 60–69); a mark of 59.5 that the policy chose not to
        // round still belongs below 60, so a mark falls in the highest band whose min it reaches.
        $found = null;
        foreach ($this->bands as $band) {
            if ($roundedMark + 1e-9 >= $band->min) {
                $found = $band;
            }
        }

        return $found ?? throw new RulesException("No grade band for mark {$roundedMark}.");
    }

    /** @return list<GradeBand> */
    public function bands(): array
    {
        return $this->bands;
    }
}
