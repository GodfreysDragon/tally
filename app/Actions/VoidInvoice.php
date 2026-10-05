<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\InvoiceStatus;
use App\Exceptions\InvoiceStateException;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

final readonly class VoidInvoice
{
    public function __construct(private QueueInvoiceWebhook $webhooks) {}

    public function handle(Invoice $invoice): Invoice
    {
        $invoice = DB::transaction(function () use ($invoice): Invoice {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if (! $locked->status->canTransitionTo(InvoiceStatus::Void)) {
                throw InvoiceStateException::invalidTransition($locked->status, InvoiceStatus::Void);
            }

            if ($locked->amount_paid > 0) {
                throw InvoiceStateException::hasPayments();
            }

            $locked->status = InvoiceStatus::Void;
            $locked->voided_at = now();
            $locked->save();

            return $locked;
        });

        $this->webhooks->handle($invoice, 'invoice.voided');

        return $invoice;
    }
}
