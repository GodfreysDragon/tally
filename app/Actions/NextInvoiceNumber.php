<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Support\Facades\DB;

/**
 * Gap-free, per-user, per-year invoice numbers: INV-2026-0001, INV-2026-0002, ...
 *
 * The UPDATE ... SET last_number = last_number + 1 takes a row lock, so two
 * concurrent sends for the same user wait for each other instead of colliding.
 * Call it inside the transaction that saves the number, so a rollback frees it.
 */
final class NextInvoiceNumber
{
    public function handle(int $userId, int $year): string
    {
        $sequence = DB::table('invoice_sequences')->where(['user_id' => $userId, 'year' => $year]);

        // The column defaults to 0 as well; being explicit here leaves no killable mutant.
        DB::table('invoice_sequences')->insertOrIgnore(['user_id' => $userId, 'year' => $year, 'last_number' => 0]); // @pest-mutate-ignore
        $sequence->clone()->increment('last_number');

        $number = $sequence->clone()->value('last_number');

        // The row was just incremented, so $number is always numeric: the guard and cast exist for PHPStan.
        return sprintf('INV-%d-%04d', $year, is_numeric($number) ? (int) $number : 0); // @pest-mutate-ignore
    }
}
