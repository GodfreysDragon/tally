<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Notification::fake();
    Queue::fake();

    $this->seed(DatabaseSeeder::class);
    $this->user = User::query()->where('email', DatabaseSeeder::EMAIL)->sole();
});

it('seeds a demo account with an invoice in every state', function () {
    $invoices = $this->user->invoices()->get();

    expect($this->user->customers()->count())->toBe(3)
        ->and($invoices->countBy(fn (Invoice $invoice) => $invoice->status->value)->sortKeys()->all())
        ->toBe(['draft' => 1, 'paid' => 1, 'sent' => 3, 'void' => 1])
        ->and($invoices->filter->isOverdue())->toHaveCount(1)
        ->and($invoices->filter(fn (Invoice $invoice) => $invoice->status === InvoiceStatus::Sent && $invoice->amount_paid > 0))->toHaveCount(1);
});

it('numbers sent invoices without gaps', function () {
    $numbers = $this->user->invoices()->whereNotNull('number')->orderBy('number')->pluck('number')->all();

    expect($numbers)->toBe(array_map(
        fn (int $n) => sprintf('INV-%d-%04d', now()->year, $n),
        range(1, 5),
    ));
});

it('lets you log in with the documented credentials', function () {
    $this->postJson('/api/tokens', [
        'email' => DatabaseSeeder::EMAIL,
        'password' => DatabaseSeeder::PASSWORD,
        'device_name' => 'demo',
    ])->assertCreated();
});
