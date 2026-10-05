<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jobs\DeliverWebhook;
use App\Models\Invoice;
use Illuminate\Support\Str;

final class QueueInvoiceWebhook
{
    /**
     * Queue an event for the invoice's customer, if they have a webhook endpoint.
     *
     * Delivery happens after the surrounding transaction commits, so a receiver
     * never hears about a change that was rolled back.
     *
     * @param  array<string, mixed>  $extra
     */
    public function handle(Invoice $invoice, string $event, array $extra = []): void
    {
        $customer = $invoice->loadMissing('customer')->customer;

        if (! $customer->wantsWebhooks()) {
            return;
        }

        DeliverWebhook::dispatch($customer, [
            'id' => 'evt_'.Str::ulid(),
            'type' => $event,
            'created_at' => now()->toIso8601String(),
            'data' => [
                'invoice' => [
                    'id' => $invoice->id,
                    'number' => $invoice->number,
                    'status' => $invoice->status->value,
                    'currency' => $invoice->currency,
                    'total' => $invoice->total,
                    'amount_paid' => $invoice->amount_paid,
                    'balance' => $invoice->balance()->amount,
                ],
                ...$extra,
            ],
        ])->afterCommit();
    }
}
