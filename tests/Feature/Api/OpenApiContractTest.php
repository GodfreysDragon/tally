<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\Yaml\Yaml;
use Tests\OpenApi;

/**
 * @return list<string> e.g. "post /invoices/{invoice}/send"
 */
function specOperations(): array
{
    /** @var array{paths: array<string, array<string, mixed>>} $spec */
    $spec = Yaml::parseFile(OpenApi::SPEC);
    $operations = [];

    foreach ($spec['paths'] as $path => $item) {
        foreach (array_keys($item) as $method) {
            if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                $operations[] = "{$method} {$path}";
            }
        }
    }

    sort($operations);

    return $operations;
}

it('documents every API route, and nothing else', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route) => str_starts_with($route->uri(), 'api/'))
        ->flatMap(fn (RoutingRoute $route) => collect($route->methods())
            ->reject(fn (string $method) => $method === 'HEAD')
            ->map(fn (string $method) => strtolower($method).' '.OpenApi::specPath($route)))
        ->sort()
        ->values()
        ->all();

    expect($routes)->toBe(specOperations());
});

it('rejects a response that breaks the contract', function () {
    $request = Request::create('/api/customers/1');
    $request->setRouteResolver(fn () => Route::getRoutes()->match($request));

    OpenApi::assertResponseMatches($request, new JsonResponse(['id' => 'not-an-integer']));
})->throws(AssertionFailedError::class, "GET /api/customers/1 returned 200, which doesn't match docs/openapi.yaml");

it('ignores routes outside the API', function () {
    $request = Request::create('/up');
    $request->setRouteResolver(fn () => Route::getRoutes()->match($request));

    OpenApi::assertResponseMatches($request, new JsonResponse(['anything' => true]));

    expect(true)->toBeTrue();
});
