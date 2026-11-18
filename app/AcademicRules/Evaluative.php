<?php

namespace App\AcademicRules;

/**
 * The national evaluative exam (الامتحان التقويمي), read from the "evaluative" block of the decision policy.
 *
 * A course the ministry includes in the evaluative exam fails when its final-exam mark is below the threshold,
 * whatever the total: the grade is ضعيف and the ordinary decision (قرار) may not lift it. The ministry may then
 * allow a separate treatment of a few marks per evaluative course, added to the evaluative exam mark only.
 *
 * Parameters (all under decision.parameters.evaluative):
 *   fail_below   (20)    the final-exam mark below which the course fails …
 *   out_of       (60)    … out of a final exam weighted this much (scaled to the course's real final weight)
 *   treatment    (0)     marks of treatment per evaluative course
 *   treatment_scale ("weight")  weight = the marks are out of 100 and scaled to the final's weight; mark = as given
 *   final_stage_bonus (0)       extra treatment marks per course for final-stage students
 *   excluded_colleges []        college codes the treatment does not apply to (e.g. medicine)
 */
final class Evaluative
{
    /** @var array<string, mixed> */
    private array $p;

    public function __construct(?Policy $decision)
    {
        $this->p = (array) ($decision?->parameters['evaluative'] ?? []);
        if ($this->outOf() <= 0 || $this->failBelow() < 0 || $this->treatment() < 0 || $this->finalStageBonus() < 0) {
            throw new RulesException('Evaluative exam parameters must be non-negative (out_of above zero).');
        }
        if (! in_array($this->treatmentScale(), ['weight', 'mark'], true)) {
            throw new RulesException('Evaluative treatment_scale must be weight or mark.');
        }
    }

    public function failBelow(): float
    {
        return (float) ($this->p['fail_below'] ?? 20);
    }

    public function outOf(): float
    {
        return (float) ($this->p['out_of'] ?? 60);
    }

    public function treatment(): float
    {
        return (float) ($this->p['treatment'] ?? 0);
    }

    public function treatmentScale(): string
    {
        return (string) ($this->p['treatment_scale'] ?? 'weight');
    }

    public function finalStageBonus(): float
    {
        return (float) ($this->p['final_stage_bonus'] ?? 0);
    }

    /** @return list<string> */
    public function excludedColleges(): array
    {
        return array_values(array_map('strval', (array) ($this->p['excluded_colleges'] ?? [])));
    }

    /** The threshold in the course's own points, for a final exam of this weight. */
    public function threshold(float $finalWeight): float
    {
        return round($this->failBelow() * $finalWeight / $this->outOf(), 2);
    }

    public function fails(?float $finalPoints, float $finalWeight): bool
    {
        return $finalPoints !== null && $finalWeight > 0 && $finalPoints + 1e-9 < $this->threshold($finalWeight);
    }

    /** Treatment marks available for one course, in the course's points. */
    public function allowance(float $finalWeight, bool $finalStage, ?string $collegeCode): float
    {
        if ($collegeCode !== null && in_array($collegeCode, $this->excludedColleges(), true)) {
            return 0.0;
        }
        $marks = $this->treatment() + ($finalStage ? $this->finalStageBonus() : 0);

        return round($this->treatmentScale() === 'weight' ? $marks * $finalWeight / 100 : $marks, 2);
    }

    /**
     * @return array{failed: bool, treated: float, threshold: float} failed = still fails after the treatment;
     *                                                               treated = marks added to the exam mark
     */
    public function assess(?float $finalPoints, float $finalWeight, bool $finalStage, ?string $collegeCode): array
    {
        $threshold = $this->threshold($finalWeight);
        if (! $this->fails($finalPoints, $finalWeight)) {
            return ['failed' => false, 'treated' => 0.0, 'threshold' => $threshold];
        }
        $need = round($threshold - $finalPoints, 2);
        if ($need <= $this->allowance($finalWeight, $finalStage, $collegeCode) + 1e-9) {
            return ['failed' => false, 'treated' => $need, 'threshold' => $threshold];
        }

        return ['failed' => true, 'treated' => 0.0, 'threshold' => $threshold];
    }
}
