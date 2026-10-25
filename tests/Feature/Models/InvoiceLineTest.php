<?php

declare(strict_types=1);

use App\Models\InvoiceLine;

mutates(InvoiceLine::class);

it('casts raw column values to integers', function () {
    $line = (new InvoiceLine)->setRawAttributes([
        'quantity' => '3',
        'unit_price' => '2500',
        'discount_rate' => '1000',
        'amount' => '6750',
        'position' => '2',
    ]);

    expect([$line->quantity, $line->unit_price, $line->discount_rate, $line->amount, $line->position])
        ->toBe([3, 2_500, 1_000, 6_750, 2]);
});
