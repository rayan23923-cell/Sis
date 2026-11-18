<?php

namespace App\AcademicRules\Executors;

use App\AcademicRules\Data\DecisionItem;
use App\AcademicRules\Evaluative;
use App\AcademicRules\Policy;
use App\AcademicRules\RulesException;

/**
 * decision@1 — the Iraqi annual-system "قرار" and second-round entitlement, as in the master sheet.
 *
 * Parameters:
 *   amounts      {"1": marks, "2": extra marks in round 2}   how many marks the decision may give in each round
 *   modes        {"1": "benefit"|"status", "2": ...}        benefit = lift whatever it can; status = only if the student passes everything
 *   carried_first (bool)                                     carried courses take the decision before the others
 *   priorities   ordered list of reduce_failures | lower_coursework | more_units | closest_specialty
 *   pass_mark    (default 50)
 *   resit_deduction (default 10)  a course passed in round 2 (or a carried course) counts mark - 10, never below the pass mark
 *   final_stage_extra (default 0) extra decision marks for final-stage students (critical graduation cases)
 *   no_fail_year (default false)  a "سنة عدم رسوب": a student who would be referred for dismissal repeats the year instead
 *   evaluative   the national evaluative exam rule and its treatment (see App\AcademicRules\Evaluative)
 *
 * A course is lifted to exactly the pass mark when mark + what is left of the decision reaches it. Courses are
 * tried in priority order; a course the remainder cannot lift is skipped and the next one is tried.
 */
final class Decision
{
    public const PRIORITIES = ['reduce_failures', 'lower_coursework', 'more_units', 'closest_specialty'];

    public function __construct(private readonly Policy $policy)
    {
        if ($policy->executor !== 'decision@1') {
            throw new RulesException("Decision cannot run executor {$policy->executor}.");
        }
        foreach ($this->priorities() as $p) {
            if (! in_array($p, self::PRIORITIES, true)) {
                throw new RulesException("Unknown decision priority '{$p}'.");
            }
        }
        foreach ([1, 2] as $r) {
            if (! in_array($this->mode($r), ['benefit', 'status'], true)) {
                throw new RulesException('Decision mode must be benefit or status.');
            }
            if ($this->amount($r) < 0) {
                throw new RulesException('Decision amount cannot be negative.');
            }
        }
        if ($this->finalStageExtra() < 0) {
            throw new RulesException('final_stage_extra cannot be negative.');
        }
        $this->evaluative = new Evaluative($policy);
    }

    public readonly Evaluative $evaluative;

    public function finalStageExtra(): float
    {
        return (float) ($this->policy->parameters['final_stage_extra'] ?? 0);
    }

    public function noFailYear(): bool
    {
        return (bool) ($this->policy->parameters['no_fail_year'] ?? false);
    }

    public function amount(int $round): float
    {
        return (float) ($this->policy->parameters['amounts'][(string) $round] ?? $this->policy->parameters['amounts'][$round] ?? 0);
    }

    public function mode(int $round): string
    {
        return (string) ($this->policy->parameters['modes'][(string) $round] ?? $this->policy->parameters['modes'][$round] ?? 'benefit');
    }

    public function passMark(): float
    {
        return (float) ($this->policy->parameters['pass_mark'] ?? 50);
    }

    public function resitDeduction(): float
    {
        return (float) ($this->policy->parameters['resit_deduction'] ?? 10);
    }

    /** @return list<string> */
    public function priorities(): array
    {
        return array_values($this->policy->parameters['priorities'] ?? ['reduce_failures', 'more_units', 'closest_specialty', 'lower_coursework']);
    }

    /**
     * Decides which failed courses the decision lifts.
     *
     * @param  list<DecisionItem>  $items
     * @param  float  $available  marks of decision available (round 2 adds its amount to what round 1 left)
     * @return array{awards: array<string, float>, used: float} awards: key → marks added
     */
    public function apply(array $items, float $available, string $mode = 'benefit'): array
    {
        $pass = $this->passMark();
        $failed = array_values(array_filter($items, fn (DecisionItem $i) => $i->mark > 0 && $i->mark < $pass));
        $order = $this->order($failed);
        $left = $available;
        $awards = [];
        foreach ($order as $item) {
            if ($item->blocked) {
                continue;
            }
            $need = $pass - $item->mark;
            if ($need <= $left + 1e-9) {
                $awards[$item->key] = round($need, 2);
                $left -= $need;
            }
        }
        // "status" mode: the decision is given only when it clears every failure
        if ($mode === 'status' && count($awards) < count($failed)) {
            return ['awards' => [], 'used' => 0.0];
        }

        return ['awards' => $awards, 'used' => round($available - $left, 2)];
    }

    /**
     * The mark that counts for the average (الاستحقاق). A course passed in round 2 or a carried course loses the
     * deduction, but a pass never drops below the pass mark.
     */
    public function entitlement(float $mark, bool $resitOrCarried): float
    {
        $pass = $this->passMark();
        if (! $resitOrCarried || $mark < $pass) {
            return $mark;
        }

        return max($pass, $mark - $this->resitDeduction());
    }

    /**
     * @param  list<DecisionItem>  $items
     * @return list<DecisionItem>
     */
    private function order(array $items): array
    {
        $carriedFirst = (bool) ($this->policy->parameters['carried_first'] ?? true);
        $priorities = $this->priorities();
        usort($items, function (DecisionItem $a, DecisionItem $b) use ($carriedFirst, $priorities) {
            if ($carriedFirst && $a->carried !== $b->carried) {
                return $a->carried ? -1 : 1;
            }
            foreach ($priorities as $p) {
                $c = match ($p) {
                    'reduce_failures' => $b->mark <=> $a->mark,           // closest to passing first
                    'lower_coursework' => $a->coursework <=> $b->coursework,
                    'more_units' => $b->units <=> $a->units,
                    'closest_specialty' => $a->specialtyRank <=> $b->specialtyRank,
                };
                if ($c !== 0) {
                    return $c;
                }
            }

            return strcmp($a->key, $b->key);
        });

        return $items;
    }
}
