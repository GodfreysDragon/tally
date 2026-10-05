<?php

declare(strict_types=1);

use App\Data\LineInput;
use App\Support\InvoiceCalculator;

mutates(InvoiceCalculator::class);

beforeEach(function () {
    $this->calculator = new InvoiceCalculator;
});

it('totals a single line with tax', function () {
    $totals = $this->calculator->calculate([new LineInput('Consulting', 2, 50_000)], 'GBP', 2_000);

    expect($totals->lineAmounts)->toHaveCount(1)
        ->and($totals->lineAmounts[0])->toBeMoney(100_000)
        ->and($totals->subtotal)->toBeMoney(100_000)
        ->and($totals->tax)->toBeMoney(20_000)
        ->and($totals->total)->toBeMoney(120_000);
});

it('applies line discounts before tax', function () {
    $totals = $this->calculator->calculate([
        new LineInput('Licence', 1, 10_000, discountRate: 2_500), // 25% off -> 7,500
        new LineInput('Support', 3, 1_000),                        // 3,000
    ], 'GBP', 2_000);

    expect(array_map(fn ($m) => $m->amount, $totals->lineAmounts))->toBe([7_500, 3_000])
        ->and($totals->subtotal)->toBeMoney(10_500)
        ->and($totals->tax)->toBeMoney(2_100)
        ->and($totals->total)->toBeMoney(12_600);
});

it('rounds tax once on the subtotal, not per line', function () {
    // Per-line rounding would give 3 x round(0.5) = 3; rounding the subtotal gives round(1.5) = 2.
    $lines = array_fill(0, 3, new LineInput('Widget', 1, 5));

    $totals = $this->calculator->calculate($lines, 'GBP', 1_000);

    expect($totals->subtotal)->toBeMoney(15)
        ->and($totals->tax)->toBeMoney(2)
        ->and($totals->total)->toBeMoney(17);
});

it('handles zero tax and free lines', function () {
    $totals = $this->calculator->calculate([new LineInput('Free trial', 1, 0)], 'EUR', 0);

    expect($totals->total)->toBeMoney(0, 'EUR');
});

it('returns zero for no lines', function () {
    $totals = $this->calculator->calculate([], 'USD', 2_000);

    expect($totals->lineAmounts)->toBe([])
        ->and($totals->total)->toBeMoney(0, 'USD');
});

it('keeps the line amount when a discount is 100%', function () {
    $totals = $this->calculator->calculate([new LineInput('Comped', 4, 2_500, 10_000)], 'GBP', 2_000);

    expect($totals->lineAmounts[0])->toBeMoney(0)
        ->and($totals->total)->toBeMoney(0);
});
