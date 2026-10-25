<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\LineInput;
use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Support\InvoiceCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final readonly class CreateInvoice
{
    public function __construct(private InvoiceCalculator $calculator) {}

    /**
     * @param  list<LineInput>  $lines
     */
    public function handle(Customer $customer, array $lines, int $taxRate, ?Carbon $dueAt = null, ?string $notes = null): Invoice
    {
        $totals = $this->calculator->calculate($lines, $customer->currency, $taxRate);

        return DB::transaction(function () use ($customer, $lines, $taxRate, $dueAt, $notes, $totals): Invoice {
            $invoice = new Invoice([
                'customer_id' => $customer->id,
                // Explicit, though the model defaults to draft too, so this line has no killable mutant.
                'status' => InvoiceStatus::Draft, // @pest-mutate-ignore
                'currency' => $customer->currency,
                'tax_rate' => $taxRate,
                'subtotal' => $totals->subtotal->amount,
                'tax_total' => $totals->tax->amount,
                'total' => $totals->total->amount,
                'due_at' => $dueAt,
                'notes' => $notes,
            ]);
            $invoice->user_id = $customer->user_id;
            $invoice->save();

            foreach ($lines as $position => $line) {
                $invoice->lines()->create([
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unitPrice,
                    'discount_rate' => $line->discountRate,
                    'amount' => $totals->lineAmounts[$position]->amount,
                    'position' => $position,
                ]);
            }

            return $invoice;
        });
    }
}
