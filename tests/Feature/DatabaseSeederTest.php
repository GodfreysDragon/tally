<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Queue;

// Only the queue is faked: if the seeder let a notification through, the queued job would show up here.
beforeEach(fn () => Queue::fake());

function demoUser(): User
{
    return User::query()->where('email', DatabaseSeeder::EMAIL)->sole();
}

it('seeds a demo account with an invoice in every state', function () {
    $this->seed(DatabaseSeeder::class);
    $invoices = demoUser()->invoices()->get();

    expect(demoUser()->customers()->count())->toBe(3)
        ->and($invoices->countBy(fn (Invoice $invoice) => $invoice->status->value)->sortKeys()->all())
        ->toBe(['draft' => 1, 'paid' => 1, 'sent' => 3, 'void' => 1])
        ->and($invoices->filter->isOverdue())->toHaveCount(1)
        ->and($invoices->filter(fn (Invoice $invoice) => $invoice->status === InvoiceStatus::Sent && $invoice->amount_paid > 0))->toHaveCount(1);
});

it('numbers sent invoices without gaps', function () {
    $this->seed(DatabaseSeeder::class);
    $numbers = demoUser()->invoices()->whereNotNull('number')->orderBy('number')->pluck('number')->all();

    expect($numbers)->toBe(array_map(
        fn (int $n) => sprintf('INV-%d-%04d', now()->year, $n),
        range(1, 5),
    ));
});

it('never records a payment before its invoice was sent', function () {
    $this->seed(DatabaseSeeder::class);

    foreach (demoUser()->invoices()->with('payments')->get() as $invoice) {
        foreach ($invoice->payments as $payment) {
            expect($payment->received_at->greaterThanOrEqualTo($invoice->sent_at))->toBeTrue();
        }
    }
});

it('lets you log in with the documented credentials', function () {
    $this->seed(DatabaseSeeder::class);

    $this->postJson('/api/tokens', [
        'email' => DatabaseSeeder::EMAIL,
        'password' => DatabaseSeeder::PASSWORD,
        'device_name' => 'demo',
    ])->assertCreated();
});

it('emails nobody', function () {
    $this->seed(DatabaseSeeder::class);

    Queue::assertNothingPushed();
});

it('can be run twice', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(User::query()->where('email', DatabaseSeeder::EMAIL)->count())->toBe(1)
        ->and(Invoice::query()->count())->toBe(6);
});

// db:seed asks for confirmation in production, but --force skips that; this is the last line of defence.
it('refuses to run in production, even when forced', function () {
    app()->detectEnvironment(fn () => 'production');

    try {
        app(DatabaseSeeder::class)->run();
    } finally {
        expect(User::query()->count())->toBe(0);
    }
})->throws(RuntimeException::class, 'The demo seeder must not run in production.');
