<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use League\OpenAPIValidation\PSR7\Exception\ValidationFailed;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ResponseValidator;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Checks API responses against docs/openapi.yaml.
 *
 * TestCase runs every /api response through this, so the spec is enforced by
 * the whole feature suite rather than by a handful of dedicated tests.
 */
final class OpenApi
{
    public const string SPEC = __DIR__.'/../docs/openapi.yaml';

    private static ?ResponseValidator $validator = null;

    public static function assertResponseMatches(Request $request, Response $response): void
    {
        $route = $request->route();

        // Unrouted 404s and 405s have no operation to check against.
        if (! $route instanceof Route || ! str_starts_with($route->uri(), 'api/')) {
            return;
        }

        $operation = new OperationAddress(self::specPath($route), strtolower($request->getMethod()));
        $factory = new Psr17Factory;
        $psr = (new PsrHttpFactory($factory, $factory, $factory, $factory))->createResponse($response);

        try {
            self::validator()->validate($operation, $psr);
        } catch (ValidationFailed $e) {
            throw new AssertionFailedError(sprintf(
                "%s %s returned %d, which doesn't match docs/openapi.yaml:\n%s\n\nBody: %s",
                $request->getMethod(),
                $request->getPathInfo(),
                $response->getStatusCode(),
                self::reasons($e),
                $response->getContent(),
            ));
        }
    }

    /**
     * The spec's path for a route: 'api/invoices/{invoice}' becomes '/invoices/{invoice}'.
     */
    public static function specPath(Route $route): string
    {
        return '/'.ltrim(substr($route->uri(), strlen('api')), '/');
    }

    private static function validator(): ResponseValidator
    {
        return self::$validator ??= (new ValidatorBuilder)->fromYamlFile(self::SPEC)->getResponseValidator();
    }

    private static function reasons(Throwable $e): string
    {
        $reasons = [];

        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $reasons[] = '  - '.$current->getMessage();
        }

        return implode("\n", $reasons);
    }
}
