<?php

declare(strict_types=1);

use Velk\Config;

/**
 * Revisão de pagamentos 2026-09: o recurso só liga com o webhook secret.
 *
 * Até aqui bastava STRIPE_SECRET_KEY. Com ela sozinha o checkout abre e o
 * Stripe COBRA — mas todo webhook chega sem assinatura válida, é rejeitado
 * com 400, e o membro paga sem nunca receber acesso (pedido fica pending).
 * Meio configurado tem que parecer desligado, não quebrado.
 */

if (!function_exists('peg_config')) {
    /** @param array<string,mixed> $site */
    function peg_config(array $site): Config
    {
        $c = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($c, ['paths' => []]);
        (new ReflectionProperty(Config::class, 'site'))->setValue($c, $site);
        return $c;
    }
}

if (!function_exists('peg_env')) {
    /** Define exatamente estas chaves Stripe no ambiente (as outras ficam vazias). */
    function peg_env(array $set): void
    {
        foreach (['STRIPE_SECRET_KEY', 'STRIPE_WEBHOOK_SECRET', 'STRIPE_STORE_WEBHOOK_SECRET'] as $k) {
            $_ENV[$k] = $set[$k] ?? '';
        }
    }
}

$subs  = ['members' => ['enabled' => true, 'plans' => ['pro' => ['name' => 'Pro']]]];
$store = ['item_types' => ['ebook' => ['label' => 'E-books', 'sellable' => true, 'template' => 't.php', 'slug' => 'ebooks']]];

test('assinaturas: só a chave secreta NÃO liga', function () use ($subs) {
    peg_env(['STRIPE_SECRET_KEY' => 'sk_test_x']);
    check_same(false, peg_config($subs)->isSubscriptionsEnabled(), 'sem webhook secret o checkout cobraria sem entregar');
});

test('assinaturas: chave + webhook secret liga', function () use ($subs) {
    peg_env(['STRIPE_SECRET_KEY' => 'sk_test_x', 'STRIPE_WEBHOOK_SECRET' => 'whsec_x']);
    check_same(true, peg_config($subs)->isSubscriptionsEnabled());
});

test('assinaturas: o secret da LOJA não serve para assinaturas', function () use ($subs) {
    peg_env(['STRIPE_SECRET_KEY' => 'sk_test_x', 'STRIPE_STORE_WEBHOOK_SECRET' => 'whsec_store']);
    check_same(false, peg_config($subs)->isSubscriptionsEnabled(), 'são dois endpoints, dois secrets');
});

test('loja: só a chave secreta NÃO liga', function () use ($store) {
    peg_env(['STRIPE_SECRET_KEY' => 'sk_test_x']);
    check_same(false, peg_config($store)->isStoreEnabled());
});

test('loja: chave + secret da loja liga', function () use ($store) {
    peg_env(['STRIPE_SECRET_KEY' => 'sk_test_x', 'STRIPE_STORE_WEBHOOK_SECRET' => 'whsec_store']);
    check_same(true, peg_config($store)->isStoreEnabled());
});

test('loja: o secret de ASSINATURA não serve para a loja', function () use ($store) {
    peg_env(['STRIPE_SECRET_KEY' => 'sk_test_x', 'STRIPE_WEBHOOK_SECRET' => 'whsec_x']);
    check_same(false, peg_config($store)->isStoreEnabled());
});

test('sem site.json declarando o recurso, nenhuma chave liga nada', function () {
    peg_env(['STRIPE_SECRET_KEY' => 'sk_test_x', 'STRIPE_WEBHOOK_SECRET' => 'whsec_x', 'STRIPE_STORE_WEBHOOK_SECRET' => 'whsec_store']);
    check_same(false, peg_config([])->isSubscriptionsEnabled());
    check_same(false, peg_config([])->isStoreEnabled());
});

peg_env([]);
