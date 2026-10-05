<?php

declare(strict_types=1);

namespace App\Data;

use App\Support\Money;

final readonly class InvoiceTotals
{
    /**
     * @param  list<Money>  $lineAmounts  One per input line, in the same order.
     */
    public function __construct(
        public array $lineAmounts,
        public Money $subtotal,
        public Money $tax,
        public Money $total,
    ) {}
}
