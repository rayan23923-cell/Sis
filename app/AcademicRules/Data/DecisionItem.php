<?php

namespace App\AcademicRules\Data;

/** One course of a student's year, as the decision ("القرار") sees it. */
final class DecisionItem
{
    public function __construct(
        public readonly string $key,
        public readonly float $mark,             // the course's final mark in this round (before any decision)
        public readonly float $coursework,       // السعي
        public readonly float $units,            // credit units
        public readonly int $specialtyRank,      // 1 = closest to the specialty
        public readonly bool $carried = false,   // a course carried from a lower stage
        public readonly bool $blocked = false,   // may not receive a decision (e.g. failed the national evaluative exam)
    ) {}
}
