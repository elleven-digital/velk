<?php

declare(strict_types=1);

use Velk\App;
use Velk\Config;
use Velk\Database;
use Velk\Models\MemberSubscription;

/**
 * T4 (payment-webhook): harness SQLite em memória pra exercitar
 * upsertFromStripe() fim-a-fim (mesma técnica de order_test.php — Config
 * via reflection sem construtor, Database com PDO sqlite injetado). Cobre
 * o novo retorno `?array{previous_status: ?string, ts_advanced: bool}`
 * usado pelo dispatch do subscription.<status> em SubscriptionService.
 */
if (!function_exists('mst_app')) {
    function mst_app(): App
    {
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, []);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, []);

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

        $db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Database::class, 'pdo'))->setValue($db, $pdo);
        $app->db = $db;

        return $app;
    }
}

if (!function_exists('mst_insert')) {
    /** @return int id da assinatura inserida */
    function mst_insert(array $overrides = []): int
    {
        return App::instance()->db->insert('member_subscriptions', array_merge([
            'member_id' => 1,
            'plan_key' => 'pro',
            'billing_interval' => 'month',
            'status' => 'active',
            'stripe_customer_id' => 'cus_test',
            'stripe_subscription_id' => 'sub_test',
            'current_period_end' => null,
            'cancel_at_period_end' => 0,
            'last_event_ts' => 1000,
        ], $overrides));
    }
}

if (!function_exists('mst_data')) {
    /**
     * Payload de upsert, no mesmo formato que SubscriptionService monta
     * (inclui last_event_ts — SubscriptionService::syncFromStripeSubscription
     * grava `'last_event_ts' => $eventTs` no MESMO array passado ao upsert,
     * então os testes replicam isso: o eventTs do 2º argumento deve bater
     * com `last_event_ts` aqui, senão a coluna não é atualizada).
     */
    function mst_data(array $overrides = []): array
    {
        return array_merge([
            'member_id' => 1,
            'plan_key' => 'pro',
            'billing_interval' => 'month',
            'status' => 'active',
            'stripe_customer_id' => 'cus_test',
            'stripe_subscription_id' => 'sub_test',
            'current_period_end' => null,
            'cancel_at_period_end' => 0,
            'last_event_ts' => 1000,
        ], $overrides);
    }
}

test('isActiveStatus: active/trialing/past_due concedem acesso', function () {
    check(true === MemberSubscription::isActiveStatus('active'));
    check(true === MemberSubscription::isActiveStatus('trialing'));
    check(true === MemberSubscription::isActiveStatus('past_due'));
});

test('isActiveStatus: canceled/unpaid/incomplete não concedem', function () {
    check(false === MemberSubscription::isActiveStatus('canceled'));
    check(false === MemberSubscription::isActiveStatus('unpaid'));
    check(false === MemberSubscription::isActiveStatus('incomplete'));
    check(false === MemberSubscription::isActiveStatus('incomplete_expired'));
    check(false === MemberSubscription::isActiveStatus(''));
});

test('isTerminalStatus: canceled/incomplete_expired são terminais', function () {
    check(true === MemberSubscription::isTerminalStatus('canceled'));
    check(true === MemberSubscription::isTerminalStatus('incomplete_expired'));
    check(false === MemberSubscription::isTerminalStatus('active'));
    check(false === MemberSubscription::isTerminalStatus('past_due'));
});

test('shouldApply: aplica quando não há timestamp anterior', function () {
    check(true === MemberSubscription::shouldApply(null, 1000));
});

test('shouldApply: aplica evento mais novo ou de mesmo timestamp', function () {
    check(true === MemberSubscription::shouldApply(1000, 1000));
    check(true === MemberSubscription::shouldApply(1000, 1001));
});

test('shouldApply: descarta evento mais antigo (out-of-order)', function () {
    check(false === MemberSubscription::shouldApply(1000, 999));
});

// M6: upsertFromStripe() SELECT-then-INSERT races on two near-simultaneous
// first events for the same NEW subscription (checkout.session.completed +
// customer.subscription.created) — both SELECT-miss, both try to INSERT,
// the loser hits the UNIQUE key on stripe_subscription_id (MySQL 1062).
// isDuplicateKeyError() is the pure predicate the fix uses to detect that
// specific failure (vs. any other PDOException, which must still bubble
// up) so it can converge to an update instead of a raw 500. Same style as
// Installer::isUnknownDatabaseError() (errorInfo[1] first, message
// substring as a defensive fallback).
test('isDuplicateKeyError: true pra 1062 via errorInfo (caminho real do PDO/MySQL)', function () {
    $e = new \PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'sub_123' for key 'stripe_subscription_id'");
    $e->errorInfo = ['23000', 1062, "Duplicate entry 'sub_123' for key 'stripe_subscription_id'"];
    check(true === MemberSubscription::isDuplicateKeyError($e));
});

test('isDuplicateKeyError: false pra outras PDOException (não pode engolir erro real)', function () {
    $e = new \PDOException('SQLSTATE[42S22]: Column not found: Unknown column');
    $e->errorInfo = ['42S22', 1054, "Unknown column 'x' in 'field list'"];
    check(false === MemberSubscription::isDuplicateKeyError($e));
});

test('isDuplicateKeyError: false quando não há errorInfo nem menção a duplicate na mensagem', function () {
    $e = new \PDOException('SQLSTATE[HY000] [2002] Connection refused');
    check(false === MemberSubscription::isDuplicateKeyError($e));
});

test('isDuplicateKeyError: fallback defensivo por mensagem quando errorInfo está ausente', function () {
    $e = new \PDOException("SQLSTATE[23000]: Duplicate entry 'sub_123' for key 'stripe_subscription_id'");
    check(true === MemberSubscription::isDuplicateKeyError($e));
});

