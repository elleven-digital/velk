<?php

declare(strict_types=1);

use Velk\App;
use Velk\Config;
use Velk\Database;
use Velk\Models\Order;
use Velk\Services\OrderService;
use Velk\Services\StripeClient;

/**
 * T3 (payment-webhook): OrderService::handleWebhook dispara `order.<status>`
 * pro payment_webhook do tema SOMENTE quando Order::markStatusFromWebhook
 * de fato aplica a transição (retorno não-null) — reentregas/eventos fora
 * de ordem que a guarda de idempotência descarta não disparam de novo.
 *
 * Harness: App+Config via reflection (mesma técnica de
 * config_payment_webhook_test.php, combinada com o Database SQLite em
 * memória de variant_resolution_test.php/order_test.php) + um StripeClient
 * de verdade (secret conhecido) pra gerar uma assinatura Stripe-Signature
 * válida na unha — sem mocks, sem rede.
 */

if (!function_exists('ost_app')) {
    /** App com Config (site.json em memória) + DB SQLite (orders/order_items). */
    function ost_app(array $site = []): App
    {
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, []);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, $site);

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
        $pdo->exec(
            'CREATE TABLE order_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id INTEGER NOT NULL,
                item_id INTEGER NULL,
                item_type TEXT NOT NULL,
                title TEXT NOT NULL,
                unit_amount INTEGER NOT NULL,
                quantity INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL DEFAULT \'2026-01-01 00:00:00\'
            )'
        );

        $db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Database::class, 'pdo'))->setValue($db, $pdo);
        $app->db = $db;

        return $app;
    }
}

if (!function_exists('ost_insert_order')) {
    /** @return int id do pedido inserido */
    function ost_insert_order(array $overrides = []): int
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
            'created_at' => '2026-01-01 00:00:00',
        ], $overrides));
    }
}

if (!function_exists('ost_insert_item')) {
    function ost_insert_item(int $orderId, array $overrides = []): void
    {
        App::instance()->db->insert('order_items', array_merge([
            'order_id' => $orderId,
            'item_id' => null,
            'item_type' => 'produto',
            'title' => 'Item',
            'unit_amount' => 1000,
            'quantity' => 1,
        ], $overrides));
    }
}

if (!function_exists('ost_payload')) {
    /** Corpo bruto de um evento Stripe checkout.session.* (JSON). */
    function ost_payload(string $sessionId, int $createdTs, string $type = 'checkout.session.completed', array $objectOverrides = []): string
    {
        $object = array_merge([
            'id' => $sessionId,
            'mode' => 'payment',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_test_123',
            'customer_details' => ['email' => 'buyer@example.com'],
        ], $objectOverrides);

        return json_encode([
            'id' => 'evt_test_' . uniqid(),
            'type' => $type,
            'created' => $createdTs,
            'data' => ['object' => $object],
        ]);
    }
}

if (!function_exists('ost_sig_header')) {
    /** Cabeçalho Stripe-Signature válido pro payload, na mesma unha de StripeClient::verifyWebhookSignature. */
    function ost_sig_header(string $payload, string $secret): string
    {
        $ts = time();
        $sig = hash_hmac('sha256', $ts . '.' . $payload, $secret);
        return 't=' . $ts . ',v1=' . $sig;
    }
}

if (!function_exists('payment_webhook_test_recorder')) {
    function payment_webhook_test_recorder(string $event, array $data, array $context): void
    {
        $GLOBALS['payment_webhook_test_calls'][] = [$event, $data, $context];
    }
}

if (!function_exists('payment_webhook_test_throw')) {
    function payment_webhook_test_throw(string $event, array $data, array $context): void
    {
        throw new \RuntimeException('handler de teste propositalmente quebrado');
    }
}

// ─── handleWebhook: dispatch do payment_webhook em transições aplicadas ──

