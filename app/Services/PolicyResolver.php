<?php

namespace App\Services;

use App\AcademicRules\PolicySet;
use App\Models\AcademicPolicy;
use App\Models\Program;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Picks the policies that apply to a program on a date (ADR-004): approved, in force on that date,
 * for the program's study system (or "any"), most specific scope first (program → college →
 * university), and the highest version within that scope.
 */
class PolicyResolver
{
    /** @var array<string, PolicySet> */
    private array $cache = [];

    public function forProgram(Program $program, ?Carbon $on = null): PolicySet
    {
        $on ??= now();
        $key = $program->id.'@'.$on->toDateString();

        return $this->cache[$key] ??= new PolicySet($this->candidates($program, $on)->map->toRule());
    }

    /** @return Collection<int, AcademicPolicy> one policy per type */
    public function candidates(Program $program, Carbon $on): Collection
    {
        $collegeId = $program->collegeId();
        $rank = fn (AcademicPolicy $p) => match ($p->scope_type) {
            'program' => 3,
            'college' => 2,
            default => 1,
        };

        return AcademicPolicy::query()
            ->where('status', 'approved')
            ->whereIn('study_system', [$program->study_system, 'any'])
            ->whereDate('effective_from', '<=', $on)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $on))
            ->where(fn ($q) => $q->where('scope_type', 'university')
                ->orWhere(fn ($c) => $c->where('scope_type', 'college')->where('scope_id', $collegeId))
                ->orWhere(fn ($c) => $c->where('scope_type', 'program')->where('scope_id', $program->id)))
            ->get()
            ->groupBy('type')
            ->map(fn (Collection $group) => $group
                ->sortByDesc(fn (AcademicPolicy $p) => sprintf('%d-%d-%06d', $rank($p), $p->study_system === 'any' ? 0 : 1, $p->version))
                ->first())
            ->values();
    }
}
