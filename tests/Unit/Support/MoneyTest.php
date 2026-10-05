<?php

declare(strict_types=1);

use App\Support\Money;

mutates(Money::class);

describe('construction', function () {
    it('holds an amount and a currency', function () {
        expect(Money::of(1_250, 'GBP'))->toBeMoney(1_250, 'GBP');
    });

    it('creates zero', function () {
        expect(Money::zero('EUR'))->toBeMoney(0, 'EUR')
            ->isZero()->toBeTrue();
    });

    it('rejects malformed currency codes', function (string $currency) {
        Money::of(100, $currency);
    })->throws(InvalidArgumentException::class, 'Invalid currency code')
        ->with(['gbp', 'GB', 'GBPX', '', '123', 'G P']);
});

describe('arithmetic', function () {
    it('adds and subtracts', function () {
        $a = Money::of(1_000, 'GBP');
        $b = Money::of(250, 'GBP');

        expect($a->add($b))->toBeMoney(1_250)
            ->and($a->subtract($b))->toBeMoney(750)
            ->and($b->subtract($a))->toBeMoney(-750);
    });

    it('never mutates the original', function () {
        $original = Money::of(1_000, 'GBP');

        $original->add(Money::of(1, 'GBP'));
        $original->multiply(3);

        expect($original)->toBeMoney(1_000);
    });

    it('multiplies by an integer factor', function () {
        expect(Money::of(333, 'GBP')->multiply(3))->toBeMoney(999)
            ->and(Money::of(333, 'GBP')->multiply(0))->toBeMoney(0);
    });

    it('refuses to mix currencies', function (string $operation) {
        Money::of(100, 'GBP')->{$operation}(Money::of(100, 'EUR'));
    })->throws(InvalidArgumentException::class, 'Cannot combine GBP with EUR')
        ->with(['add', 'subtract', 'greaterThan']);
});

describe('percentage', function () {
    it('rounds half away from zero', function (int $amount, int $basisPoints, int $expected) {
        expect(Money::of($amount, 'GBP')->percentage($basisPoints))->toBeMoney($expected);
    })->with([
        'exact 20%' => [10_000, 2_000, 2_000],
        'zero rate' => [10_000, 0, 0],
        'full rate' => [10_000, 10_000, 10_000],
        'rounds down below half' => [1_002, 2_000, 200],   // 200.4
        'exact small amount' => [1_025, 2_000, 205],       // 205.0
        'half rounds up' => [5, 1_000, 1],                 // 0.5
        'just below half rounds down' => [4, 1_000, 0],    // 0.4
        'negative half rounds away' => [-5, 1_000, -1],    // -0.5
        'negative below half' => [-4, 1_000, 0],           // -0.4
        'odd VAT case' => [1_999, 2_000, 400],             // 399.8
        'one minor unit' => [1, 5_000, 1],                 // 0.5
    ]);
});

describe('allocate', function () {
    it('splits without losing a minor unit', function (int $amount, array $ratios, array $expected) {
        $shares = Money::of($amount, 'GBP')->allocate($ratios);

        expect(array_map(fn (Money $m) => $m->amount, $shares))->toBe($expected)
            ->and(array_sum($expected))->toBe($amount);
    })->with([
        'even split' => [100, [1, 1], [50, 50]],
        'remainder to first' => [100, [1, 1, 1], [34, 33, 33]],
        'weighted' => [1_000, [70, 30], [700, 300]],
        'weighted with remainder' => [5, [3, 7], [2, 3]],
        'zero ratio share' => [100, [0, 1], [0, 100]],
        'negative amount' => [-100, [1, 1, 1], [-34, -33, -33]],
        'single share' => [999, [1], [999]],
    ]);

    it('rejects ratios that add up to nothing', function () {
        Money::of(100, 'GBP')->allocate([0, 0]);
    })->throws(InvalidArgumentException::class, 'Ratios must add up to more than zero.');
});

describe('comparison', function () {
    it('knows its sign', function (int $amount, bool $zero, bool $positive, bool $negative) {
        $money = Money::of($amount, 'GBP');

        expect($money->isZero())->toBe($zero)
            ->and($money->isPositive())->toBe($positive)
            ->and($money->isNegative())->toBe($negative);
    })->with([
        [0, true, false, false],
        [1, false, true, false],
        [-1, false, false, true],
    ]);

    it('compares by amount and currency', function () {
        $money = Money::of(100, 'GBP');

        expect($money->equals(Money::of(100, 'GBP')))->toBeTrue()
            ->and($money->equals(Money::of(101, 'GBP')))->toBeFalse()
            ->and($money->equals(Money::of(100, 'EUR')))->toBeFalse()
            ->and($money->greaterThan(Money::of(99, 'GBP')))->toBeTrue()
            ->and($money->greaterThan(Money::of(100, 'GBP')))->toBeFalse();
    });
});

describe('presentation', function () {
    it('formats without floats', function (int $amount, string $expected) {
        expect(Money::of($amount, 'GBP')->format())->toBe($expected);
    })->with([
        [0, 'GBP 0.00'],
        [5, 'GBP 0.05'],
        [1_250, 'GBP 12.50'],
        [123_456_789, 'GBP 1,234,567.89'],
        [-1_250, '-GBP 12.50'],
    ]);

    it('serialises to JSON', function () {
        expect(json_encode(Money::of(1_250, 'USD')))
            ->toBe('{"amount":1250,"currency":"USD","formatted":"USD 12.50"}');
    });
});
