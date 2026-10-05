<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\InvoiceStatus;
use App\Exceptions\InvoiceStateException;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class RecordPayment
{
    public function __construct(private QueueInvoiceWebhook $webhooks) {}

    public function handle(Invoice $invoice, int $amount, ?string $reference = null, ?Carbon $receivedAt = null): Payment
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('A payment must be greater than zero.');
        }

        [$invoice, $payment] = DB::transaction(function () use ($invoice, $amount, $reference, $receivedAt): array {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($locked->status !== InvoiceStatus::Sent) {
                throw InvoiceStateException::notPayable($locked->status);
            }

            $payment = Money::of($amount, $locked->currency);

            if ($payment->greaterThan($locked->balance())) {
                throw InvoiceStateException::exceedsBalance($payment, $locked->balance());
            }

            $record = $locked->payments()->create([
                'amount' => $amount,
                'reference' => $reference,
                'received_at' => $receivedAt ?? now(),
            ]);

            $locked->amount_paid += $amount;

            if ($locked->balance()->isZero()) {
                $locked->status = InvoiceStatus::Paid;
                $locked->paid_at = now();
            }

            $locked->save();

            return [$locked, $record];
        });

        $this->webhooks->handle($invoice, 'payment.recorded', ['payment_id' => $payment->id, 'amount' => $payment->amount]);

        if ($invoice->status === InvoiceStatus::Paid) {
            $this->webhooks->handle($invoice, 'invoice.paid');
        }

        return $payment;
    }
}
