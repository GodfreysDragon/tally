<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Invoice;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Invoice
 */
final class InvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status->value,
            'currency' => $this->currency,
            'tax_rate' => $this->tax_rate,
            'subtotal' => Money::of($this->subtotal, $this->currency),
            'tax' => Money::of($this->tax_total, $this->currency),
            'total' => $this->totalMoney(),
            'amount_paid' => Money::of($this->amount_paid, $this->currency),
            'balance' => $this->balance(),
            'overdue' => $this->isOverdue(),
            'notes' => $this->notes,
            'due_at' => $this->due_at?->toDateString(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'customer' => CustomerResource::make($this->whenLoaded('customer')),
            'lines' => InvoiceLineResource::collection($this->whenLoaded('lines')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
        ];
    }
}
