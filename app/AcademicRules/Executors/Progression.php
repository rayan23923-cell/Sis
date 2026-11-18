<?php

namespace App\AcademicRules\Executors;

use App\AcademicRules\Policy;
use App\AcademicRules\RulesException;

/**
 * progression@1 — the traditional (annual/semester) end-of-year decision after the last exam round.
 * Parameters: max_carried (how many failed courses may be carried to the next stage),
 * carry_in_final_stage (bool: may a final-stage student graduate later with carried courses).
 * Returns promoted | promoted_with_carried | repeat | graduate.
 */
final class Progression
{
    public function __construct(private readonly Policy $policy)
    {
        if ($policy->executor !== 'progression@1') {
            throw new RulesException("Progression cannot run executor {$policy->executor}.");
        }
    }

    public function decide(int $failedCourses, bool $finalStage): string
    {
        $maxCarried = (int) ($this->policy->parameters['max_carried'] ?? 0);

        if ($failedCourses === 0) {
            return $finalStage ? 'graduate' : 'promoted';
        }
        if ($finalStage) {
            return 'repeat';
        }

        return $failedCourses <= $maxCarried ? 'promoted_with_carried' : 'repeat';
    }
}
