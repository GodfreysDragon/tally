<?php

declare(strict_types=1);

use App\Models\Customer;

mutates(App\Http\Controllers\Api\CustomerController::class, App\Http\Requests\StoreCustomerRequest::class, App\Http\Resources\CustomerResource::class, App\Policies\CustomerPolicy::class);

it('lists only the caller\'s customers, alphabetically', function () {
    $user = actingAsUser();
    Customer::factory()->for($user)->create(['name' => 'Zeta Ltd']);
    Customer::factory()->for($user)->create(['name' => 'Acme Ltd']);
    Customer::factory()->create(['name' => 'Someone Else Ltd']);

    $this->getJson('/api/customers')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Acme Ltd')
        ->assertJsonPath('data.1.name', 'Zeta Ltd')
        ->assertJsonPath('meta.total', 2);
});

it('creates a customer', function () {
    $user = actingAsUser();

    $this->postJson('/api/customers', [
        'name' => 'Acme Ltd',
        'email' => 'accounts@acme.test',
        'currency' => 'EUR',
        'webhook_url' => 'https://acme.test/hooks',
        'webhook_secret' => 'whsec_0123456789abcdef',
    ])
        ->assertCreated()
        ->assertJsonPath('name', 'Acme Ltd')
        ->assertJsonPath('currency', 'EUR')
        ->assertJsonMissingPath('webhook_secret');

    $customer = $user->customers()->sole();

    expect($customer->webhook_secret)->toBe('whsec_0123456789abcdef');
    $this->assertDatabaseMissing('customers', ['webhook_secret' => 'whsec_0123456789abcdef']); // Encrypted at rest.
});

it('validates new customers', function (array $payload, array|string $errors) {
    actingAsUser();

    $this->postJson('/api/customers', [
        'name' => 'Acme Ltd',
        'email' => 'accounts@acme.test',
        'currency' => 'GBP',
        ...$payload,
    ])->assertUnprocessable()->assertJsonValidationErrors($errors);
})->with([
    'missing name' => [['name' => ''], 'name'],
    'bad email' => [['email' => 'not-an-email'], 'email'],
    'unsupported currency' => [['currency' => 'JPY'], 'currency'],
    'lowercase currency' => [['currency' => 'gbp'], 'currency'],
    'plain-http webhook' => [['webhook_url' => 'http://acme.test/hooks', 'webhook_secret' => str_repeat('s', 16)], 'webhook_url'],
    'webhook without secret' => [['webhook_url' => 'https://acme.test/hooks'], 'webhook_secret'],
    'secret without webhook' => [['webhook_secret' => str_repeat('s', 16)], 'webhook_url'],
    'short secret' => [['webhook_url' => 'https://acme.test/hooks', 'webhook_secret' => 'short'], 'webhook_secret'],
]);

it('lets two users each have a customer with the same email', function () {
    Customer::factory()->create(['email' => 'shared@example.test']);
    actingAsUser();

    $this->postJson('/api/customers', ['name' => 'Shared', 'email' => 'shared@example.test', 'currency' => 'GBP'])
        ->assertCreated();
});

it('rejects a duplicate email for the same user', function () {
    $user = actingAsUser();
    Customer::factory()->for($user)->create(['email' => 'dup@example.test']);

    $this->postJson('/api/customers', ['name' => 'Dup', 'email' => 'dup@example.test', 'currency' => 'GBP'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

it('shows a customer to its owner only', function () {
    $user = actingAsUser();
    $mine = Customer::factory()->for($user)->create();
    $theirs = Customer::factory()->create();

    $this->getJson("/api/customers/{$mine->id}")->assertOk()->assertJsonPath('id', $mine->id);
    $this->getJson("/api/customers/{$theirs->id}")->assertForbidden();
});
