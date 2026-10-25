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
    'missing name' => [['name' => ''], ['name' => 'required']],
    'non-string name' => [['name' => 123], ['name' => 'string']],
    'name too long' => [['name' => str_repeat('n', 256)], ['name' => '255']],
    'missing email' => [['email' => ''], ['email' => 'required']],
    'email too long' => [['email' => str_repeat('e', 244).'@example.test'], ['email' => '255']],
    'missing currency' => [['currency' => ''], ['currency' => 'required']],
    'webhook URL too long' => [['webhook_url' => 'https://acme.test/'.str_repeat('h', 2_031), 'webhook_secret' => str_repeat('s', 16)], ['webhook_url' => '2048']],
    'non-string secret' => [['webhook_url' => 'https://acme.test/hooks', 'webhook_secret' => ['s']], ['webhook_secret' => 'string']],
    'secret too long' => [['webhook_url' => 'https://acme.test/hooks', 'webhook_secret' => str_repeat('s', 256)], ['webhook_secret' => '255']],
    'bad email' => [['email' => 'not-an-email'], 'email'],
    'unsupported currency' => [['currency' => 'JPY'], 'currency'],
    'lowercase currency' => [['currency' => 'gbp'], 'currency'],
    'plain-http webhook' => [['webhook_url' => 'http://acme.test/hooks', 'webhook_secret' => str_repeat('s', 16)], 'webhook_url'],
    'webhook without secret' => [['webhook_url' => 'https://acme.test/hooks'], 'webhook_secret'],
    'secret without webhook' => [['webhook_secret' => str_repeat('s', 16)], 'webhook_url'],
    'short secret' => [['webhook_url' => 'https://acme.test/hooks', 'webhook_secret' => 'short'], 'webhook_secret'],
]);

it('accepts every supported currency and explicit nulls for the webhook', function (string $currency) {
    actingAsUser();

    $this->postJson('/api/customers', [
        'name' => str_repeat('n', 255),
        'email' => 'accounts@acme.test',
        'currency' => $currency,
        'webhook_url' => null,
        'webhook_secret' => null,
    ])->assertCreated()->assertJsonPath('currency', $currency)->assertJsonPath('webhook_url', null);
})->with(['GBP', 'EUR', 'USD']);

it('renders every public field and nothing secret', function () {
    $this->freezeTime();
    $user = actingAsUser();
    $customer = Customer::factory()->for($user)->withWebhook('https://acme.test/hooks')->create([
        'name' => 'Acme Ltd',
        'email' => 'accounts@acme.test',
        'currency' => 'USD',
    ]);

    $this->getJson("/api/customers/{$customer->id}")
        ->assertOk()
        ->assertExactJson([
            'id' => $customer->id,
            'name' => 'Acme Ltd',
            'email' => 'accounts@acme.test',
            'currency' => 'USD',
            'webhook_url' => 'https://acme.test/hooks',
            'created_at' => now()->toIso8601String(),
        ]);
});

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
