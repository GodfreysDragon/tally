<?php

declare(strict_types=1);

use App\Actions\NextInvoiceNumber;
use App\Models\User;
use Illuminate\Support\Facades\DB;

mutates(NextInvoiceNumber::class);

beforeEach(function () {
    $this->numbers = new NextInvoiceNumber;
});

it('counts up from one', function () {
    $user = User::factory()->create();

    expect($this->numbers->handle($user->id, 2026))->toBe('INV-2026-0001')
        ->and($this->numbers->handle($user->id, 2026))->toBe('INV-2026-0002')
        ->and($this->numbers->handle($user->id, 2026))->toBe('INV-2026-0003');
});

it('restarts every year', function () {
    $user = User::factory()->create();
    $this->numbers->handle($user->id, 2026);
    $this->numbers->handle($user->id, 2026);

    expect($this->numbers->handle($user->id, 2027))->toBe('INV-2027-0001');
});

it('keeps a separate sequence per user', function () {
    [$alice, $bob] = User::factory()->count(2)->create();

    $this->numbers->handle($alice->id, 2026);

    expect($this->numbers->handle($bob->id, 2026))->toBe('INV-2026-0001');
});

it('releases the number when the surrounding transaction rolls back', function () {
    $user = User::factory()->create();

    try {
        DB::transaction(function () use ($user) {
            $this->numbers->handle($user->id, 2026);

            throw new RuntimeException('Send failed');
        });
    } catch (RuntimeException) {
        // Expected.
    }

    expect($this->numbers->handle($user->id, 2026))->toBe('INV-2026-0001');
});

it('pads to four digits but does not truncate', function () {
    $user = User::factory()->create();
    DB::table('invoice_sequences')->insert(['user_id' => $user->id, 'year' => 2026, 'last_number' => 9_999]);

    expect($this->numbers->handle($user->id, 2026))->toBe('INV-2026-10000');
});
