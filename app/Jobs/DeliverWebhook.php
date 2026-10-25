<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Customer;
use App\Support\WebhookSigner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    // Property defaults aren't executable lines, so mutation testing can never see a test cover them.
    // DeliverWebhookTest asserts the value directly.
    public int $tries = 5; // @pest-mutate-ignore

    /**
     * @param  array{id: string, type: string, created_at: string, data: array<string, mixed>}  $payload
     */
    public function __construct(
        public readonly Customer $customer,
        public readonly array $payload,
    ) {}

    /**
     * Seconds to wait before each retry: 10s, 1m, 5m, 15m.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(WebhookSigner $signer): void
    {
        // The endpoint may have been removed while this job waited in the queue.
        if (! $this->customer->wantsWebhooks()) {
            return;
        }

        $body = json_encode($this->payload, JSON_THROW_ON_ERROR);

        // wantsWebhooks() guarantees both are strings here; the casts below are for PHPStan.
        Http::timeout(config()->integer('tally.webhooks.timeout_seconds'))
            ->withHeaders([
                WebhookSigner::HEADER => $signer->sign($body, (string) $this->customer->webhook_secret, now()->getTimestamp()), // @pest-mutate-ignore
                'Tally-Event' => $this->payload['type'],
                'Tally-Delivery' => $this->payload['id'],
            ])
            ->withBody($body, 'application/json')
            ->post((string) $this->customer->webhook_url) // @pest-mutate-ignore
            ->throw();
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Webhook delivery gave up.', [
            'customer_id' => $this->customer->id,
            'event_id' => $this->payload['id'],
            'type' => $this->payload['type'],
            'error' => $exception?->getMessage(),
        ]);
    }
}
