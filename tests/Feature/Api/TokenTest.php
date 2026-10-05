<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

mutates(App\Http\Controllers\Api\TokenController::class);

beforeEach(function () {
    $this->user = User::factory()->create(['email' => 'owner@example.test']);
});

it('issues a token for valid credentials', function () {
    $token = $this->postJson('/api/tokens', [
        'email' => 'owner@example.test',
        'password' => 'password',
        'device_name' => 'ci',
    ])->assertCreated()->json('token');

    expect($token)->toBeString()->not->toBeEmpty()
        ->and($this->user->tokens()->sole()->name)->toBe('ci');

    $this->withToken($token)->getJson('/api/customers')->assertOk();
});

it('refuses wrong credentials without saying which part was wrong', function (array $credentials) {
    $this->postJson('/api/tokens', [...$credentials, 'device_name' => 'ci'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email' => __('auth.failed')]);
})->with([
    'wrong password' => [['email' => 'owner@example.test', 'password' => 'nope']],
    'unknown email' => [['email' => 'nobody@example.test', 'password' => 'password']],
]);

it('validates the request', function () {
    $this->postJson('/api/tokens', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password', 'device_name']);
});

it('throttles brute-force attempts', function () {
    RateLimiter::clear('tokens');

    foreach (range(1, 6) as $_) {
        $this->postJson('/api/tokens', ['email' => 'owner@example.test', 'password' => 'nope', 'device_name' => 'ci']);
    }

    $this->postJson('/api/tokens', ['email' => 'owner@example.test', 'password' => 'password', 'device_name' => 'ci'])
        ->assertTooManyRequests();
});

it('rejects unauthenticated API calls', function (string $method, string $uri) {
    $this->json($method, $uri)->assertUnauthorized();
})->with([
    ['GET', '/api/customers'],
    ['POST', '/api/customers'],
    ['GET', '/api/invoices'],
    ['POST', '/api/invoices'],
    ['POST', '/api/invoices/1/send'],
    ['POST', '/api/invoices/1/void'],
    ['POST', '/api/invoices/1/payments'],
]);