test('handleWebhook: evento aplicado dispara order.paid UMA vez, com $data/$context exatos', function () {
    $GLOBALS['payment_webhook_test_calls'] = [];
    $secret = 'whsec_test_a';
    $stripe = new StripeClient('sk_test_x', $secret);

    ost_app(['payment_webhook' => 'payment_webhook_test_recorder']);

    $orderId = ost_insert_order([
        'member_id' => 5,
        'email' => '',
        'status' => 'pending',
        'currency' => 'BRL',
        'total_amount' => 6980,
        'stripe_session_id' => 'cs_test_abc',
        'created_at' => '2026-01-01 00:00:00',
    ]);
    ost_insert_item($orderId, ['item_id' => 10, 'item_type' => 'produto', 'title' => 'Camiseta', 'unit_amount' => 4990, 'quantity' => 1]);
    ost_insert_item($orderId, ['item_id' => 11, 'item_type' => 'produto', 'title' => 'Boné', 'unit_amount' => 1990, 'quantity' => 1]);

    $payload = ost_payload('cs_test_abc', 1750000000);
    $sig = ost_sig_header($payload, $secret);

    $service = new OrderService($stripe);
    $ok = $service->handleWebhook($payload, $sig);

    check(true === $ok, 'handleWebhook deve retornar true (Stripe precisa receber 2xx)');
    check_same(1, count($GLOBALS['payment_webhook_test_calls']), 'handler deve ser chamado exatamente uma vez');

    [$event, $data, $context] = $GLOBALS['payment_webhook_test_calls'][0];
    check_same('order.paid', $event);
    check_same([
        'order' => [
            'id' => $orderId,
            'member_id' => 5,
            'email' => 'buyer@example.com',
            'status' => 'paid',
            'currency' => 'BRL',
            'total_amount' => 6980,
            'stripe_session_id' => 'cs_test_abc',
            'stripe_payment_intent' => 'pi_test_123',
            'created_at' => '2026-01-01 00:00:00',
        ],
        'items' => [
            ['item_id' => 10, 'item_type' => 'produto', 'title' => 'Camiseta', 'unit_amount' => 4990, 'quantity' => 1],
            ['item_id' => 11, 'item_type' => 'produto', 'title' => 'Boné', 'unit_amount' => 1990, 'quantity' => 1],
        ],
    ], $data);
    check_same([
        'stripe_event' => 'checkout.session.completed',
        'event_ts' => 1750000000,
        'session_id' => 'cs_test_abc',
        'payment_intent' => 'pi_test_123',
    ], $context);
});

test('handleWebhook: evento obsoleto (ts mais antigo que o já aplicado) NÃO dispara de novo', function () {
    $GLOBALS['payment_webhook_test_calls'] = [];
    $secret = 'whsec_test_b';
    $stripe = new StripeClient('sk_test_x', $secret);

    ost_app(['payment_webhook' => 'payment_webhook_test_recorder']);
    ost_insert_order(['stripe_session_id' => 'cs_test_stale']);

    $service = new OrderService($stripe);

    // primeiro evento (ts=2000) aplica e dispara.
    $payload1 = ost_payload('cs_test_stale', 2000);
    check(true === $service->handleWebhook($payload1, ost_sig_header($payload1, $secret)));
    check_same(1, count($GLOBALS['payment_webhook_test_calls']), 'sanity: primeiro evento disparou');

    // reentrega/duplicata com ts mais antigo (fora de ordem) — shouldApply=false,
    // markStatusFromWebhook devolve null, dispatch NÃO roda de novo.
    $payload2 = ost_payload('cs_test_stale', 1000);
    $ok = $service->handleWebhook($payload2, ost_sig_header($payload2, $secret));

    check(true === $ok, 'Stripe recebe 2xx mesmo quando o evento é descartado por obsoleto');
    check_same(1, count($GLOBALS['payment_webhook_test_calls']), 'evento obsoleto não deve chamar o handler de novo');
});

