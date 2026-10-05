<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
final class CustomerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->company(),
            'email' => fake()->unique()->companyEmail(),
            'currency' => 'GBP',
        ];
    }

    public function withWebhook(string $url = 'https://hooks.example.test/tally', string $secret = 'whsec_test'): static
    {
        return $this->state(fn (): array => [
            'webhook_url' => $url,
            'webhook_secret' => $secret,
        ]);
    }
}
