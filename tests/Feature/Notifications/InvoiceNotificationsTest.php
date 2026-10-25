<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Invoice;
use App\Notifications\InvoiceOverdue;
use App\Notifications\InvoiceSent;
use Illuminate\Notifications\AnonymousNotifiable;

mutates(InvoiceSent::class, InvoiceOverdue::class);

beforeEach(function () {
    $this->invoice = Invoice::factory()
        ->for(Customer::factory()->state(['name' => 'Acme Ltd']))
        ->sent()
        ->create(['number' => 'INV-2026-0042', 'total' => 123_456, 'amount_paid' => 23_456, 'due_at' => '2026-11-04']);
});

it('tells the customer what they owe and when', function () {
    $mail = (new InvoiceSent($this->invoice->load('customer')))->toMail(new AnonymousNotifiable);

    expect($mail->subject)->toBe('Invoice INV-2026-0042')
        ->and($mail->greeting)->toBe('Hello Acme Ltd,')
        ->and($mail->introLines)->toBe([
            'Invoice INV-2026-0042 for GBP 1,234.56 is ready.',
            'Payment is due by Nov 4, 2026.',
        ]);
});

it('reminds the customer of the outstanding balance', function () {
    $mail = (new InvoiceOverdue($this->invoice))->toMail(new AnonymousNotifiable);

    expect($mail->subject)->toBe('Reminder: invoice INV-2026-0042 is overdue')
        ->and($mail->introLines)->toBe([
            'Invoice INV-2026-0042 was due on Nov 4, 2026.',
            'The outstanding balance is GBP 1,000.00.',
        ]);
});

it('delivers by mail only', function (string $class) {
    expect((new $class($this->invoice))->via(new AnonymousNotifiable))->toBe(['mail']);
})->with([InvoiceSent::class, InvoiceOverdue::class]);

it('waits for the surrounding transaction before sending', function () {
    expect((new InvoiceSent($this->invoice))->afterCommit)->toBeTrue();
});

it('still renders when the invoice has no due date', function (string $class) {
    $this->invoice->due_at = null;

    $mail = (new $class($this->invoice->load('customer')))->toMail(new AnonymousNotifiable);

    expect($mail->introLines)->not->toBeEmpty();
})->with([InvoiceSent::class, InvoiceOverdue::class]);
