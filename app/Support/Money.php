<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use JsonSerializable;

/**
 * An immutable amount of money held in integer minor units (pence, cents).
 *
 * Floats never touch an amount. Every currency is treated as having two
 * decimal places, which covers every currency this application accepts.
 */
final readonly class Money implements JsonSerializable
{
    public function __construct(public int $amount, public string $currency)
    {
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException("Invalid currency code [{$currency}].");
        }
    }

    public static function of(int $amount, string $currency): self
    {
        return new self($amount, $currency);
    }

    public static function zero(string $currency): self
    {
        return new self(0, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount - $other->amount, $this->currency);
    }

    public function multiply(int $factor): self
    {
        return new self($this->amount * $factor, $this->currency);
    }

    /**
     * A share of this amount, given in basis points (2000 = 20%), rounded half away from zero.
     */
    public function percentage(int $basisPoints): self
    {
        $product = $this->amount * $basisPoints;
        $quotient = intdiv($product, 10_000);

        // Mutants here are equivalent: 2 * remainder is always even, and $product is never 0 or ±1 inside the branch.
        if (abs($product % 10_000) * 2 >= 10_000) { // @pest-mutate-ignore
            $quotient += $product <=> 0; // @pest-mutate-ignore
        }

        return new self($quotient, $this->currency);
    }

    /**
     * Split the amount by ratio without losing a single minor unit.
     *
     * Leftover units go to the earliest shares, one each.
     *
     * @param  non-empty-list<int<0, max>>  $ratios
     * @return non-empty-list<self>
     */
    public function allocate(array $ratios): array
    {
        $total = array_sum($ratios);

        if ($total <= 0) {
            throw new InvalidArgumentException('Ratios must add up to more than zero.');
        }

        $shares = array_map(fn (int $ratio): int => intdiv($this->amount * $ratio, $total), $ratios);
        $remainder = $this->amount - array_sum($shares);
        $step = $remainder <=> 0;

        for ($i = 0; $remainder !== 0; $i++) {
            $shares[$i] += $step;
            $remainder -= $step;
        }

        // Already a list at runtime; array_values() is what lets PHPStan prove it.
        return array_values(array_map(fn (int $share): self => new self($share, $this->currency), $shares)); // @pest-mutate-ignore
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amount === $other->amount;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount > $other->amount;
    }

    public function format(): string
    {
        $sign = $this->amount < 0 ? '-' : '';
        $absolute = abs($this->amount);

        return sprintf('%s%s %s.%02d', $sign, $this->currency, number_format(intdiv($absolute, 100)), $absolute % 100);
    }

    /**
     * @return array{amount: int, currency: string, formatted: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'formatted' => $this->format(),
        ];
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Cannot combine {$this->currency} with {$other->currency}.");
        }
    }
}
