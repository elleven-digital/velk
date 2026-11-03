<?php

declare(strict_types=1);

use Velk\App;
use Velk\Config;
use Velk\Database;
use Velk\Models\Option;
use Velk\Services\StripeClient;
use Velk\Services\SubscriptionService;

/**
 * T4 (payment-webhook): SubscriptionService::syncFromStripeSubscription
 * dispara `subscription.<status>` pro payment_webhook do tema SÓ quando
 * MemberSubscription::upsertFromStripe aplicou de fato (retorno não-null)
 * E (status mudou OU o ts do evento avançou de verdade — tie-replay
 * hardening, ver design doc "Events → Subscriptions"). Renovação (mesmo
 * status, ts mais novo) DISPARA; um replay byte-idêntico (mesmo status,
 * mesmo ts) NÃO dispara de novo.
 *
 * Harness: App+Config via reflection (config_payment_webhook_test.php) +
 * Database SQLite em memória (order_test.php/order_service_test.php) com
 * as tabelas member_subscriptions + settings (Option::getGroup lê
 * `settings` pro cache _stripe_billing usado por planKeyForStripe — aqui
 * sempre vazio, então o plan_key cai pro fallback da metadata da
 * subscription). syncFromStripeSubscription é exercitado DIRETO com um
 * array $sub cru (sem StripeClient real) — só o teste de threading do
 * `stripe_event` passa por handleWebhook() de ponta a ponta (com
 * assinatura Stripe válida na unha, mesma técnica de
 * order_service_test.php), porque só handleWebhook() sabe o `type` do
 * evento Stripe.
 */

if (!function_exists('swt_app')) {
    function swt_app(array $site = []): App
    {
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, []);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, $site);

        $app = new App($config);

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec(
            'CREATE TABLE member_subscriptions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                member_id INTEGER NOT NULL,
                plan_key TEXT NOT NULL,
                billing_interval TEXT NOT NULL,
                status TEXT NOT NULL,
                stripe_customer_id TEXT NOT NULL,
                stripe_subscription_id TEXT NOT NULL,
                current_period_end TEXT NULL,
                cancel_at_period_end INTEGER NOT NULL DEFAULT 0,
                last_event_ts INTEGER NULL,
                created_at TEXT NOT NULL DEFAULT \'2026-01-01 00:00:00\',
                updated_at TEXT NOT NULL DEFAULT \'2026-01-01 00:00:00\'
            )'
        );
        $pdo->exec(
            'CREATE TABLE members (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL,
                stripe_customer_id TEXT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE settings (
                setting_key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            )'
        );

        $db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Database::class, 'pdo'))->setValue($db, $pdo);
        $app->db = $db;

        // O cache estático de Option sobrevive entre testes (mesmo processo
        // PHP) — sem isto, um valor de _stripe_billing lido num teste
        // anterior vazaria pra este DB novo.
        Option::flushCache();

        return $app;
    }
}

if (!function_exists('swt_sub')) {
    /** Objeto subscription do Stripe, formato mínimo que syncFromStripeSubscription lê. */
    function swt_sub(array $overrides = []): array
    {
        return array_merge([
            'id' => 'sub_test_1',
            'customer' => 'cus_test_1',
            'status' => 'active',
            'cancel_at_period_end' => false,
            'current_period_end' => 1750000000,
            'metadata' => ['member_id' => '5', 'plan_key' => 'pro'],
            'items' => ['data' => [
                ['price' => ['id' => 'price_1', 'product' => 'prod_1', 'recurring' => ['interval' => 'month']]],
            ]],
        ], $overrides);
    }
}

if (!function_exists('swt_recorder')) {
    function swt_recorder(string $event, array $data, array $context): void
    {
        $GLOBALS['swt_calls'][] = [$event, $data, $context];
    }
}

if (!function_exists('swt_throw')) {
    function swt_throw(string $event, array $data, array $context): void
    {
        throw new \RuntimeException('handler de teste propositalmente quebrado');
    }
}

// ─── (a) primeira sincronização: insere e dispara ──────────────────────

