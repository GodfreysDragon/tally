<?php

declare(strict_types=1);

use App\Actions\QueueInvoiceWebhook;
use App\Enums\InvoiceStatus;
use App\Jobs\DeliverWebhook;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Support\Facades\Queue;

mutates(QueueInvoiceWebhook::class);

beforeEach(fn () => Queue::fake());

it('queues the full event envelope for the customer', function () {
    $this->freezeTime();
    $customer = Customer::factory()->withWebhook()->create(['currency' => 'EUR']);
    $invoice = Invoice::factory()->for($customer)->sent()->create(['total' => 12_000, 'amount_paid' => 2_000]);

    app(QueueInvoiceWebhook::class)->handle($invoice, 'invoice.sent', ['note' => 'extra']);

    Queue::assertPushed(DeliverWebhook::class, function (DeliverWebhook $job) use ($customer, $invoice): bool {
        expect($job->customer->is($customer))->toBeTrue()
            ->and($job->afterCommit)->toBeTrue()
            ->and($job->payload['id'])->toMatch('/^evt_[0-9A-HJKMNP-TV-Z]{26}$/')
            ->and($job->payload)->toMatchArray([
                'type' => 'invoice.sent',
                'created_at' => now()->toIso8601String(),
                'data' => [
                    'invoice' => [
                        'id' => $invoice->id,
                        'number' => $invoice->number,
                        'status' => InvoiceStatus::Sent->value,
                        'currency' => $invoice->currency,
                        'total' => 12_000,
                        'amount_paid' => 2_000,
                        'balance' => 10_000,
                    ],
                    'note' => 'extra',
                ],
            ]);

        return true;
    });
});

it('gives every event a unique id', function () {
    $invoice = Invoice::factory()->for(Customer::factory()->withWebhook())->sent()->create();

    app(QueueInvoiceWebhook::class)->handle($invoice, 'invoice.sent');
    app(QueueInvoiceWebhook::class)->handle($invoice, 'invoice.sent');

    $ids = Queue::pushed(DeliverWebhook::class)->map(fn (DeliverWebhook $job) => $job->payload['id']);

    expect($ids)->toHaveCount(2)->and($ids->unique())->toHaveCount(2);
});

it('queues nothing for a customer without an endpoint', function () {
    $invoice = Invoice::factory()->sent()->create();

    app(QueueInvoiceWebhook::class)->handle($invoice, 'invoice.sent');

    Queue::assertNothingPushed();
});
