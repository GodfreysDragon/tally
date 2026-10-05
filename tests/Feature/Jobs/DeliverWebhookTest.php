<?php

declare(strict_types=1);

use App\Jobs\DeliverWebhook;
use App\Models\Customer;
use App\Support\WebhookSigner;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

mutates(DeliverWebhook::class);

function webhookPayload(): array
{
    return [
        'id' => 'evt_01TEST',
        'type' => 'invoice.paid',
        'created_at' => '2026-10-05T09:00:00+00:00',
        'data' => ['invoice' => ['id' => 1, 'balance' => 0]],
    ];
}

it('posts a signed payload the receiver can verify', function () {
    $this->freezeTime();
    Http::fake(['hooks.example.test/*' => Http::response(status: 204)]);
    $customer = Customer::factory()->withWebhook('https://hooks.example.test/tally', 'whsec_job_secret')->create();

    (new DeliverWebhook($customer, webhookPayload()))->handle(new WebhookSigner);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://hooks.example.test/tally'
            && $request->method() === 'POST'
            && $request->header('Content-Type') === ['application/json']
            && $request->header('Tally-Event') === ['invoice.paid']
            && $request->header('Tally-Delivery') === ['evt_01TEST']
            && $request->data() === webhookPayload()
            // Verify exactly as a receiver would: from the raw body and our public signer.
            && (new WebhookSigner)->verify($request->body(), $request->header(WebhookSigner::HEADER)[0], 'whsec_job_secret', now()->getTimestamp());
    });
});

it('throws on a failed response so the queue retries', function (int $status) {
    Http::fake(['*' => Http::response(status: $status)]);
    $customer = Customer::factory()->withWebhook()->create();

    (new DeliverWebhook($customer, webhookPayload()))->handle(new WebhookSigner);
})->throws(RequestException::class)->with([400, 410, 500, 503]);

it('retries with backoff before giving up', function () {
    $job = new DeliverWebhook(Customer::factory()->withWebhook()->create(), webhookPayload());

    expect($job->tries)->toBe(5)
        ->and($job->backoff())->toBe([10, 60, 300, 900]);
});

it('does nothing if the customer removed their endpoint while queued', function () {
    Http::fake();
    $customer = Customer::factory()->create(['webhook_url' => null, 'webhook_secret' => null]);

    (new DeliverWebhook($customer, webhookPayload()))->handle(new WebhookSigner);

    Http::assertNothingSent();
});

it('logs when delivery is abandoned', function () {
    Log::spy();
    $customer = Customer::factory()->withWebhook()->create();

    (new DeliverWebhook($customer, webhookPayload()))->failed(new RuntimeException('Connection refused'));

    Log::shouldHaveReceived('warning')->once()->with('Webhook delivery gave up.', [
        'customer_id' => $customer->id,
        'event_id' => 'evt_01TEST',
        'type' => 'invoice.paid',
        'error' => 'Connection refused',
    ]);
});

it('is dispatched through the real queue end to end', function () {
    Http::fake(['*' => Http::response(status: 200)]);
    $customer = Customer::factory()->withWebhook()->create();

    DeliverWebhook::dispatch($customer, webhookPayload()); // QUEUE_CONNECTION=sync in tests.

    Http::assertSentCount(1);
});
