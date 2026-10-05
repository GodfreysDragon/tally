<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Signatures in the form "t=<unix time>,v1=<hex HMAC-SHA256 of "<time>.<body>">".
 *
 * The timestamp is part of the signed content, so receivers can reject replays.
 */
final readonly class WebhookSigner
{
    public const string HEADER = 'Tally-Signature';

    public function __construct(private int $toleranceSeconds = 300) {}

    public function sign(string $payload, string $secret, int $timestamp): string
    {
        return sprintf('t=%d,v1=%s', $timestamp, $this->hash($payload, $secret, $timestamp));
    }

    public function verify(string $payload, string $header, string $secret, int $now): bool
    {
        if (preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/', $header, $matches) !== 1) {
            return false;
        }

        $timestamp = (int) $matches[1];

        if (abs($now - $timestamp) > $this->toleranceSeconds) {
            return false;
        }

        return hash_equals($this->hash($payload, $secret, $timestamp), $matches[2]);
    }

    private function hash(string $payload, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
    }
}