test('handleWebhook: replay exato do mesmo checkout.session.completed (mesmo ts) dispara o handler UMA vez só (tie-replay hardening)', function () {
    $GLOBALS['payment_webhook_test_calls'] = [];
    $secret = 'whsec_test_tie';
    $stripe = new StripeClient('sk_test_x', $secret);

    ost_app(['payment_webhook' => 'payment_webhook_test_recorder']);
    ost_insert_order(['stripe_session_id' => 'cs_test_tie']);

    $service = new OrderService($stripe);

    // primeiro evento (ts=5000) aplica pending->paid e dispara.
    $payload1 = ost_payload('cs_test_tie', 5000);
    check(true === $service->handleWebhook($payload1, ost_sig_header($payload1, $secret)));
    check_same(1, count($GLOBALS['payment_webhook_test_calls']), 'sanity: primeiro evento disparou');

    // retentativa Stripe byte-idêntica: mesmo evento, mesmo `created` (ts=5000).
    // shouldApply usa >= (permite), markStatusFromWebhook grava de novo mas
    // devolve null (mesmo status já gravado) — dispatch NÃO roda de novo.
    $payload2 = ost_payload('cs_test_tie', 5000);
    $ok = $service->handleWebhook($payload2, ost_sig_header($payload2, $secret));

    check(true === $ok, 'Stripe recebe 2xx mesmo no replay exato');
    check_same(1, count($GLOBALS['payment_webhook_test_calls']), 'replay exato não deve chamar o handler de novo');
});

test('handleWebhook: completed(paid) seguido de async_payment_succeeded (também paid, ts maior) dispara o handler UMA vez só', function () {
    $GLOBALS['payment_webhook_test_calls'] = [];
    $secret = 'whsec_test_double_confirm';
    $stripe = new StripeClient('sk_test_x', $secret);

    ost_app(['payment_webhook' => 'payment_webhook_test_recorder']);
    ost_insert_order(['stripe_session_id' => 'cs_test_double_confirm']);

    $service = new OrderService($stripe);

    // primeiro evento: checkout.session.completed (payment_status=paid) → aplica pending->paid, dispara.
    $payload1 = ost_payload('cs_test_double_confirm', 5000, 'checkout.session.completed');
    check(true === $service->handleWebhook($payload1, ost_sig_header($payload1, $secret)));
    check_same(1, count($GLOBALS['payment_webhook_test_calls']), 'sanity: primeiro evento (completed) disparou');

    // segundo evento, DIFERENTE tipo, ts maior, mas confirmando o MESMO
    // status final (paid) — resolveWebhookStatus mapeia async_payment_succeeded
    // pra 'paid'. shouldApply permite (ts avançou), guarda terminal permite
    // (paid->paid não é regressão), markStatusFromWebhook grava o novo ts mas
    // devolve null (não é transição) — dispatch NÃO roda de novo.
    $payload2 = ost_payload('cs_test_double_confirm', 6000, 'checkout.session.async_payment_succeeded');
    $ok = $service->handleWebhook($payload2, ost_sig_header($payload2, $secret));

    check(true === $ok, 'Stripe recebe 2xx na segunda confirmação');
    check_same(1, count($GLOBALS['payment_webhook_test_calls']), 'segunda confirmação (mesmo status final) não deve chamar o handler de novo');
});

test('handleWebhook: sem payment_webhook em site.json → nenhuma chamada, sem erro', function () {
    $GLOBALS['payment_webhook_test_calls'] = [];
    $secret = 'whsec_test_c';
    $stripe = new StripeClient('sk_test_x', $secret);

    ost_app([]); // site sem a chave payment_webhook
    ost_insert_order(['stripe_session_id' => 'cs_test_nowh']);

    $payload = ost_payload('cs_test_nowh', 3000);
    $service = new OrderService($stripe);
    $ok = $service->handleWebhook($payload, ost_sig_header($payload, $secret));

    check(true === $ok);
    check_same(0, count($GLOBALS['payment_webhook_test_calls']));
});

test('handleWebhook: handler que lança não derruba o processamento — Stripe recebe 2xx mesmo assim', function () {
    $secret = 'whsec_test_d';
    $stripe = new StripeClient('sk_test_x', $secret);

    ost_app(['payment_webhook' => 'payment_webhook_test_throw']);
    ost_insert_order(['stripe_session_id' => 'cs_test_throw']);

    $payload = ost_payload('cs_test_throw', 4000);
    $service = new OrderService($stripe);
    $ok = $service->handleWebhook($payload, ost_sig_header($payload, $secret));

    check(true === $ok);
});

