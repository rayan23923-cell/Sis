<?php

namespace App\AcademicRules\Data;

use App\AcademicRules\RulesException;

final class GradeBand
{
    public function __construct(
        public readonly string $code,
        public readonly string $nameAr,
        public readonly ?string $nameEn,
        public readonly float $min,
        public readonly float $max,
        public readonly ?float $points,
        public readonly bool $passed,
        public readonly string $outcome,
    ) {
        if (! in_array($outcome, ['pass', 'fail', 'resit'], true)) {
            throw new RulesException("Band {$code} has an unknown outcome '{$outcome}'.");
        }
    }

    /** @param array<string, mixed> $b */
    public static function fromArray(array $b): self
    {
        return new self(
            (string) $b['code'],
            (string) $b['name_ar'],
            $b['name_en'] ?? null,
            (float) $b['min'],
            (float) $b['max'],
            isset($b['points']) ? (float) $b['points'] : null,
            (bool) $b['passed'],
            (string) ($b['outcome'] ?? ($b['passed'] ? 'pass' : 'fail')),
        );
    }
}
