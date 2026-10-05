<?php

declare(strict_types=1);

use App\Data\LineInput;

mutates(LineInput::class);

it('builds from validated request data', function () {
    $line = LineInput::fromArray(['description' => 'Hosting', 'quantity' => 12, 'unit_price' => 2_000, 'discount_rate' => 500]);

    expect($line)
        ->description->toBe('Hosting')
        ->quantity->toBe(12)
        ->unitPrice->toBe(2_000)
        ->discountRate->toBe(500);
});

it('defaults a missing or null discount to zero', function (array $extra) {
    $line = LineInput::fromArray(['description' => 'Hosting', 'quantity' => 1, 'unit_price' => 100, ...$extra]);

    expect($line->discountRate)->toBe(0);
})->with([
    'missing' => [[]],
    'null' => [['discount_rate' => null]],
]);
