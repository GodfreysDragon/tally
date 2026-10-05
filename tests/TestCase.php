<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->refuseToRunAgainstARealDatabase();

        // Any HTTP call a test did not explicitly fake is a bug, not a network request.
        Http::preventStrayRequests();
    }

    /**
     * RefreshDatabase drops every table. If the environment points the suite at
     * a database that is not obviously a test one, stop before it does.
     */
    private function refuseToRunAgainstARealDatabase(): void
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');

        if ($database !== ':memory:' && ! str_contains($database, 'test')) {
            throw new RuntimeException("Refusing to run tests against database [{$database}]. Use one whose name contains \"test\".");
        }
    }
}
