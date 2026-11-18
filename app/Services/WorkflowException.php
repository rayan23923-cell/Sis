<?php

namespace App\Services;

use RuntimeException;

/** A business rule stopped the action. The message is shown to the user as is (Arabic). */
class WorkflowException extends RuntimeException
{
    /** @var array<int, string> */
    public array $problems = [];

    /** @param  array<int, string>  $problems  details listed under the message (first ones shown) */
    public function withProblems(array $problems): static
    {
        $this->problems = array_values($problems);

        return $this;
    }
}
