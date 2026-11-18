<?php

namespace Tests\Unit\AcademicRules;

use App\AcademicRules\Defaults;
use App\AcademicRules\Policy;
use App\AcademicRules\PolicySet;
use PHPUnit\Framework\TestCase;

abstract class RulesTestCase extends TestCase
{
    protected function defaults(string $system): PolicySet
    {
        $policies = [];
        foreach (Defaults::policies() as $i => $p) {
            if ($p['study_system'] === $system) {
                $policies[] = new Policy($i + 1, $p['type'], 1, $p['executor'], $p['parameters'], false);
            }
        }

        return new PolicySet($policies);
    }

    protected function policy(string $type, string $executor, array $parameters, bool $official = true): Policy
    {
        return new Policy(99, $type, 1, $executor, $parameters, $official);
    }
}
