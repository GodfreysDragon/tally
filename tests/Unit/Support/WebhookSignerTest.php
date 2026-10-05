<?php

declare(strict_types=1);

use App\Support\WebhookSigner;

mutates(WebhookSigner::class);

const PAYLOAD = '{"type":"invoice.paid"}';
const SECRET = 'whsec_unit_test_secret';
const SIGNED_AT = 1_790_000_000;

it('produces a timestamped HMAC-SHA256 header', function () {
    $header = (new WebhookSigner)->sign(PAYLOAD, SECRET, SIGNED_AT);

    $expected = hash_hmac('sha256', SIGNED_AT.'.'.PAYLOAD, SECRET);

    expect($header)->toBe('t='.SIGNED_AT.',v1='.$expected);
});

it('verifies its own signatures', function () {
    $signer = new WebhookSigner;

    expect($signer->verify(PAYLOAD, $signer->sign(PAYLOAD, SECRET, SIGNED_AT), SECRET, SIGNED_AT))->toBeTrue();
});

it('rejects tampering', function (string $payload, string $secret) {
    $signer = new WebhookSigner;
    $header = $signer->sign(PAYLOAD, SECRET, SIGNED_AT);

    expect($signer->verify($payload, $header, $secret, SIGNED_AT))->toBeFalse();
})->with([
    'changed payload' => ['{"type":"invoice.void"}', SECRET],
    'wrong secret' => [PAYLOAD, 'whsec_someone_else'],
]);

it('accepts signatures inside the tolerance window', function (int $skew) {
    $signer = new WebhookSigner(toleranceSeconds: 300);

    expect($signer->verify(PAYLOAD, $signer->sign(PAYLOAD, SECRET, SIGNED_AT), SECRET, SIGNED_AT + $skew))->toBeTrue();
})->with([0, 299, 300, -300]);

it('rejects replays outside the tolerance window', function (int $skew) {
    $signer = new WebhookSigner(toleranceSeconds: 300);

    expect($signer->verify(PAYLOAD, $signer->sign(PAYLOAD, SECRET, SIGNED_AT), SECRET, SIGNED_AT + $skew))->toBeFalse();
})->with([301, -301, 86_400]);

it('rejects malformed headers', function (string $header) {
    expect((new WebhookSigner)->verify(PAYLOAD, $header, SECRET, SIGNED_AT))->toBeFalse();
})->with([
    '',
    'garbage',
    't=abc,v1='.str_repeat('a', 64),
    't='.SIGNED_AT.',v1=tooshort',
    't='.SIGNED_AT.',v0='.str_repeat('a', 64),
    ' t='.SIGNED_AT.',v1='.str_repeat('a', 64),
]);
