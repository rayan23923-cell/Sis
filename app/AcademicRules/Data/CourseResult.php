<?php

namespace App\AcademicRules\Data;

/**
 * What the engine decided for one student in one course round, plus everything needed to prove it.
 */
final class CourseResult
{
    /** @param array<string, array<string, mixed>> $policySnapshot */
    public function __construct(
        public readonly float $total,
        public readonly float $rounded,
        public readonly GradeBand $band,
        public readonly float $creditsEarned,
        public readonly array $policySnapshot,
        public readonly string $inputHash,
        public readonly bool $official,
    ) {}

    public function passed(): bool
    {
        return $this->band->passed;
    }

    public function outcome(): string
    {
        return $this->band->outcome;
    }
}
