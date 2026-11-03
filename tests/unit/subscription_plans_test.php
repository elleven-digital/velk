<?php

declare(strict_types=1);

use Velk\Services\SubscriptionService;

function fixture_declared(): array
{
    return [
        'basico' => [
            'name' => 'Básico',
            'description' => 'Essencial.',
            'features' => ['A', 'B'],
            'intervals' => ['month', 'year'],
        ],
        'pro' => [
            'name' => 'Pro',
            'features' => ['Tudo + C'],
            'intervals' => ['month'],
        ],
    ];
}

test('buildPlans: monta preços e formatação por intervalo', function () {
    $values = [
        'basico_active' => true,
        'basico_price_month' => '29,90',
        'basico_price_year' => '299,00',
        'pro_active' => true,
        'pro_price_month' => '59,90',
        'currency' => 'BRL',
    ];
    $plans = SubscriptionService::buildPlans(fixture_declared(), $values, true);

    check_same(2, count($plans));
    check_same('basico', $plans[0]['key']);
    check_same('Básico', $plans[0]['name']);
    check_same(2990, $plans[0]['prices']['month']['amount']);
    check_same('R$ 29,90', $plans[0]['prices']['month']['formatted']);
    check_same(29900, $plans[0]['prices']['year']['amount']);
    // Pro só declara 'month' → não deve ter 'year'.
    check_same(false, isset($plans[1]['prices']['year']));
});

test('buildPlans: onlyActive remove inativos', function () {
    $values = [
        'basico_active' => false,
        'basico_price_month' => '29,90',
        'pro_active' => true,
        'pro_price_month' => '59,90',
        'currency' => 'BRL',
    ];
    $plans = SubscriptionService::buildPlans(fixture_declared(), $values, true);
    check_same(1, count($plans));
    check_same('pro', $plans[0]['key']);
});

test('buildPlans: onlyActive remove plano ativo mas sem preço', function () {
    $values = ['basico_active' => true, 'pro_active' => true, 'currency' => 'BRL'];
    $plans = SubscriptionService::buildPlans(fixture_declared(), $values, true);
    check_same(0, count($plans));
});

test('buildPlans: onlyActive=false mantém tudo (para o admin)', function () {
    $values = ['currency' => 'BRL'];
    $plans = SubscriptionService::buildPlans(fixture_declared(), $values, false);
    check_same(2, count($plans));
});

test('priceCacheLookup: hit quando o valor bate', function () {
    $cache = ['basico' => ['month' => ['amount' => 2990, 'price_id' => 'price_abc']]];
    check_same('price_abc', SubscriptionService::priceCacheLookup($cache, 'basico', 'month', 2990));
});

test('priceCacheLookup: miss quando o valor mudou', function () {
    $cache = ['basico' => ['month' => ['amount' => 2990, 'price_id' => 'price_abc']]];
    check_same(null, SubscriptionService::priceCacheLookup($cache, 'basico', 'month', 3990));
});

test('priceCacheLookup: miss quando não há entrada', function () {
    check_same(null, SubscriptionService::priceCacheLookup([], 'basico', 'month', 2990));
});

test('planKeyForStripe: match por product_id', function () {
    $cache = ['pro' => ['product_id' => 'prod_X', 'month' => ['amount' => 5990, 'price_id' => 'price_M']]];
    check_same('pro', SubscriptionService::planKeyForStripe($cache, 'prod_X', null));
});

test('planKeyForStripe: match por price_id (mês ou ano)', function () {
    $cache = ['pro' => ['product_id' => 'prod_X', 'year' => ['amount' => 59900, 'price_id' => 'price_Y']]];
    check_same('pro', SubscriptionService::planKeyForStripe($cache, null, 'price_Y'));
});

test('planKeyForStripe: sem match retorna null (cai para metadata)', function () {
    $cache = ['pro' => ['product_id' => 'prod_X', 'month' => ['amount' => 5990, 'price_id' => 'price_M']]];
    check_same(null, SubscriptionService::planKeyForStripe($cache, 'prod_OUTRO', 'price_OUTRO'));
});
