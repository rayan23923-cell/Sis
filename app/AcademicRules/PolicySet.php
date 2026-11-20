<?php

namespace App\AcademicRules;

/**
 * The policies that apply to one computation, keyed by type (grade_scale, rounding, total, gpa...).
 */
final class PolicySet
{
    /** @var array<string, Policy> */
    private array $policies = [];

    /** @param iterable<Policy> $policies */
    public function __construct(iterable $policies = [])
    {
        foreach ($policies as $policy) {
            $this->policies[$policy->type] = $policy;
        }
    }

    public function has(string $type): bool
    {
        return isset($this->policies[$type]);
    }

    public function get(string $type): Policy
    {
        return $this->policies[$type] ?? throw new RulesException("No approved '{$type}' policy applies here.");
    }

    public function allOfficial(): bool
    {
        foreach ($this->policies as $policy) {
            if (! $policy->isOfficial) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, array<string, mixed>> */
    public function snapshot(): array
    {
        $out = [];
        foreach ($this->policies as $type => $policy) {
            $out[$type] = $policy->snapshot();
        }
        ksort($out);

        return $out;
    }

    /** @param array<string, array<string, mixed>> $snapshot */
    public static function fromSnapshot(array $snapshot): self
    {
        return new self(array_map(fn (array $s) => Policy::fromSnapshot($s), array_values($snapshot)));
    }

    /**
     * The policies a stored course result was computed with (`final_results.policy_snapshot`).
     * That snapshot also holds computed details (the evaluative block), so only the entries that are
     * policy snapshots are taken, plus the decision policy nested in the evaluative block.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function fromResultSnapshot(array $snapshot): self
    {
        $isPolicy = fn ($s) => is_array($s) && isset($s['type'], $s['executor'], $s['version']);
        $policies = array_filter($snapshot, $isPolicy);
        $decision = $snapshot['evaluative']['decision'] ?? null;
        if ($isPolicy($decision) && ! isset($policies['decision'])) {
            $policies['decision'] = $decision;
        }

        return new self(array_map(fn (array $s) => Policy::fromSnapshot($s), array_values($policies)));
    }
}
