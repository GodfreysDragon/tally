<?php

declare(strict_types=1);

use App\Actions\RecordPayment;
use App\Models\Invoice;
use Illuminate\Support\Facades\Queue;

mutates(RecordPayment::class);

beforeEach(fn () => Queue::fake());

it('refuses non-positive amounts before touching the database', function (int $amount) {
    $invoice = Invoice::factory()->sent()->create();

    app(RecordPayment::class)->handle($invoice, $amount);
})->throws(InvalidArgumentException::class, 'A payment must be greater than zero.')->with([0, -1]);

it('uses the given received date', function () {
    $invoice = Invoice::factory()->sent()->create();

    $payment = app(RecordPayment::class)->handle($invoice, 100, 'CHQ-9', now()->subDays(2)->startOfDay());

    expect($payment->received_at->equalTo(now()->subDays(2)->startOfDay()))->toBeTrue()
        ->and($payment->reference)->toBe('CHQ-9');
});

it('reads the balance from the locked row, not a stale model', function () {
    $invoice = Invoice::factory()->sent()->create(['total' => 10_000]);
    $stale = Invoice::query()->findOrFail($invoice->id);

    app(RecordPayment::class)->handle($invoice, 6_000);

    // $stale still believes the full 10,000 is outstanding.
    expect(fn () => app(RecordPayment::class)->handle($stale, 6_000))
        ->toThrow(App\Exceptions\InvoiceStateException::class, 'exceeds the outstanding balance of GBP 40.00');
});
