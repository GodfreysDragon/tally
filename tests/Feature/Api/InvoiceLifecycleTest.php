<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Jobs\DeliverWebhook;
use App\Models\Customer;
use App\Models\Invoice;
use App\Notifications\InvoiceSent;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

mutates(App\Actions\SendInvoice::class, App\Actions\RecordPayment::class, App\Actions\VoidInvoice::class, App\Actions\QueueInvoiceWebhook::class, App\Http\Controllers\Api\InvoiceStatusController::class, App\Http\Controllers\Api\PaymentController::class, App\Http\Requests\StorePaymentRequest::class, App\Exceptions\InvoiceStateException::class);

beforeEach(function () {
    Notification::fake();
    Queue::fake();

    $this->user = actingAsUser();
    $this->customer = Customer::factory()->for($this->user)->withWebhook()->create(['email' => 'ap@acme.test']);
});

describe('send', function () {
    it('numbers, sends and notifies', function () {
        $this->freezeTime();
        $invoice = Invoice::factory()->for($this->customer)->create(['due_at' => null]);

        $this->postJson("/api/invoices/{$invoice->id}/send")
            ->assertOk()
            ->assertJsonPath('status', 'sent')
            ->assertJsonPath('number', sprintf('INV-%d-0001', now()->year))
            ->assertJsonPath('sent_at', now()->toIso8601String())
            ->assertJsonPath('due_at', today()->addDays(30)->toDateString());

        Notification::assertSentOnDemand(
            InvoiceSent::class,
            fn (InvoiceSent $notification, array $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === ['ap@acme.test' => $this->customer->name]
                && $notification->invoice->is($invoice),
        );

        Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job) => $job->payload['type'] === 'invoice.sent');

        $stored = $invoice->fresh();
        expect($stored?->status)->toBe(InvoiceStatus::Sent)
            ->and($stored?->number)->toBe(sprintf('INV-%d-0001', now()->year));
    });

    it('keeps an explicit due date', function () {
        $invoice = Invoice::factory()->for($this->customer)->create(['due_at' => '2099-01-31']);

        $this->postJson("/api/invoices/{$invoice->id}/send")->assertOk()->assertJsonPath('due_at', '2099-01-31');
    });

    it('numbers invoices sequentially', function () {
        $invoices = Invoice::factory()->for($this->customer)->count(3)->create();

        $numbers = $invoices->map(fn (Invoice $invoice) => $this->postJson("/api/invoices/{$invoice->id}/send")->json('number'));

        $year = now()->year;
        expect($numbers->all())->toBe(["INV-{$year}-0001", "INV-{$year}-0002", "INV-{$year}-0003"]);
    });

    it('refuses to send twice', function (string $state) {
        $invoice = Invoice::factory()->for($this->customer)->{$state}()->create();

        $this->postJson("/api/invoices/{$invoice->id}/send")
            ->assertConflict()
            ->assertJsonPath('message', "An invoice cannot move from {$invoice->status->value} to sent.");

        Notification::assertNothingSent();
    })->with(['sent', 'paid', 'void']);

    it('refuses to send an invoice with no lines', function () {
        $invoice = Invoice::factory()->for($this->customer)->create();
        $invoice->lines()->delete();

        $this->postJson("/api/invoices/{$invoice->id}/send")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'An invoice must have at least one line before it is sent.');

        expect($invoice->fresh()?->status)->toBe(InvoiceStatus::Draft);
    });

    it('skips webhooks for customers without an endpoint', function () {
        $quiet = Customer::factory()->for($this->user)->create();
        $invoice = Invoice::factory()->for($quiet)->create();

        $this->postJson("/api/invoices/{$invoice->id}/send")->assertOk();

        Queue::assertNothingPushed();
    });
});

