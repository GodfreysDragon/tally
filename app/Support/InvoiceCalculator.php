<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\InvoiceTotals;
use App\Data\LineInput;

final class InvoiceCalculator
{
    /**
     * Discounts apply per line; tax applies once, to the discounted subtotal.
     *
     * @param  list<LineInput>  $lines
     * @param  int  $taxRate  Basis points.
     */
    public function calculate(array $lines, string $currency, int $taxRate): InvoiceTotals
    {
        $lineAmounts = array_map(function (LineInput $line) use ($currency): Money {
            $gross = Money::of($line->unitPrice, $currency)->multiply($line->quantity);

            return $gross->subtract($gross->percentage($line->discountRate));
        }, $lines);

        $subtotal = array_reduce(
            $lineAmounts,
            fn (Money $carry, Money $amount): Money => $carry->add($amount),
            Money::zero($currency),
        );
        $tax = $subtotal->percentage($taxRate);

        return new InvoiceTotals($lineAmounts, $subtotal, $tax, $subtotal->add($tax));
    }
}
