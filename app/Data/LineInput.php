<?php

declare(strict_types=1);

namespace App\Data;

final readonly class LineInput
{
    /**
     * @param  int  $discountRate  Basis points: 1000 = 10% off.
     */
    public function __construct(
        public string $description,
        public int $quantity,
        public int $unitPrice,
        public int $discountRate = 0,
    ) {}

    /**
     * @param  array{description: string, quantity: int, unit_price: int, discount_rate?: int|null}  $line
     */
    public static function fromArray(array $line): self
    {
        return new self($line['description'], $line['quantity'], $line['unit_price'], $line['discount_rate'] ?? 0);
    }
}
