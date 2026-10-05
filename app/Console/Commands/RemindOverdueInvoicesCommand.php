<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Notifications\InvoiceOverdue;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Notification;

#[Signature('invoices:remind-overdue {--dry-run : List the invoices without sending anything}')]
#[Description('Email customers about overdue invoices, at most once per reminder interval')]
final class RemindOverdueInvoicesCommand extends Command
{
    public function handle(): int
    {
        $interval = config()->integer('tally.reminder_interval_days');
        $dryRun = (bool) $this->option('dry-run');
        $count = 0;

        Invoice::query()
            ->overdue()
            ->where(fn (Builder $query) => $query
                ->whereNull('last_reminded_at')
                ->orWhere('last_reminded_at', '<=', now()->subDays($interval)))
            ->with('customer')
            ->lazyById()
            ->each(function (Invoice $invoice) use ($dryRun, &$count): void {
                $count++;
                $this->line("{$invoice->number}  {$invoice->customer->email}  {$invoice->balance()->format()}");

                if ($dryRun) {
                    return;
                }

                Notification::route('mail', $invoice->customer->email)->notify(new InvoiceOverdue($invoice));
                $invoice->forceFill(['last_reminded_at' => now()])->save();
            });

        $this->components->info(($dryRun ? 'Would remind' : 'Reminded')." {$count} overdue ".str('invoice')->plural($count).'.');

        return self::SUCCESS;
    }
}
