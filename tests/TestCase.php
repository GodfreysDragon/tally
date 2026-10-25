<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
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
     * Every API response a test receives must match the OpenAPI spec.
     *
     * @param  \Symfony\Component\HttpFoundation\Response  $response
     * @param  \Illuminate\Http\Request  $request
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function createTestResponse($response, $request): TestResponse
    {
        OpenApi::assertResponseMatches($request, $response);

        return parent::createTestResponse($response, $request);
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
