<?php

declare(strict_types=1);

use Velk\Services\StripeClient;

/** Helper: monta um header Stripe-Signature válido para um payload/segredo/timestamp. */
function stripe_sig(string $payload, string $secret, int $ts): string
{
    $sig = hash_hmac('sha256', $ts . '.' . $payload, $secret);
    return "t={$ts},v1={$sig}";
}

test('verifyWebhookSignature: assinatura válida passa', function () {
    $client = new StripeClient('sk_test_x', 'whsec_segredo');
    $payload = '{"id":"evt_1","type":"checkout.session.completed"}';
    $ts = 1_700_000_000;
    $header = stripe_sig($payload, 'whsec_segredo', $ts);
    check(true === $client->verifyWebhookSignature($payload, $header, 300, $ts));
});

test('verifyWebhookSignature: payload adulterado falha', function () {
    $client = new StripeClient('sk_test_x', 'whsec_segredo');
    $ts = 1_700_000_000;
    $header = stripe_sig('{"a":1}', 'whsec_segredo', $ts);
    check(false === $client->verifyWebhookSignature('{"a":2}', $header, 300, $ts));
});

test('verifyWebhookSignature: segredo errado falha', function () {
    $client = new StripeClient('sk_test_x', 'whsec_certo');
    $payload = '{"a":1}';
    $ts = 1_700_000_000;
    $header = stripe_sig($payload, 'whsec_errado', $ts);
    check(false === $client->verifyWebhookSignature($payload, $header, 300, $ts));
});

test('verifyWebhookSignature: timestamp fora da tolerância falha', function () {
    $client = new StripeClient('sk_test_x', 'whsec_segredo');
    $payload = '{"a":1}';
    $ts = 1_700_000_000;
    $header = stripe_sig($payload, 'whsec_segredo', $ts);
    // "agora" = ts + 10min, tolerância 5min → expirado.
    check(false === $client->verifyWebhookSignature($payload, $header, 300, $ts + 600));
});

test('verifyWebhookSignature: header malformado falha', function () {
    $client = new StripeClient('sk_test_x', 'whsec_segredo');
    check(false === $client->verifyWebhookSignature('{}', 'lixo', 300, 1_700_000_000));
});

test('verifyWebhookSignature: sem webhook secret falha', function () {
    $client = new StripeClient('sk_test_x', '');
    $ts = 1_700_000_000;
    $header = stripe_sig('{}', 'qualquer', $ts);
    check(false === $client->verifyWebhookSignature('{}', $header, 300, $ts));
});
