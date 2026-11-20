<?php

namespace Tests\Unit;

use App\Services\PolicyResolver;
use Tests\TestCase;

/** B-02: the resolver lives for one request or one job, never for the whole process. */
class PolicyResolverBindingTest extends TestCase
{
    public function test_resolver_is_shared_within_a_scope_and_replaced_when_the_scope_ends(): void
    {
        $a = app(PolicyResolver::class);
        $this->assertSame($a, app(PolicyResolver::class), 'one request or job uses one resolver, so its results share one policy set');

        app()->forgetScopedInstances(); // what the queue worker does before every job
        $this->assertNotSame($a, app(PolicyResolver::class));
    }
}
