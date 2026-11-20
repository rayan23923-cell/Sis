<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * In production every HTTP request runs in a fresh application (PHP-FPM), and the queue worker forgets scoped
     * instances before every job. A test keeps one application for all its requests, so each simulated request
     * starts its scope here, exactly as the worker does. Singletons are NOT reset: a service wrongly registered as
     * a singleton still leaks between requests in tests, as it would in a worker (see PolicyResolverBindingTest).
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app->forgetScopedInstances();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
