<?php

namespace App\AcademicRules\Executors;

use App\AcademicRules\Policy;
use App\AcademicRules\RulesException;

/**
 * rounds@1 — how many exam rounds exist and which outcomes may go on to the next one.
 * Parameters: max_rounds (int, default 2), resit_outcomes (list, default ["resit"]),
 * resit_cap (null or a number: the highest mark a later round may record, if the rules cap it).
 */
final class ExamRounds
{
    public function __construct(private readonly Policy $policy)
    {
        if ($policy->executor !== 'rounds@1') {
            throw new RulesException("ExamRounds cannot run executor {$policy->executor}.");
        }
    }

    public function maxRounds(): int
    {
        return max(1, (int) ($this->policy->parameters['max_rounds'] ?? 2));
    }

    /** The final word on an outcome in a given round: a resit in the last round is a fail. */
    public function finalOutcome(string $bandOutcome, int $round): string
    {
        $resits = $this->policy->parameters['resit_outcomes'] ?? ['resit'];
        if (in_array($bandOutcome, $resits, true)) {
            return $round < $this->maxRounds() ? 'resit' : 'fail';
        }

        return $bandOutcome;
    }

    public function cap(int $round): ?float
    {
        $cap = $this->policy->parameters['resit_cap'] ?? null;

        return $round > 1 && $cap !== null ? (float) $cap : null;
    }
}
