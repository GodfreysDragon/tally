<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\CreateInvoice;
use App\Actions\RecordPayment;
use App\Actions\SendInvoice;
use App\Actions\VoidInvoice;
use App\Data\LineInput;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * A demo account with one invoice in every state, for trying the API locally.
 *
 * Everything goes through the same actions the API uses, so totals, numbers
 * and timestamps are real. No Faker: the data is the same on every run.
 */
final class DatabaseSeeder extends Seeder
{
    public const string EMAIL = 'demo@tally.test';

    public const string PASSWORD = 'password';

    public function __construct(
        private readonly CreateInvoice $create,
        private readonly SendInvoice $send,
        private readonly RecordPayment $pay,
        private readonly VoidInvoice $void,
    ) {}

    public function run(): void
    {
        // The demo password is in the README; this account must never exist in production.
        if (app()->environment('production')) {
            throw new RuntimeException('The demo seeder must not run in production.');
        }

        if (User::query()->where('email', self::EMAIL)->exists()) {
            $this->command?->warn('Demo data already present; run migrate:fresh --seed to start over.');

            return;
        }

        // The actions email customers; the demo customers are fictional, so nothing is sent.
        Notification::fake();

        $user = User::query()->create([
            'name' => 'Demo User',
            'email' => self::EMAIL,
            'password' => Hash::make(self::PASSWORD),
        ]);

        $acme = $this->customer($user, 'Acme Ltd', 'accounts@acme.test', 'GBP');
        $globex = $this->customer($user, 'Globex GmbH', 'ap@globex.test', 'EUR');
        $initech = $this->customer($user, 'Initech Inc', 'billing@initech.test', 'USD');

        $retainer = [new LineInput('Monthly retainer', 1, 250_000)];
        $sprint = [
            new LineInput('Design sprint', 2, 150_000),
            new LineInput('Hosting', 12, 2_500, discountRate: 1_000),
        ];

        // Draft: not yet numbered or sent.
        $this->create->handle($acme, $sprint, 2_000, today()->addDays(30), 'Draft for review.');

        // Sent and waiting for payment.
        $this->send->handle($this->create->handle($acme, $retainer, 2_000));

        // Sent, then part-paid.
        $partPaid = $this->send->handle($this->create->handle($globex, $sprint, 1_900));
        $this->pay->handle($partPaid, 100_000, 'SEPA-0001');

        // Paid in full.
        $paid = $this->send->handle($this->create->handle($initech, $retainer, 0));
        $this->pay->handle($paid, $paid->total, 'ACH-0001');

        // Overdue: sent, with a due date that has passed.
        $this->send->handle($this->create->handle($globex, $retainer, 1_900, today()->subDays(10)));

        // Voided before any money was taken.
        $this->void->handle($this->send->handle($this->create->handle($initech, $sprint, 0)));
    }

    private function customer(User $user, string $name, string $email, string $currency): Customer
    {
        return $user->customers()->create(['name' => $name, 'email' => $email, 'currency' => $currency]);
    }
}
