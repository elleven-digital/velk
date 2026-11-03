<?php

declare(strict_types=1);

use Velk\App;
use Velk\Config;
use Velk\Database;
use Velk\Models\Order;

/**
 * T3 (payment-webhook): além dos testes puros de isTerminalStatus/shouldApply
 * já existentes, cobre o valor de retorno de markStatusFromWebhook() (status
 * aplicado, ou null nos 3 caminhos de skip) e o novo finder
 * findByStripeSession(). Harness SQLite em memória — mesma técnica de
 * variant_resolution_test.php (Config via reflection sem construtor,
 * Database com PDO sqlite injetado na propriedade privada `pdo`).
 */

if (!function_exists('ot_app')) {
    function ot_app(): App
    {
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, []);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, []);

        $app = new App($config);

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec(
            'CREATE TABLE orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                member_id INTEGER NULL,
                email TEXT NOT NULL DEFAULT \'\',
                status TEXT NOT NULL,
                currency TEXT NOT NULL,
                total_amount INTEGER NOT NULL,
                stripe_session_id TEXT NOT NULL,
                stripe_payment_intent TEXT NULL,
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

if (!function_exists('ot_insert_order')) {
    /** @return int id do pedido inserido */
    function ot_insert_order(array $overrides = []): int
    {
        return App::instance()->db->insert('orders', array_merge([
            'member_id' => null,
            'email' => '',
            'status' => 'pending',
            'currency' => 'BRL',
            'total_amount' => 1000,
            'stripe_session_id' => 'cs_test_' . uniqid(),
            'stripe_payment_intent' => null,
            'last_event_ts' => null,
        ], $overrides));
    }
}

// ─── markStatusFromWebhook: valor de retorno ───────────────────────────

test('markStatusFromWebhook: transição aplicada retorna o status aplicado', function () {
    ot_app();
    $sessionId = 'cs_test_applied';
    ot_insert_order(['stripe_session_id' => $sessionId, 'status' => 'pending']);

    $result = Order::markStatusFromWebhook($sessionId, 'paid', 1000, 'pi_123', 'buyer@example.com');

    check_same('paid', $result);
    $row = App::instance()->db->fetch('SELECT status, last_event_ts, stripe_payment_intent, email FROM orders WHERE stripe_session_id = ?', [$sessionId]);
    check_same('paid', $row['status']);
    check_same(1000, (int) $row['last_event_ts']);
    check_same('pi_123', $row['stripe_payment_intent']);
    check_same('buyer@example.com', $row['email']);
});

test('markStatusFromWebhook: reaplicar o mesmo status com ts MAIS NOVO atualiza o banco mas retorna null (tie-replay hardening)', function () {
    ot_app();
    $sessionId = 'cs_test_reapply_newer_ts';
    ot_insert_order([
        'stripe_session_id' => $sessionId,
        'status' => 'paid',
        'last_event_ts' => 1000,
        'email' => '',
        'stripe_payment_intent' => null,
    ]);

    // Segundo evento Stripe DIFERENTE (ts mais novo) confirmando o MESMO
    // status (ex.: completed(paid) seguido de async_payment_succeeded(paid)).
    // shouldApply permite (ts avançou), guarda terminal permite (paid->paid),
    // o banco é atualizado — mas não é uma transição, então o retorno é null.
    $result = Order::markStatusFromWebhook($sessionId, 'paid', 2000, 'pi_new', 'buyer@example.com');

    check_same(null, $result);
    $row = App::instance()->db->fetch('SELECT status, last_event_ts, stripe_payment_intent, email FROM orders WHERE stripe_session_id = ?', [$sessionId]);
    check_same('paid', $row['status']);
    check_same(2000, (int) $row['last_event_ts']);
    check_same('pi_new', $row['stripe_payment_intent']);
    check_same('buyer@example.com', $row['email']);
});

test('markStatusFromWebhook: replay exato (mesmo ts, mesmo status) retorna null e não escreve', function () {
    ot_app();
    $sessionId = 'cs_test_exact_tie';
    ot_insert_order([
        'stripe_session_id' => $sessionId,
        'status' => 'paid',
        'last_event_ts' => 1000,
        'stripe_payment_intent' => 'pi_original',
        'email' => 'original@example.com',
    ]);

    // Retentativa Stripe byte-idêntica: mesmo evento, mesmo `created`.
    // shouldApply usa >= de propósito (permite), mas como é o MESMO status
    // já gravado, o retorno deve ser null (não é uma transição).
    $result = Order::markStatusFromWebhook($sessionId, 'paid', 1000, 'pi_original', 'original@example.com');

    check_same(null, $result);
    $row = App::instance()->db->fetch('SELECT status, last_event_ts FROM orders WHERE stripe_session_id = ?', [$sessionId]);
    check_same('paid', $row['status']);
    check_same(1000, (int) $row['last_event_ts']);
});

test('markStatusFromWebhook: timestamp obsoleto (mais antigo que o já aplicado) retorna null e não escreve', function () {
    ot_app();
    $sessionId = 'cs_test_stale';
    ot_insert_order(['stripe_session_id' => $sessionId, 'status' => 'paid', 'last_event_ts' => 2000]);

    $result = Order::markStatusFromWebhook($sessionId, 'paid', 1000, null, null);

    check_same(null, $result);
    $row = App::instance()->db->fetch('SELECT status, last_event_ts FROM orders WHERE stripe_session_id = ?', [$sessionId]);
    check_same('paid', $row['status']);
    check_same(2000, (int) $row['last_event_ts']);
});

test('markStatusFromWebhook: guarda de estado terminal (pedido pago não regride) retorna null e não escreve', function () {
    ot_app();
    $sessionId = 'cs_test_terminal';
    ot_insert_order(['stripe_session_id' => $sessionId, 'status' => 'paid', 'last_event_ts' => 1000]);

    // newStatus não-terminal chegando DEPOIS (ts maior) — ainda assim não regride pedido pago.
    $result = Order::markStatusFromWebhook($sessionId, 'pending', 2000, null, null);

    check_same(null, $result);
    $row = App::instance()->db->fetch('SELECT status, last_event_ts FROM orders WHERE stripe_session_id = ?', [$sessionId]);
    check_same('paid', $row['status']);
    check_same(1000, (int) $row['last_event_ts']);
});

test('markStatusFromWebhook: sessão desconhecida retorna null', function () {
    ot_app();
    $result = Order::markStatusFromWebhook('cs_test_does_not_exist', 'paid', 1000, null, null);
    check_same(null, $result);
});

// ─── findByStripeSession ────────────────────────────────────────────────

test('findByStripeSession: encontra o pedido pela session id do Stripe', function () {
    ot_app();
    $id = ot_insert_order(['stripe_session_id' => 'cs_test_find', 'status' => 'paid', 'total_amount' => 4990]);

    $order = Order::findByStripeSession('cs_test_find');

    check($order !== null);
    check_same($id, $order->id);
    check_same('paid', $order->status);
    check_same(4990, $order->totalAmount);
});

test('findByStripeSession: session inexistente retorna null', function () {
    ot_app();
    check_same(null, Order::findByStripeSession('cs_test_missing'));
});

test('isTerminalStatus: paid/expired são terminais', function () {
    check(true === Order::isTerminalStatus('paid'));
    check(true === Order::isTerminalStatus('expired'));
    check(false === Order::isTerminalStatus('pending'));
    check(false === Order::isTerminalStatus(''));
});

test('shouldApply: sem timestamp anterior aplica', function () {
    check(true === Order::shouldApply(null, 1000));
});

test('shouldApply: evento novo ou igual aplica; mais antigo não', function () {
    check(true === Order::shouldApply(1000, 1000));
    check(true === Order::shouldApply(1000, 1001));
    check(false === Order::shouldApply(1000, 999));
});
