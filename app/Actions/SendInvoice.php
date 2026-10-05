<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\InvoiceStatus;
use App\Exceptions\InvoiceStateException;
use App\Models\Invoice;
use App\Notifications\InvoiceSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

final readonly class SendInvoice
{
    public function __construct(
        private NextInvoiceNumber $numbers,
        private QueueInvoiceWebhook $webhooks,
    ) {}

    public function handle(Invoice $invoice): Invoice
    {
        $invoice = DB::transaction(function () use ($invoice): Invoice {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if (! $locked->status->canTransitionTo(InvoiceStatus::Sent)) {
                throw InvoiceStateException::invalidTransition($locked->status, InvoiceStatus::Sent);
            }

            if ($locked->lines()->doesntExist()) {
                throw InvoiceStateException::empty();
            }

            $locked->number = $this->numbers->handle($locked->user_id, now()->year);
            $locked->status = InvoiceStatus::Sent;
            $locked->sent_at = now();
            $locked->due_at ??= today()->addDays(config()->integer('tally.payment_terms_days'));
            $locked->save();

            return $locked;
        });

        $customer = $invoice->loadMissing('customer')->customer;

        Notification::route('mail', [$customer->email => $customer->name])->notify(new InvoiceSent($invoice));

        $this->webhooks->handle($invoice, 'invoice.sent');

        return $invoice;
    }
}
