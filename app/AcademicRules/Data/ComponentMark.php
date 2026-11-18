<?php

namespace App\AcademicRules\Data;

final class ComponentMark
{
    public function __construct(
        public readonly string $code,
        public readonly float $weight,
        public readonly float $maxMark,
        public readonly ?float $mark,
        public readonly bool $absent = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['code' => $this->code, 'weight' => $this->weight, 'max' => $this->maxMark, 'mark' => $this->mark, 'absent' => $this->absent];
    }
}
