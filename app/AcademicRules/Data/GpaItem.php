<?php

namespace App\AcademicRules\Data;

final class GpaItem
{
    public function __construct(
        public readonly string $courseCode,
        public readonly ?float $mark,
        public readonly ?float $points,
        public readonly ?float $creditHours,
        public readonly ?float $ects,
        public readonly bool $passed,
    ) {}
}