test('syncFromStripeSubscription: primeira sincronização (insere) dispara subscription.active com $data/$context exatos', function () {
    $GLOBALS['swt_calls'] = [];
    swt_app(['payment_webhook' => 'swt_recorder']);

    $service = new SubscriptionService(new StripeClient('sk_test_x'));
    $service->syncFromStripeSubscription(swt_sub(), 1000, 'customer.subscription.created');

    check_same(1, count($GLOBALS['swt_calls']), 'handler deve ser chamado exatamente uma vez');
    [$event, $data, $context] = $GLOBALS['swt_calls'][0];

    check_same('subscription.active', $event);
    check_same([
        'member_id' => 5,
        'plan_key' => 'pro',
        'status' => 'active',
        'previous_status' => null,
        'billing_interval' => 'month',
        'current_period_end' => gmdate('Y-m-d H:i:s', 1750000000),
        'cancel_at_period_end' => false,
    ], $data);
    check_same([
        'stripe_event' => 'customer.subscription.created',
        'event_ts' => 1000,
        'stripe_subscription_id' => 'sub_test_1',
        'stripe_customer_id' => 'cus_test_1',
    ], $context);
});

// ─── (b) tie replay: NÃO dispara de novo ───────────────────────────────

test('syncFromStripeSubscription: replay exato (mesmo status, mesmo ts) NÃO dispara de novo (tie-replay hardening)', function () {
    $GLOBALS['swt_calls'] = [];
    swt_app(['payment_webhook' => 'swt_recorder']);
    $service = new SubscriptionService(new StripeClient('sk_test_x'));

    $service->syncFromStripeSubscription(swt_sub(), 1000, 'customer.subscription.created');
    check_same(1, count($GLOBALS['swt_calls']), 'sanity: primeira sincronização disparou');

    // Retentativa Stripe byte-idêntica: mesmo $sub, mesmo ts. shouldApply
    // permite o empate (>=) e regrava, mas ts_advanced fica false e o
    // status não mudou — dispatch NÃO roda de novo.
    $service->syncFromStripeSubscription(swt_sub(), 1000, 'customer.subscription.created');

    check_same(1, count($GLOBALS['swt_calls']), 'replay exato não deve chamar o handler de novo');
});

// ─── (c) renovação: mesmo status, ts mais novo → dispara de novo ──────

test('syncFromStripeSubscription: renovação (mesmo status, ts mais novo) dispara de novo com previous_status = status atual', function () {
    $GLOBALS['swt_calls'] = [];
    swt_app(['payment_webhook' => 'swt_recorder']);
    $service = new SubscriptionService(new StripeClient('sk_test_x'));

    $service->syncFromStripeSubscription(swt_sub(), 1000, 'customer.subscription.created');
    check_same(1, count($GLOBALS['swt_calls']), 'sanity: primeira sincronização disparou');

    // Renovação Stripe: period_end avança, MESMO status ('active'), ts
    // estritamente maior — precisa disparar de novo (I2/tie-replay
    // hardening: renovação != replay).
    $renewed = swt_sub(['current_period_end' => 1752678400]);
    $service->syncFromStripeSubscription($renewed, 2000, 'customer.subscription.updated');

    check_same(2, count($GLOBALS['swt_calls']), 'renovação deve disparar de novo');
    [$event, $data, $context] = $GLOBALS['swt_calls'][1];
    check_same('subscription.active', $event);
    check_same('active', $data['previous_status']);
    check_same('active', $data['status']);
    check_same(gmdate('Y-m-d H:i:s', 1752678400), $data['current_period_end']);
    check_same('customer.subscription.updated', $context['stripe_event']);
    check_same(2000, $context['event_ts']);
});

// ─── (d) mudança de status: dispara com o novo evento e previous_status ─