test('cartTotal: soma unit_amount * quantity', function () {
    $lines = [
        ['unit_amount' => 2990, 'quantity' => 2],
        ['unit_amount' => 1000, 'quantity' => 1],
    ];
    check_same(6980, OrderService::cartTotal($lines));
});

test('cartTotal: carrinho vazio = 0', function () {
    check_same(0, OrderService::cartTotal([]));
});

test('buildStripeLineItems: formato price_data', function () {
    $lines = [['item_id' => 5, 'item_type' => 'produto', 'title' => 'Camiseta', 'unit_amount' => 4990, 'quantity' => 3]];
    $out = OrderService::buildStripeLineItems($lines, 'BRL');
    check_same(1, count($out));
    check_same('brl', $out[0]['price_data']['currency']);
    check_same(4990, $out[0]['price_data']['unit_amount']);
    check_same('Camiseta', $out[0]['price_data']['product_data']['name']);
    check_same(3, $out[0]['quantity']);
});

test('clampQuantity: limita ao intervalo [1, MAX]', function () {
    check_same(1, OrderService::clampQuantity(0));
    check_same(1, OrderService::clampQuantity(-5));
    check_same(5, OrderService::clampQuantity(5));
    check_same(OrderService::MAX_QUANTITY, OrderService::clampQuantity(99999999));
});

// ─── resolveWebhookStatus (B3/C2 — não marcar pago sem payment_status) ──

test('resolveWebhookStatus: completed + payment_status=paid → paid', function () {
    $object = ['mode' => 'payment', 'payment_status' => 'paid'];
    check_same('paid', OrderService::resolveWebhookStatus('checkout.session.completed', $object));
});

test('resolveWebhookStatus: completed + payment_status=unpaid → null (boleto/pix ainda não pago)', function () {
    $object = ['mode' => 'payment', 'payment_status' => 'unpaid'];
    check_same(null, OrderService::resolveWebhookStatus('checkout.session.completed', $object));
});

test('resolveWebhookStatus: completed sem payment_status → null (fail-safe)', function () {
    $object = ['mode' => 'payment'];
    check_same(null, OrderService::resolveWebhookStatus('checkout.session.completed', $object));
});

test('resolveWebhookStatus: async_payment_succeeded → paid', function () {
    $object = ['mode' => 'payment'];
    check_same('paid', OrderService::resolveWebhookStatus('checkout.session.async_payment_succeeded', $object));
});

test('resolveWebhookStatus: async_payment_failed → failed', function () {
    $object = ['mode' => 'payment'];
    check_same('failed', OrderService::resolveWebhookStatus('checkout.session.async_payment_failed', $object));
});

test('resolveWebhookStatus: expired → expired', function () {
    $object = ['mode' => 'payment'];
    check_same('expired', OrderService::resolveWebhookStatus('checkout.session.expired', $object));
});

test('resolveWebhookStatus: mode=subscription é ignorado (sem cross-talk com assinaturas)', function () {
    $object = ['mode' => 'subscription', 'payment_status' => 'paid'];
    check_same(null, OrderService::resolveWebhookStatus('checkout.session.completed', $object));
});

test('resolveWebhookStatus: tipo de evento não mapeado → null', function () {
    $object = ['mode' => 'payment'];
    check_same(null, OrderService::resolveWebhookStatus('customer.subscription.updated', $object));
});

// ─── handleWebhook → logRepeatPurchaseViolations: gate `$applied === 'paid'` ──
//
// Único trecho da feature (spec 2026-09-07, decisão 3) sem teste ponta a
// ponta: os testes de logRepeatPurchaseViolations() chamam o método direto
// (subscription_cardinality_webhook_test.php). Aqui o payload é assinado de
// verdade e passa por handleWebhook() inteiro, provando que quem decide se
// a detecção roda é o `if ($applied === 'paid')` — não `!== null`.

if (!function_exists('ost_log_start')) {
    /** Redireciona error_log para um arquivo temporário vazio e devolve o caminho. */
    function ost_log_start(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'ost_log_');
        ini_set('error_log', $file);
        return $file;
    }
}

