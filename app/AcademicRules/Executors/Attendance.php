<?php

namespace App\AcademicRules\Executors;

use App\AcademicRules\Policy;
use App\AcademicRules\RulesException;

/**
 * attendance@1 — parameters:
 *   count_excused (bool)    excused hours count toward the absence percentage
 *   late_as_absence (0..1)  share of a session's hours counted as absence when the student is late
 *   warnings [{percent, name_ar, name_en}]  thresholds, ascending
 *   limit (number|null)     the percentage beyond which the course is lost; null = warn only
 */
final class Attendance
{
    public function __construct(private readonly Policy $policy)
    {
        if ($policy->executor !== 'attendance@1') {
            throw new RulesException("Attendance cannot run executor {$policy->executor}.");
        }
        $late = $policy->parameters['late_as_absence'] ?? 0;
        if (! is_numeric($late) || $late < 0 || $late > 1) {
            throw new RulesException('late_as_absence must be between 0 and 1.');
        }
        foreach ($this->warnings() as $w) {
            if (! is_numeric($w['percent'] ?? null) || empty($w['name_ar'])) {
                throw new RulesException('Each warning needs a percent and a name_ar.');
            }
        }
        $limit = $policy->parameters['limit'] ?? null;
        if ($limit !== null && (! is_numeric($limit) || $limit <= 0 || $limit > 100)) {
            throw new RulesException('limit must be null or a percentage.');
        }
    }

    /** Hours of one record that count as absence. */
    public function absenceHours(string $status, float $hours): float
    {
        return match ($status) {
            'absent' => $hours,
            'excused' => ($this->policy->parameters['count_excused'] ?? false) ? $hours : 0.0,
            'late' => $hours * (float) ($this->policy->parameters['late_as_absence'] ?? 0),
            default => 0.0,
        };
    }

    /** @return array{level: int, name_ar: ?string, name_en: ?string, over_limit: bool} */
    public function assess(float $percent): array
    {
        $level = 0;
        $name = $nameEn = null;
        foreach ($this->warnings() as $i => $w) {
            if ($percent >= (float) $w['percent']) {
                $level = $i + 1;
                $name = $w['name_ar'];
                $nameEn = $w['name_en'] ?? null;
            }
        }
        $limit = $this->policy->parameters['limit'] ?? null;

        return ['level' => $level, 'name_ar' => $name, 'name_en' => $nameEn, 'over_limit' => $limit !== null && $percent > (float) $limit];
    }

    public function limit(): ?float
    {
        $l = $this->policy->parameters['limit'] ?? null;

        return $l === null ? null : (float) $l;
    }

    /** @return list<array{percent: float|int, name_ar: string, name_en?: string}> */
    public function warnings(): array
    {
        $w = $this->policy->parameters['warnings'] ?? [];
        if (! is_array($w)) {
            throw new RulesException('warnings must be a list.');
        }
        usort($w, fn ($a, $b) => ($a['percent'] ?? 0) <=> ($b['percent'] ?? 0));

        return array_values($w);
    }
}
