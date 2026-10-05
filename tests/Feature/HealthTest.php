<?php

declare(strict_types=1);

it('reports healthy at /up', function () {
    $this->get('/up')->assertOk();
});

it('describes itself at the root', function () {
    $this->getJson('/')
        ->assertOk()
        ->assertJson(['name' => config('app.name'), 'health' => url('/up')]);
});

it('renders API errors as JSON even without an Accept header', function () {
    $this->get('/api/invoices')->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);
});
