<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Notifications\InvoiceOverdue;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;

mutates(App\Console\Commands\RemindOverdueInvoicesCommand::class);

beforeEach(function () {
    Notification::fake();
    $this->travelTo(now()->setDate(2026, 10, 5)->setTime(9, 0));
});

it('reminds customers about overdue invoices', function () {
    $overdue = Invoice::factory()->overdue(days: 3)->create();

    $this->artisan('invoices:remind-overdue')
        ->expectsOutputToContain($overdue->number)
        ->expectsOutputToContain('Reminded 1 overdue invoice.')
        ->assertSuccessful();

    Notification::assertSentOnDemandTimes(InvoiceOverdue::class, 1);
    expect($overdue->fresh()?->last_reminded_at?->equalTo(now()))->toBeTrue();
});

it('ignores invoices that are not overdue', function () {
    Invoice::factory()->draft()->create(['due_at' => now()->subDays(10)]);
    Invoice::factory()->paid()->create(['due_at' => now()->subDays(10)]);
    Invoice::factory()->void()->create(['due_at' => now()->subDays(10)]);
    Invoice::factory()->sent()->create(['due_at' => today()]); // Due today is not late yet.
    Invoice::factory()->sent()->create(['due_at' => now()->addDay()]);

    $this->artisan('invoices:remind-overdue')
        ->expectsOutputToContain('Reminded 0 overdue invoices.')
        ->assertSuccessful();

    Notification::assertNothingSent();
});

it('waits for the reminder interval before nagging again', function () {
    $invoice = Invoice::factory()->overdue()->create();

    $this->artisan('invoices:remind-overdue')->assertSuccessful();
    $this->travel(6)->days();
    $this->artisan('invoices:remind-overdue')->assertSuccessful();

    Notification::assertSentOnDemandTimes(InvoiceOverdue::class, 1);

    $this->travel(1)->day();
    $this->artisan('invoices:remind-overdue')->assertSuccessful();

    Notification::assertSentOnDemandTimes(InvoiceOverdue::class, 2);
    expect($invoice->fresh()?->last_reminded_at?->equalTo(now()))->toBeTrue();
});

it('changes nothing on a dry run', function () {
    $invoice = Invoice::factory()->overdue()->create();

    $this->artisan('invoices:remind-overdue', ['--dry-run' => true])
        ->expectsOutputToContain('Would remind 1 overdue invoice.')
        ->assertSuccessful();

    Notification::assertNothingSent();
    expect($invoice->fresh()?->last_reminded_at)->toBeNull();
});

it('is scheduled daily at 09:00 without overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => str_contains((string) $event->command, 'invoices:remind-overdue'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 9 * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});