test('syncFromStripeSubscription: mudança de status (active→canceled) dispara subscription.canceled com previous_status = "active"', function () {
    $GLOBALS['swt_calls'] = [];
    swt_app(['payment_webhook' => 'swt_recorder']);
    $service = new SubscriptionService(new StripeClient('sk_test_x'));

    $service->syncFromStripeSubscription(swt_sub(), 1000, 'customer.subscription.created');
    check_same(1, count($GLOBALS['swt_calls']), 'sanity: primeira sincronização disparou');

    $canceled = swt_sub(['status' => 'canceled']);
    $service->syncFromStripeSubscription($canceled, 2000, 'customer.subscription.deleted');

    check_same(2, count($GLOBALS['swt_calls']), 'mudança de status deve disparar de novo');
    [$event, $data, $context] = $GLOBALS['swt_calls'][1];
    check_same('subscription.canceled', $event);
    check_same('active', $data['previous_status']);
    check_same('canceled', $data['status']);
    check_same('customer.subscription.deleted', $context['stripe_event']);
});

// ─── (e) handler que lança não interrompe a sincronização ─────────────

test('syncFromStripeSubscription: handler que lança não interrompe a sincronização (grava mesmo assim)', function () {
    swt_app(['payment_webhook' => 'swt_throw']);
    $service = new SubscriptionService(new StripeClient('sk_test_x'));

    // Não deve lançar — o try/catch isola o dispatch da escrita, que já
    // aconteceu antes.
    $service->syncFromStripeSubscription(swt_sub(), 1000, 'customer.subscription.created');

    $row = App::instance()->db->fetch('SELECT status FROM member_subscriptions WHERE stripe_subscription_id = ?', ['sub_test_1']);
    check($row !== null, 'a escrita no banco deve acontecer mesmo com handler quebrado');
    check_same('active', $row['status']);
});

// ─── sem payment_webhook em site.json → nenhuma chamada, sem erro ─────

test('syncFromStripeSubscription: sem payment_webhook em site.json → nenhuma chamada, sem erro', function () {
    swt_app([]);
    $service = new SubscriptionService(new StripeClient('sk_test_x'));

    $service->syncFromStripeSubscription(swt_sub(), 1000, 'customer.subscription.created');

    $row = App::instance()->db->fetch('SELECT status FROM member_subscriptions WHERE stripe_subscription_id = ?', ['sub_test_1']);
    check($row !== null);
});

// ─── evento obsoleto (ts mais antigo) NÃO dispara ──────────────────────

test('syncFromStripeSubscription: evento obsoleto (ts mais antigo que o já aplicado) NÃO dispara', function () {
    $GLOBALS['swt_calls'] = [];
    swt_app(['payment_webhook' => 'swt_recorder']);
    $service = new SubscriptionService(new StripeClient('sk_test_x'));

    $service->syncFromStripeSubscription(swt_sub(), 2000, 'customer.subscription.created');
    check_same(1, count($GLOBALS['swt_calls']), 'sanity: primeira sincronização disparou');

    $service->syncFromStripeSubscription(swt_sub(['status' => 'past_due']), 1000, 'customer.subscription.updated');

    check_same(1, count($GLOBALS['swt_calls']), 'evento obsoleto não deve chamar o handler de novo');
});

// ─── threading do stripe_event via handleWebhook (ponta a ponta) ──────

if (!function_exists('swt_sig_header')) {
    function swt_sig_header(string $payload, string $secret): string
    {
        $ts = time();
        $sig = hash_hmac('sha256', $ts . '.' . $payload, $secret);
        return 't=' . $ts . ',v1=' . $sig;
    }
}

test('handleWebhook: threading do stripe_event — customer.subscription.updated chega em $context[stripe_event]', function () {
    $GLOBALS['swt_calls'] = [];
    $secret = 'whsec_test_sub';
    swt_app(['payment_webhook' => 'swt_recorder']);

    $sub = swt_sub();
    $payload = json_encode([
        'id' => 'evt_test_' . uniqid(),
        'type' => 'customer.subscription.updated',
        'created' => 3000,
        'data' => ['object' => $sub],
    ]);
    $sig = swt_sig_header($payload, $secret);

    $service = new SubscriptionService(new StripeClient('sk_test_x', $secret));
    $ok = $service->handleWebhook($payload, $sig);

    check(true === $ok);
    check_same(1, count($GLOBALS['swt_calls']));
    [$event, $data, $context] = $GLOBALS['swt_calls'][0];
    check_same('subscription.active', $event);
    check_same('customer.subscription.updated', $context['stripe_event']);
});
