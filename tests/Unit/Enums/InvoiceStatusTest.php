<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;

mutates(InvoiceStatus::class);

/*
 * The full transition matrix, written out. Adding a status without
 * deciding its transitions makes this dataset incomplete and the test fail.
 */
dataset('transitions', function () {
    $allowed = [
        'draft' => ['sent', 'void'],
        'sent' => ['paid', 'void'],
        'paid' => [],
        'void' => [],
    ];

    foreach (InvoiceStatus::cases() as $from) {
        foreach (InvoiceStatus::cases() as $to) {
            yield "{$from->value} -> {$to->value}" => [$from, $to, in_array($to->value, $allowed[$from->value], true)];
        }
    }
});

it('enforces the transition matrix', function (InvoiceStatus $from, InvoiceStatus $to, bool $allowed) {
    expect($from->canTransitionTo($to))->toBe($allowed);
})->with('transitions');

it('treats paid and void as terminal', function () {
    expect(InvoiceStatus::Paid->isTerminal())->toBeTrue()
        ->and(InvoiceStatus::Void->isTerminal())->toBeTrue()
        ->and(InvoiceStatus::Draft->isTerminal())->toBeFalse()
        ->and(InvoiceStatus::Sent->isTerminal())->toBeFalse();
});

it('never allows a status to transition to itself', function (InvoiceStatus $status) {
    expect($status->canTransitionTo($status))->toBeFalse();
})->with(InvoiceStatus::cases());
