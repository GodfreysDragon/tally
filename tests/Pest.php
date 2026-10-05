<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test case binding
|--------------------------------------------------------------------------
|
| Unit tests run against plain PHPUnit: no container, no database, no I/O.
| If a unit test needs the framework, it belongs in Feature.
|
*/

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeMoney', function (int $amount, string $currency = 'GBP') {
    return $this->toBeInstanceOf(Money::class)
        ->amount->toBe($amount)
        ->currency->toBe($currency);
});

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function actingAsUser(?User $user = null): User
{
    $user ??= User::factory()->create();

    Sanctum::actingAs($user);

    return $user;
}
