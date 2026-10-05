<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;

mutates(App\Http\Controllers\Api\InvoiceController::class, App\Http\Requests\StoreInvoiceRequest::class, App\Http\Resources\InvoiceResource::class, App\Actions\CreateInvoice::class, App\Policies\InvoicePolicy::class);

beforeEach(function () {
    $this->user = actingAsUser();
    $this->customer = Customer::factory()->for($this->user)->create(['currency' => 'GBP']);
});

function invoicePayload(array $overrides = []): array
{
    return [
        'tax_rate' => 2_000,
        'due_at' => now()->addDays(14)->toDateString(),
        'notes' => 'Thanks for your business.',
        'lines' => [
            ['description' => 'Design sprint', 'quantity' => 2, 'unit_price' => 150_000],
            ['description' => 'Hosting', 'quantity' => 12, 'unit_price' => 2_500, 'discount_rate' => 1_000],
        ],
        ...$overrides,
    ];
}

describe('create', function () {
    it('creates a draft invoice with calculated totals', function () {
        $response = $this->postJson('/api/invoices', invoicePayload(['customer_id' => $this->customer->id]))
            ->assertCreated()
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('number', null)
            ->assertJsonPath('subtotal.amount', 327_000)  // 300,000 + (30,000 - 10%)
            ->assertJsonPath('tax.amount', 65_400)
            ->assertJsonPath('total.amount', 392_400)
            ->assertJsonPath('total.formatted', 'GBP 3,924.00')
            ->assertJsonPath('balance.amount', 392_400)
            ->assertJsonPath('customer.id', $this->customer->id)
            ->assertJsonCount(2, 'lines')
            ->assertJsonPath('lines.1.amount', 27_000);

        $invoice = Invoice::query()->findOrFail($response->json('id'));

        expect($invoice->user_id)->toBe($this->user->id)
            ->and($invoice->currency)->toBe('GBP')
            ->and($invoice->lines()->pluck('position')->all())->toBe([0, 1]);
    });

    it('takes its currency from the customer', function () {
        $euro = Customer::factory()->for($this->user)->create(['currency' => 'EUR']);

        $this->postJson('/api/invoices', invoicePayload(['customer_id' => $euro->id]))
            ->assertCreated()
            ->assertJsonPath('currency', 'EUR')
            ->assertJsonPath('total.currency', 'EUR');
    });

    it('will not invoice another user\'s customer', function () {
        $stranger = Customer::factory()->create();

        $this->postJson('/api/invoices', invoicePayload(['customer_id' => $stranger->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('customer_id');

        expect(Invoice::query()->count())->toBe(0);
    });

    it('validates the payload', function (array $overrides, string $error) {
        $this->postJson('/api/invoices', invoicePayload(['customer_id' => $this->customer->id, ...$overrides]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($error);
    })->with([
        'no lines' => [['lines' => []], 'lines'],
        'tax over 100%' => [['tax_rate' => 10_001], 'tax_rate'],
        'negative tax' => [['tax_rate' => -1], 'tax_rate'],
        'due in the past' => [['due_at' => '2000-01-01'], 'due_at'],
        'zero quantity' => [['lines' => [['description' => 'x', 'quantity' => 0, 'unit_price' => 1]]], 'lines.0.quantity'],
        'fractional price' => [['lines' => [['description' => 'x', 'quantity' => 1, 'unit_price' => 9.99]]], 'lines.0.unit_price'],
        'negative price' => [['lines' => [['description' => 'x', 'quantity' => 1, 'unit_price' => -1]]], 'lines.0.unit_price'],
        'discount over 100%' => [['lines' => [['description' => 'x', 'quantity' => 1, 'unit_price' => 1, 'discount_rate' => 10_001]]], 'lines.0.discount_rate'],
        'missing description' => [['lines' => [['quantity' => 1, 'unit_price' => 1]]], 'lines.0.description'],
    ]);
});

describe('list', function () {
    it('lists only the caller\'s invoices, newest first', function () {
        $older = Invoice::factory()->for($this->customer)->create();
        $newer = Invoice::factory()->for($this->customer)->create();
        Invoice::factory()->create();

        $this->getJson('/api/invoices')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id);
    });

    it('filters by status', function () {
        Invoice::factory()->for($this->customer)->create();
        $sent = Invoice::factory()->for($this->customer)->sent()->create();

        $this->getJson('/api/invoices?status=sent')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $sent->id);
    });

    it('filters to overdue invoices', function () {
        Invoice::factory()->for($this->customer)->sent()->create();
        $overdue = Invoice::factory()->for($this->customer)->overdue()->create();

        $this->getJson('/api/invoices?overdue=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $overdue->id)
            ->assertJsonPath('data.0.overdue', true);
    });

    it('rejects an unknown status filter', function () {
        $this->getJson('/api/invoices?status=lost')->assertUnprocessable()->assertJsonValidationErrors('status');
    });

    it('does not run one query per invoice', function () {
        Invoice::factory()->for($this->customer)->count(5)->create();

        // Model::preventLazyLoading() is on outside production, so an N+1 here throws.
        $this->getJson('/api/invoices')->assertOk()->assertJsonCount(5, 'data');
    });
});

describe('show', function () {
    it('shows an invoice with its lines and payments', function () {
        $invoice = Invoice::factory()->for($this->customer)->sent()->create();
        $invoice->payments()->create(['amount' => 1_000, 'received_at' => now()]);

        $this->getJson("/api/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('id', $invoice->id)
            ->assertJsonCount(1, 'lines')
            ->assertJsonCount(1, 'payments')
            ->assertJsonPath('payments.0.amount', 1_000);
    });

    it('hides other users\' invoices', function () {
        $invoice = Invoice::factory()->create();

        $this->getJson("/api/invoices/{$invoice->id}")->assertForbidden();
    });

    it('returns 404 for a missing invoice', function () {
        $this->getJson('/api/invoices/999999')->assertNotFound();
    });
});

it('is scoped per user throughout', function () {
    $invoice = Invoice::factory()->for($this->customer)->create();
    actingAsUser(User::factory()->create());

    $this->postJson("/api/invoices/{$invoice->id}/send")->assertForbidden();
    $this->postJson("/api/invoices/{$invoice->id}/void")->assertForbidden();
    $this->postJson("/api/invoices/{$invoice->id}/payments", ['amount' => 1])->assertForbidden();

    expect($invoice->fresh()?->status)->toBe(InvoiceStatus::Draft);
});
