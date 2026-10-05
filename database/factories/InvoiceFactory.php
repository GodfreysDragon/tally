<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
final class InvoiceFactory extends Factory
{
    /**
     * Draft invoice with one £100.00 line and 20% tax.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'user_id' => fn (array $attributes): int => Customer::query()->whereKey($attributes['customer_id'])->sole()->user_id,
            'status' => InvoiceStatus::Draft,
            'currency' => 'GBP',
            'tax_rate' => 2000,
            'subtotal' => 10_000,
            'tax_total' => 2_000,
            'total' => 12_000,
            'amount_paid' => 0,
            'due_at' => now()->addDays(30),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Invoice $invoice): void {
            if ($invoice->lines()->doesntExist()) {
                InvoiceLine::factory()->for($invoice)->create([
                    'quantity' => 1,
                    'unit_price' => $invoice->subtotal,
                    'amount' => $invoice->subtotal,
                ]);
            }
        });
    }

    public function draft(): static
    {
        return $this->state(fn (): array => ['status' => InvoiceStatus::Draft]);
    }

    public function sent(): static
    {
        return $this->state(fn (): array => [
            'status' => InvoiceStatus::Sent,
            'number' => 'INV-'.now()->year.'-'.fake()->unique()->numerify('####'),
            'sent_at' => now(),
        ]);
    }

    public function overdue(int $days = 10): static
    {
        return $this->sent()->state(fn (): array => [
            'sent_at' => now()->subDays($days + 30),
            'due_at' => now()->subDays($days),
        ]);
    }

    public function paid(): static
    {
        return $this->sent()->state(fn (array $attributes): array => [
            'status' => InvoiceStatus::Paid,
            'amount_paid' => $attributes['total'],
            'paid_at' => now(),
        ]);
    }

    public function void(): static
    {
        return $this->state(fn (): array => [
            'status' => InvoiceStatus::Void,
            'voided_at' => now(),
        ]);
    }
}