if (!function_exists('ost_log_stop')) {
    /** Restaura error_log e devolve quantas vezes $needle aparece no arquivo. */
    function ost_log_stop(string $file, string $needle): int
    {
        ini_restore('error_log');
        $content = (string) file_get_contents($file);
        @unlink($file);
        return substr_count($content, $needle);
    }
}

test('handleWebhook: pedido vira paid com item de compra única repetido de outro pedido pago do membro → dispara a linha de ATENÇÃO (gate $applied === \'paid\')', function () {
    $secret = 'whsec_test_repeat_paid';
    $stripe = new StripeClient('sk_test_x', $secret);

    ost_app(['item_types' => ['course' => ['sellable' => true, 'repeat_purchase' => false]]]);

    // pedido PAGO anterior do membro 5 com o mesmo item 10.
    $priorId = ost_insert_order(['member_id' => 5, 'status' => 'paid']);
    ost_insert_item($priorId, ['item_id' => 10, 'item_type' => 'course']);

    // pedido sob revisão: pendente, mesmo membro, mesmo item — o webhook vai aplicá-lo como pago.
    $orderId = ost_insert_order(['member_id' => 5, 'status' => 'pending', 'stripe_session_id' => 'cs_test_repeat_new']);
    ost_insert_item($orderId, ['item_id' => 10, 'item_type' => 'course']);

    $payload = ost_payload('cs_test_repeat_new', 7000);
    $sig = ost_sig_header($payload, $secret);

    $file = ost_log_start();
    $service = new OrderService($stripe);
    $ok = $service->handleWebhook($payload, $sig);
    $n = ost_log_stop($file, '[Velk] ATENÇÃO: membro 5 comprou de novo o item 10');

    check(true === $ok, 'handleWebhook deve retornar true (Stripe precisa receber 2xx)');
    check_same('paid', Order::findByStripeSession('cs_test_repeat_new')->status, 'a transição pending->paid deve ter sido aplicada');
    check_same(1, $n, 'a transição pra paid deve disparar exatamente uma linha de ATENÇÃO');
});

test('handleWebhook: pedido vira expired (não paid) com a mesma anomalia presente → NÃO dispara a linha de ATENÇÃO (mata gate !== null)', function () {
    $secret = 'whsec_test_repeat_expired';
    $stripe = new StripeClient('sk_test_x', $secret);

    ost_app(['item_types' => ['course' => ['sellable' => true, 'repeat_purchase' => false]]]);

    // membro 5 já tem DUAS ordens pagas anteriores com o item 10 — a
    // anomalia já existe antes do evento sob teste.
    $prior1 = ost_insert_order(['member_id' => 5, 'status' => 'paid']);
    ost_insert_item($prior1, ['item_id' => 10, 'item_type' => 'course']);
    $prior2 = ost_insert_order(['member_id' => 5, 'status' => 'paid']);
    ost_insert_item($prior2, ['item_id' => 10, 'item_type' => 'course']);

    // terceiro pedido, pendente, mesmo item — recebe um evento EXPIRED (não paid).
    $orderId = ost_insert_order(['member_id' => 5, 'status' => 'pending', 'stripe_session_id' => 'cs_test_exp_new']);
    ost_insert_item($orderId, ['item_id' => 10, 'item_type' => 'course']);

    $payload = ost_payload('cs_test_exp_new', 8000, 'checkout.session.expired');
    $sig = ost_sig_header($payload, $secret);

    $file = ost_log_start();
    $service = new OrderService($stripe);
    $ok = $service->handleWebhook($payload, $sig);
    $n = ost_log_stop($file, '[Velk] ATENÇÃO:');

    check(true === $ok, 'handleWebhook deve retornar true (Stripe precisa receber 2xx)');
    check_same('expired', Order::findByStripeSession('cs_test_exp_new')->status, 'a transição pending->expired deve ter sido aplicada');
    check_same(0, $n, 'expired não é paid: com o gate correto (=== \'paid\') a detecção não roda; !== null rodaria e violaria (2 > 1)');
});
