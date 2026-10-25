<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Support\Money;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $customer_id
 * @property string|null $number
 * @property InvoiceStatus $status
 * @property string $currency
 * @property int $tax_rate
 * @property int $subtotal
 * @property int $tax_total
 * @property int $total
 * @property int $amount_paid
 * @property string|null $notes
 * @property Carbon|null $due_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $voided_at
 * @property Carbon|null $last_reminded_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Customer $customer
 */
// Attribute arguments and property defaults aren't executable lines, so mutation testing can't see them
// covered. Model::shouldBeStrict() makes a missing fillable entry throw in every test that creates an invoice.
#[Fillable(['customer_id', 'status', 'currency', 'tax_rate', 'subtotal', 'tax_total', 'total', 'notes', 'due_at'])] // @pest-mutate-ignore
final class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft', // @pest-mutate-ignore
        'amount_paid' => 0, // @pest-mutate-ignore
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<InvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('position');
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function totalMoney(): Money
    {
        return Money::of($this->total, $this->currency);
    }

    public function balance(): Money
    {
        return Money::of($this->total - $this->amount_paid, $this->currency);
    }

    public function isOverdue(?Carbon $now = null): bool
    {
        return $this->status === InvoiceStatus::Sent
            && $this->due_at !== null
            && $this->due_at->lt(($now ?? now())->startOfDay());
    }

    /**
     * Sent invoices whose due date has passed.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function overdue(Builder $query): void
    {
        $query->where('status', InvoiceStatus::Sent)
            ->whereDate('due_at', '<', today());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'tax_rate' => 'integer',
            'subtotal' => 'integer',
            'tax_total' => 'integer',
            'total' => 'integer',
            'amount_paid' => 'integer',
            'due_at' => 'date',
            'sent_at' => 'datetime',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
            'last_reminded_at' => 'datetime',
        ];
    }
}
