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
            ->assertJsonPath('lines.1.amount', 27_000)
            ->assertJsonPath('lines.1.discount_rate', 1_000)
            ->assertJsonPath('tax_rate', 2_000)
            ->assertJsonPath('notes', 'Thanks for your business.')
            ->assertJsonPath('due_at', now()->addDays(14)->toDateString());

        $invoice = Invoice::query()->findOrFail($response->json('id'));

        expect($invoice->user_id)->toBe($this->user->id)
            ->and($invoice->currency)->toBe('GBP')
            ->and($invoice->lines()->pluck('position')->all())->toBe([0, 1]);
    });

    it('accepts explicit nulls for optional fields', function () {
        $payload = invoicePayload([
            'customer_id' => $this->customer->id,
            'due_at' => null,
            'notes' => null,
            'lines' => [['description' => 'Hosting', 'quantity' => 1, 'unit_price' => 2_500, 'discount_rate' => null]],
        ]);

        $this->postJson('/api/invoices', $payload)
            ->assertCreated()
            ->assertJsonPath('due_at', null)
            ->assertJsonPath('notes', null)
            ->assertJsonPath('lines.0.discount_rate', 0);
    });

    it('stores an empty note as null', function () {
        $this->postJson('/api/invoices', invoicePayload(['customer_id' => $this->customer->id, 'notes' => '']))
            ->assertCreated()
            ->assertJsonPath('notes', null);
    });

    it('accepts the boundaries of each range', function () {
        $payload = invoicePayload([
            'customer_id' => $this->customer->id,
            'tax_rate' => 10_000,
            'due_at' => now()->toDateString(),
            'notes' => str_repeat('n', 2_000),
            'lines' => array_fill(0, 100, ['description' => str_repeat('d', 255), 'quantity' => 1, 'unit_price' => 0, 'discount_rate' => 10_000]),
        ]);

        $this->postJson('/api/invoices', $payload)->assertCreated()->assertJsonCount(100, 'lines');
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

    it('validates the payload', function (array $overrides, array|string $error) {
        $this->postJson('/api/invoices', invoicePayload(['customer_id' => $this->customer->id, ...$overrides]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($error);
    })->with([
        'missing customer' => [['customer_id' => null], ['customer_id' => 'required']],
        'non-integer customer' => [['customer_id' => 'abc'], ['customer_id' => 'integer']],
        'missing tax rate' => [['tax_rate' => null], ['tax_rate' => 'required']],
        'non-integer tax rate' => [['tax_rate' => 'twenty'], ['tax_rate' => 'integer']],
        'unparseable due date' => [['due_at' => 'soon'], ['due_at' => 'valid date']],
        'non-string notes' => [['notes' => ['x']], ['notes' => 'string']],
        'notes too long' => [['notes' => str_repeat('n', 2_001)], ['notes' => '2000']],
        'missing lines' => [['lines' => null], ['lines' => 'required']],
        'lines not a list' => [['lines' => 'one'], ['lines' => 'array']],
        'too many lines' => [['lines' => array_fill(0, 101, ['description' => 'x', 'quantity' => 1, 'unit_price' => 1])], ['lines' => '100']],
        'non-string description' => [['lines' => [['description' => 12, 'quantity' => 1, 'unit_price' => 1]]], ['lines.0.description' => 'string']],
        'description too long' => [['lines' => [['description' => str_repeat('d', 256), 'quantity' => 1, 'unit_price' => 1]]], ['lines.0.description' => '255']],
        'missing quantity' => [['lines' => [['description' => 'x', 'unit_price' => 1]]], ['lines.0.quantity' => 'required']],
        'missing price' => [['lines' => [['description' => 'x', 'quantity' => 1]]], ['lines.0.unit_price' => 'required']],
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

    it('rejects invalid filters', function (string $query, string $error) {
        $this->getJson("/api/invoices?{$query}")->assertUnprocessable()->assertJsonValidationErrors($error);
    })->with([
        'unknown status' => ['status=lost', 'status'],
        'non-boolean overdue' => ['overdue=maybe', 'overdue'],
    ]);

    it('treats empty filters as no filter', function () {
        Invoice::factory()->for($this->customer)->create();
        Invoice::factory()->for($this->customer)->sent()->create();

        $this->getJson('/api/invoices?status=&overdue=')->assertOk()->assertJsonCount(2, 'data');
    });

    it('does not run one query per invoice', function () {
        Invoice::factory()->for($this->customer)->count(5)->create();

        // Model::preventLazyLoading() is on outside production, so an N+1 here throws.
        $this->getJson('/api/invoices')->assertOk()->assertJsonCount(5, 'data');
    });
});

describe('show', function () {
    it('renders every field of a paid invoice', function () {
        $this->freezeTime();
        $invoice = Invoice::factory()->for($this->customer)->paid()->create(['notes' => 'Paid in full']);

        $this->getJson("/api/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJson([
                'id' => $invoice->id,
                'number' => $invoice->number,
                'status' => 'paid',
                'currency' => 'GBP',
                'tax_rate' => 2_000,
                'subtotal' => ['amount' => 10_000, 'currency' => 'GBP', 'formatted' => 'GBP 100.00'],
                'tax' => ['amount' => 2_000, 'currency' => 'GBP'],
                'total' => ['amount' => 12_000, 'currency' => 'GBP'],
                'amount_paid' => ['amount' => 12_000, 'currency' => 'GBP'],
                'balance' => ['amount' => 0, 'currency' => 'GBP'],
                'overdue' => false,
                'notes' => 'Paid in full',
                'due_at' => now()->addDays(30)->toDateString(),
                'sent_at' => now()->toIso8601String(),
                'paid_at' => now()->toIso8601String(),
                'voided_at' => null,
                'customer' => ['id' => $this->customer->id],
            ]);
    });

    it('renders a voided draft with no dates', function () {
        $this->freezeTime();
        $invoice = Invoice::factory()->for($this->customer)->void()->create(['due_at' => null]);

        $this->getJson("/api/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('status', 'void')
            ->assertJsonPath('total.amount', 12_000)
            ->assertJsonPath('balance.amount', 0)
            ->assertJsonPath('due_at', null)
            ->assertJsonPath('sent_at', null)
            ->assertJsonPath('paid_at', null)
            ->assertJsonPath('voided_at', now()->toIso8601String());
    });

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
