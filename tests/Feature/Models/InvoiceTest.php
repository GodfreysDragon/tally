<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Models\Invoice;

mutates(Invoice::class);

it('works out the balance', function () {
    $invoice = Invoice::factory()->make(['total' => 12_000, 'amount_paid' => 4_500, 'currency' => 'EUR']);

    expect($invoice->balance())->toBeMoney(7_500, 'EUR')
        ->and($invoice->totalMoney())->toBeMoney(12_000, 'EUR');
});

it('owes nothing once voided', function () {
    $invoice = Invoice::factory()->make(['status' => InvoiceStatus::Void, 'total' => 12_000, 'amount_paid' => 0, 'currency' => 'EUR']);

    expect($invoice->balance())->toBeMoney(0, 'EUR')
        ->and($invoice->totalMoney())->toBeMoney(12_000, 'EUR');
});

it('knows when it is overdue', function (InvoiceStatus $status, ?string $dueAt, bool $overdue) {
    $this->travelTo('2026-10-05 15:00:00');

    $invoice = Invoice::factory()->make(['status' => $status, 'due_at' => $dueAt]);

    expect($invoice->isOverdue())->toBe($overdue);
})->with([
    'sent, due yesterday' => [InvoiceStatus::Sent, '2026-10-04', true],
    'sent, due today' => [InvoiceStatus::Sent, '2026-10-05', false],
    'sent, due tomorrow' => [InvoiceStatus::Sent, '2026-10-06', false],
    'sent, no due date' => [InvoiceStatus::Sent, null, false],
    'draft, long past due' => [InvoiceStatus::Draft, '2026-01-01', false],
    'paid, long past due' => [InvoiceStatus::Paid, '2026-01-01', false],
    'void, long past due' => [InvoiceStatus::Void, '2026-01-01', false],
]);

it('accepts an explicit clock for overdue checks', function () {
    $invoice = Invoice::factory()->make(['status' => InvoiceStatus::Sent, 'due_at' => '2026-10-05']);

    expect($invoice->isOverdue(now()->setDate(2026, 10, 6)))->toBeTrue()
        ->and($invoice->isOverdue(now()->setDate(2026, 10, 5)))->toBeFalse();
});

it('scopes overdue invoices in SQL the same way', function () {
    $this->travelTo('2026-10-05 15:00:00');
    $late = Invoice::factory()->sent()->create(['due_at' => '2026-10-04']);
    Invoice::factory()->sent()->create(['due_at' => '2026-10-05']);
    Invoice::factory()->draft()->create(['due_at' => '2026-10-01']);

    expect(Invoice::query()->overdue()->pluck('id')->all())->toBe([$late->id]);
});

it('orders lines by position', function () {
    $invoice = Invoice::factory()->create();
    $invoice->lines()->delete();
    $invoice->lines()->createMany([
        ['description' => 'Second', 'quantity' => 1, 'unit_price' => 1, 'amount' => 1, 'position' => 1],
        ['description' => 'First', 'quantity' => 1, 'unit_price' => 1, 'amount' => 1, 'position' => 0],
    ]);

    expect($invoice->lines()->pluck('description')->all())->toBe(['First', 'Second']);
});

it('belongs to a user and a customer', function () {
    $invoice = Invoice::factory()->create();

    expect($invoice->user->is($invoice->customer->user))->toBeTrue()
        ->and($invoice->customer->invoices()->sole()->is($invoice))->toBeTrue()
        ->and($invoice->user->invoices()->sole()->is($invoice))->toBeTrue()
        ->and($invoice->lines()->sole()->invoice->is($invoice))->toBeTrue()
        ->and($invoice->payments()->create(['amount' => 1, 'received_at' => now()])->invoice->is($invoice))->toBeTrue();
});

it('casts raw column values to their domain types', function () {
    $invoice = (new Invoice)->setRawAttributes([
        'status' => 'sent',
        'tax_rate' => '2000',
        'subtotal' => '10000',
        'tax_total' => '2000',
        'total' => '12000',
        'amount_paid' => '500',
        'due_at' => '2026-10-31',
        'sent_at' => '2026-10-01 09:00:00',
        'paid_at' => '2026-10-02 09:00:00',
        'voided_at' => '2026-10-03 09:00:00',
        'last_reminded_at' => '2026-10-04 09:00:00',
    ]);

    expect($invoice->status)->toBe(InvoiceStatus::Sent)
        ->and([$invoice->tax_rate, $invoice->subtotal, $invoice->tax_total, $invoice->total, $invoice->amount_paid])
        ->toBe([2_000, 10_000, 2_000, 12_000, 500])
        ->and($invoice->due_at?->toDateTimeString())->toBe('2026-10-31 00:00:00')
        ->and($invoice->sent_at?->toDateTimeString())->toBe('2026-10-01 09:00:00')
        ->and($invoice->paid_at?->toDateTimeString())->toBe('2026-10-02 09:00:00')
        ->and($invoice->voided_at?->toDateTimeString())->toBe('2026-10-03 09:00:00')
        ->and($invoice->last_reminded_at?->toDateTimeString())->toBe('2026-10-04 09:00:00');
});