// ─── upsertFromStripe: retorno ?array{previous_status, ts_advanced} ────
//
// T4 (payment-webhook): SubscriptionService dispara subscription.<status>
// só quando upsertFromStripe aplicou de fato (não-null) E (status mudou OU
// o ts do evento avançou de verdade — tie-replay hardening). Os 4 caminhos
// abaixo cobrem insert/update/stale/terminal com um SQLite real; o quinto
// caminho (corrida de INSERT duplicado, M6) não é reproduzível
// deterministicamente com uma única conexão síncrona — mesma limitação já
// aceita no commit original do M6 (só a predicate pura isDuplicateKeyError
// acima é testada com DB real); o code path do catch cai nas MESMAS guardas
// abaixo, então o retorno segue a mesma forma.

test('upsertFromStripe: insert de assinatura NOVA → previous_status null, ts_advanced true', function () {
    mst_app();
    $data = mst_data(['stripe_subscription_id' => 'sub_new', 'status' => 'active']);

    $result = MemberSubscription::upsertFromStripe($data, 1000);

    check_same(['previous_status' => null, 'ts_advanced' => true], $result);
    $row = App::instance()->db->fetch('SELECT status, last_event_ts FROM member_subscriptions WHERE stripe_subscription_id = ?', ['sub_new']);
    check($row !== null);
    check_same('active', $row['status']);
});

test('upsertFromStripe: update com ts MAIS NOVO e MESMO status (renovação) → applied, ts_advanced true, previous_status = status anterior', function () {
    mst_app();
    mst_insert(['stripe_subscription_id' => 'sub_renew', 'status' => 'active', 'last_event_ts' => 1000]);

    $data = mst_data(['stripe_subscription_id' => 'sub_renew', 'status' => 'active', 'current_period_end' => '2026-08-01 00:00:00', 'last_event_ts' => 2000]);
    $result = MemberSubscription::upsertFromStripe($data, 2000);

    check_same(['previous_status' => 'active', 'ts_advanced' => true], $result);
    $row = App::instance()->db->fetch('SELECT status, last_event_ts, current_period_end FROM member_subscriptions WHERE stripe_subscription_id = ?', ['sub_renew']);
    check_same('active', $row['status']);
    check_same(2000, (int) $row['last_event_ts']);
    check_same('2026-08-01 00:00:00', $row['current_period_end']);
});

test('upsertFromStripe: update com ts EMPATADO e MESMO status (tie replay) → applied, mas ts_advanced false', function () {
    mst_app();
    mst_insert(['stripe_subscription_id' => 'sub_tie', 'status' => 'active', 'last_event_ts' => 1000]);

    $data = mst_data(['stripe_subscription_id' => 'sub_tie', 'status' => 'active']);
    $result = MemberSubscription::upsertFromStripe($data, 1000);

    // shouldApply usa >= de propósito (aplica o empate — grava de novo), mas
    // como o ts não avançou de verdade, ts_advanced é false: é esse sinal
    // que deixa o dispatch do webhook diferenciar renovação de replay puro.
    check_same(['previous_status' => 'active', 'ts_advanced' => false], $result);
    $row = App::instance()->db->fetch('SELECT status, last_event_ts FROM member_subscriptions WHERE stripe_subscription_id = ?', ['sub_tie']);
    check_same('active', $row['status']);
    check_same(1000, (int) $row['last_event_ts']);
});

test('upsertFromStripe: mudança de status com ts mais novo → applied, ts_advanced true, previous_status = status antigo', function () {
    mst_app();
    mst_insert(['stripe_subscription_id' => 'sub_change', 'status' => 'active', 'last_event_ts' => 1000]);

    $data = mst_data(['stripe_subscription_id' => 'sub_change', 'status' => 'canceled', 'last_event_ts' => 2000]);
    $result = MemberSubscription::upsertFromStripe($data, 2000);

    check_same(['previous_status' => 'active', 'ts_advanced' => true], $result);
    $row = App::instance()->db->fetch('SELECT status FROM member_subscriptions WHERE stripe_subscription_id = ?', ['sub_change']);
    check_same('canceled', $row['status']);
});

test('upsertFromStripe: ts obsoleto (mais antigo que o já aplicado) → null, não escreve', function () {
    mst_app();
    mst_insert(['stripe_subscription_id' => 'sub_stale', 'status' => 'active', 'last_event_ts' => 2000]);

    $data = mst_data(['stripe_subscription_id' => 'sub_stale', 'status' => 'past_due']);
    $result = MemberSubscription::upsertFromStripe($data, 1000);

    check_same(null, $result);
    $row = App::instance()->db->fetch('SELECT status, last_event_ts FROM member_subscriptions WHERE stripe_subscription_id = ?', ['sub_stale']);
    check_same('active', $row['status']);
    check_same(2000, (int) $row['last_event_ts']);
});

test('upsertFromStripe: guarda de estado terminal (canceled não ressuscita) → null, não escreve', function () {
    mst_app();
    mst_insert(['stripe_subscription_id' => 'sub_terminal', 'status' => 'canceled', 'last_event_ts' => 1000]);

    $data = mst_data(['stripe_subscription_id' => 'sub_terminal', 'status' => 'active']);
    $result = MemberSubscription::upsertFromStripe($data, 2000);

    check_same(null, $result);
    $row = App::instance()->db->fetch('SELECT status FROM member_subscriptions WHERE stripe_subscription_id = ?', ['sub_terminal']);
    check_same('canceled', $row['status']);
});
