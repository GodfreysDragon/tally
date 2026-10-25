<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\InvoiceLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $invoice_id
 * @property string $description
 * @property int $quantity
 * @property int $unit_price
 * @property int $discount_rate
 * @property int $amount
 * @property int $position
 */
// Not an executable line, so mutation testing can't see it covered; Model::shouldBeStrict() guards it instead.
#[Fillable(['description', 'quantity', 'unit_price', 'discount_rate', 'amount', 'position'])] // @pest-mutate-ignore
final class InvoiceLine extends Model
{
    /** @use HasFactory<InvoiceLineFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'discount_rate' => 'integer',
            'amount' => 'integer',
            'position' => 'integer',
        ];
    }
}
