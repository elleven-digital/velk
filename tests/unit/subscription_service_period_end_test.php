<?php

declare(strict_types=1);

use Velk\Services\SubscriptionService;

/**
 * I11: from Stripe API version 2025-03-31.basil on, webhook payloads no
 * longer render `current_period_end` at the subscription root — it moved
 * under `items.data[0].current_period_end` (billing is per-item now).
 * currentPeriodEndFromStripe() is the pure selection logic: root wins when
 * present (older API versions / already-flat payloads), else fall back to
 * the first item. gmdate/UTC conversion itself happens at the call site and
 * is untouched here.
 */

test('currentPeriodEndFromStripe: usa a raiz quando presente (API antiga)', function () {
    $sub = ['current_period_end' => 1735689600, 'items' => ['data' => [['current_period_end' => 999]]]];
    check_same(1735689600, SubscriptionService::currentPeriodEndFromStripe($sub));
});

test('currentPeriodEndFromStripe: cai pro item quando a raiz está ausente (API 2025-03-31.basil+)', function () {
    $sub = ['items' => ['data' => [['current_period_end' => 1735689600]]]];
    check_same(1735689600, SubscriptionService::currentPeriodEndFromStripe($sub));
});

test('currentPeriodEndFromStripe: cai pro item quando a raiz é explicitamente null', function () {
    $sub = ['current_period_end' => null, 'items' => ['data' => [['current_period_end' => 1735689600]]]];
    check_same(1735689600, SubscriptionService::currentPeriodEndFromStripe($sub));
});

test('currentPeriodEndFromStripe: null quando nem raiz nem item têm o campo', function () {
    check_same(null, SubscriptionService::currentPeriodEndFromStripe([]));
    check_same(null, SubscriptionService::currentPeriodEndFromStripe(['items' => ['data' => [[]]]]));
    check_same(null, SubscriptionService::currentPeriodEndFromStripe(['items' => ['data' => []]]));
});

test('currentPeriodEndFromStripe: converte string numérica pra int (payload real do Stripe é sempre int, mas é defensivo)', function () {
    check_same(1735689600, SubscriptionService::currentPeriodEndFromStripe(['current_period_end' => '1735689600']));
});

test('current_period_end aplicado ao webhook: gmdate/UTC preservado no fim-a-fim', function () {
    // Exercita a expressão exata usada no call site (sem tocar DB): dado
    // um $sub só com o campo no item (payload novo), a data final em UTC
    // deve bater com gmdate sobre o epoch do item.
    $sub = ['items' => ['data' => [['current_period_end' => 1735689600]]]];
    $periodEnd = SubscriptionService::currentPeriodEndFromStripe($sub);
    check($periodEnd !== null);
    check_same(gmdate('Y-m-d H:i:s', 1735689600), gmdate('Y-m-d H:i:s', $periodEnd));
    check_same('2025-01-01 00:00:00', gmdate('Y-m-d H:i:s', $periodEnd));
});
