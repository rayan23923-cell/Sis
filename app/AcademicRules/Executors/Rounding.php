<?php

namespace App\AcademicRules\Executors;

use App\AcademicRules\Policy;
use App\AcademicRules\RulesException;

/**
 * rounding@1 — parameters: mode (half_up | ceil | floor | none), precision (decimal places).
 * Works on strings internally (bcmath-free) to avoid binary float surprises such as 49.5 → 49.4999.
 */
final class Rounding
{
    public function __construct(private readonly Policy $policy)
    {
        if ($policy->executor !== 'rounding@1') {
            throw new RulesException("Rounding cannot run executor {$policy->executor}.");
        }
    }

    public function apply(float $value): float
    {
        $mode = $this->policy->parameters['mode'] ?? 'half_up';
        $precision = (int) ($this->policy->parameters['precision'] ?? 0);
        $factor = 10 ** $precision;
        // Snap to 6 decimals first so 49.4999999997 (a float artefact of 49.5) is treated as 49.5.
        $scaled = round($value * $factor, 6);

        $result = match ($mode) {
            'half_up' => floor($scaled + 0.5),
            'ceil' => ceil($scaled),
            'floor' => floor($scaled),
            'none' => $scaled,
            default => throw new RulesException("Unknown rounding mode '{$mode}'."),
        };

        return $result / $factor;
    }
}
