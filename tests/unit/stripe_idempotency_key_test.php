<?php

declare(strict_types=1);

use Velk\Services\StripeClient;

/**
 * Revisão de pagamentos 2026-09: Idempotency-Key nas criações.
 *
 * Nenhum POST ao Stripe levava a chave. Um timeout com a resposta perdida
 * (o Stripe criou, o Velk não soube) seguido de novo clique criava
 * cliente/produto/preço em dobro. A chave é determinística por INTENÇÃO:
 * mesma intenção, mesma chave, mesmo objeto de volta.
 */

test('idempotencyKey: determinística — mesma entrada, mesma chave', function () {
    check_same(
        StripeClient::idempotencyKey('customer', '42', 'abc123'),
        StripeClient::idempotencyKey('customer', '42', 'abc123')
    );
});

test('idempotencyKey: entrada diferente, chave diferente', function () {
    // Mudou o e-mail (hash) → é OUTRO cliente; mudou o valor → OUTRO preço.
    check(
        StripeClient::idempotencyKey('customer', '42', 'abc123') !== StripeClient::idempotencyKey('customer', '42', 'def456'),
        'e-mail diferente precisa gerar chave diferente'
    );
    check(
        StripeClient::idempotencyKey('price', 'prod_1', 'month', '2990', 'brl') !== StripeClient::idempotencyKey('price', 'prod_1', 'month', '3990', 'brl'),
        'valor diferente precisa gerar chave diferente'
    );
});

test('idempotencyKey: legível quando cabe, com prefixo velk-', function () {
    check_same('velk-product-pro', StripeClient::idempotencyKey('product', 'pro'));
    check_same('velk-price-prod_1-month-2990-brl', StripeClient::idempotencyKey('price', 'prod_1', 'month', '2990', 'brl'));
});

test('idempotencyKey: entrada longa ou com caractere estranho vira hash, dentro do limite do Stripe', function () {
    $longa = StripeClient::idempotencyKey('customer', str_repeat('x', 300));
    check(strlen($longa) <= 255, 'Stripe aceita no máximo 255 chars');
    check(str_starts_with($longa, 'velk-customer-'), 'o prefixo sobrevive ao hash');

    $estranha = StripeClient::idempotencyKey('product', 'plano com espaço/é');
    check(preg_match('/^[A-Za-z0-9._-]+$/', $estranha) === 1, 'sem caractere fora do seguro');
});