describe('payments', function () {
    it('records a partial payment', function () {
        $invoice = Invoice::factory()->for($this->customer)->sent()->create(['total' => 12_000]);

        $this->postJson("/api/invoices/{$invoice->id}/payments", ['amount' => 5_000, 'reference' => 'BACS-1'])
            ->assertCreated()
            ->assertJsonPath('amount', 5_000)
            ->assertJsonPath('reference', 'BACS-1');

        $invoice->refresh();

        expect($invoice->status)->toBe(InvoiceStatus::Sent)
            ->and($invoice->amount_paid)->toBe(5_000)
            ->and($invoice->balance())->toBeMoney(7_000);

        Queue::assertPushed(DeliverWebhook::class, 1);
        Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job) => $job->payload['type'] === 'payment.recorded'
            && $job->payload['data']['payment_id'] === $invoice->payments()->sole()->id
            && $job->payload['data']['amount'] === 5_000
            && $job->payload['data']['invoice']['balance'] === 7_000);
    });

    it('marks the invoice paid when the balance reaches zero', function () {
        $this->freezeTime();
        $invoice = Invoice::factory()->for($this->customer)->sent()->create(['total' => 12_000]);

        $this->postJson("/api/invoices/{$invoice->id}/payments", ['amount' => 2_000])->assertCreated();
        $this->postJson("/api/invoices/{$invoice->id}/payments", ['amount' => 10_000])->assertCreated();

        $invoice->refresh();

        expect($invoice->status)->toBe(InvoiceStatus::Paid)
            ->and($invoice->paid_at?->toDateTimeString())->toBe(now()->toDateTimeString())
            ->and($invoice->balance()->isZero())->toBeTrue()
            ->and($invoice->payments()->count())->toBe(2);

        Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job) => $job->payload['type'] === 'invoice.paid');
        Queue::assertPushed(DeliverWebhook::class, 3);
    });

    it('rejects overpayment', function () {
        $invoice = Invoice::factory()->for($this->customer)->sent()->create(['total' => 12_000]);

        $this->postJson("/api/invoices/{$invoice->id}/payments", ['amount' => 12_001])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'A payment of GBP 120.01 exceeds the outstanding balance of GBP 120.00.');

        expect($invoice->payments()->count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('only accepts payments on sent invoices', function (string $state) {
        $invoice = Invoice::factory()->for($this->customer)->{$state}()->create();

        $this->postJson("/api/invoices/{$invoice->id}/payments", ['amount' => 1])->assertConflict();
    })->with(['draft', 'paid', 'void']);

    it('validates the amount', function (mixed $amount) {
        $invoice = Invoice::factory()->for($this->customer)->sent()->create();

        $this->postJson("/api/invoices/{$invoice->id}/payments", ['amount' => $amount])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');
    })->with([0, -100, 10.5, 'ten', null]);

    it('validates the optional fields', function (array $payload, array $errors) {
        $invoice = Invoice::factory()->for($this->customer)->sent()->create();

        $this->postJson("/api/invoices/{$invoice->id}/payments", ['amount' => 100, ...$payload])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errors);
    })->with([
        'missing amount' => [['amount' => null], ['amount' => 'required']],
        'non-string reference' => [['reference' => ['BACS']], ['reference' => 'string']],
        'reference too long' => [['reference' => str_repeat('r', 256)], ['reference' => '255']],
        'unparseable date' => [['received_at' => 'yesterday-ish'], ['received_at' => 'valid date']],
    ]);

    it('accepts explicit nulls for the optional fields', function () {
        $invoice = Invoice::factory()->for($this->customer)->sent()->create();

        $this->postJson("/api/invoices/{$invoice->id}/payments", ['amount' => 100, 'reference' => null, 'received_at' => null])
            ->assertCreated()
            ->assertJsonPath('reference', null);
    });

    it('refuses a payment dated in the future', function () {
        $invoice = Invoice::factory()->for($this->customer)->sent()->create();

        $this->postJson("/api/invoices/{$invoice->id}/payments", ['amount' => 100, 'received_at' => now()->addDay()->toIso8601String()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('received_at');
    });
});

describe('void', function () {
    it('voids a draft or sent invoice', function (string $state) {
        $invoice = Invoice::factory()->for($this->customer)->{$state}()->create();

        $this->postJson("/api/invoices/{$invoice->id}/void")
            ->assertOk()
            ->assertJsonPath('status', 'void');

        expect($invoice->fresh()?->voided_at)->not->toBeNull();
        Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job) => $job->payload['type'] === 'invoice.voided'
            && $job->payload['data']['invoice']['balance'] === 0);
    })->with(['draft', 'sent']);

    it('refuses to void a paid or already void invoice', function (string $state) {
        $invoice = Invoice::factory()->for($this->customer)->{$state}()->create();

        $this->postJson("/api/invoices/{$invoice->id}/void")->assertConflict();
    })->with(['paid', 'void']);

    it('refuses to void an invoice that has taken any money', function () {
        $invoice = Invoice::factory()->for($this->customer)->sent()->create(['amount_paid' => 1]);

        $this->postJson("/api/invoices/{$invoice->id}/void")
            ->assertConflict()
            ->assertJsonPath('message', 'An invoice with recorded payments cannot be voided.');
    });
});
