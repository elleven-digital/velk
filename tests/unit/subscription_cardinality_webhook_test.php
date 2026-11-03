<?php

declare(strict_types=1);

use Velk\App;
use Velk\Config;
use Velk\Database;
use Velk\Models\Option;
use Velk\Models\Order;
use Velk\Services\OrderService;
use Velk\Services\StripeClient;
use Velk\Services\SubscriptionService;

/**
 * Cardinalidade no webhook (spec 2026-09-07, decisão 3): a guarda do
 * formulário não fecha a janela entre o clique e o webhook — duas abas
 * concluídas antes do primeiro evento passam as duas. O webhook grava
 * mesmo assim (o dinheiro já saiu) e REGISTRA a violação; nunca cancela
 * nem estorna. Aqui o log é capturado redirecionando error_log para um
 * arquivo do scratchpad.
 *
 * syncFromStripeSubscription() e logRepeatPurchaseViolations() são
 * chamados direto (sem assinar payload), como em
 * subscription_webhook_dispatch_test.
 */

if (!function_exists('scw_app')) {
    function scw_app(array $site): App
    {
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, []);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, $site);
        $app = new App($config);

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE member_subscriptions (id INTEGER PRIMARY KEY AUTOINCREMENT, member_id INTEGER NOT NULL,
                    plan_key TEXT NOT NULL, billing_interval TEXT NOT NULL, status TEXT NOT NULL, stripe_customer_id TEXT NOT NULL,
                    stripe_subscription_id TEXT NOT NULL UNIQUE, current_period_end TEXT NULL, cancel_at_period_end INTEGER NOT NULL DEFAULT 0,
                    last_event_ts INTEGER NULL, created_at TEXT NOT NULL DEFAULT \'2026-01-01\', updated_at TEXT NOT NULL DEFAULT \'2026-01-01\')');
        $pdo->exec('CREATE TABLE members (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL, stripe_customer_id TEXT NULL)');
        $pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY AUTOINCREMENT, member_id INTEGER NULL, email TEXT NOT NULL DEFAULT \'\',
                    status TEXT NOT NULL, currency TEXT NOT NULL, total_amount INTEGER NOT NULL, stripe_session_id TEXT NOT NULL,
                    stripe_payment_intent TEXT NULL, last_event_ts INTEGER NULL,
                    created_at TEXT NOT NULL DEFAULT \'2026-01-01\', updated_at TEXT NOT NULL DEFAULT \'2026-01-01\')');
        $pdo->exec('CREATE TABLE order_items (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL, item_id INTEGER NULL,
                    item_type TEXT NOT NULL, title TEXT NOT NULL, unit_amount INTEGER NOT NULL, quantity INTEGER NOT NULL DEFAULT 1,
                    created_at TEXT NOT NULL DEFAULT \'2026-01-01\')');

        $db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Database::class, 'pdo'))->setValue($db, $pdo);
        $app->db = $db;
        Option::flushCache();
        return $app;
    }
}

if (!function_exists('scw_sub')) {
    /** Objeto subscription do Stripe, mínimo que syncFromStripeSubscription lê. plan_key vem da metadata (cache de billing vazio). */
    function scw_sub(string $id, string $plan, string $status = 'active'): array
    {
        return [
            'id' => $id,
            'customer' => 'cus_test_1',
            'status' => $status,
            'cancel_at_period_end' => false,
            'current_period_end' => 1750000000,
            'metadata' => ['member_id' => '5', 'plan_key' => $plan],
            'items' => ['data' => [['price' => ['id' => 'price_' . $plan, 'product' => 'prod_' . $plan, 'recurring' => ['interval' => 'month']]]]],
        ];
    }
}

if (!function_exists('scw_log_start')) {
    /** Redireciona error_log para um arquivo e devolve o caminho (vazio). */
    function scw_log_start(): string
    {
        $dir = sys_get_temp_dir() . '/velk-scw';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $file = $dir . '/' . uniqid('log_', true) . '.txt';
        file_put_contents($file, '');
        ini_set('error_log', $file);
        return $file;
    }
}

if (!function_exists('scw_log_stop')) {
    /** Restaura error_log e devolve quantas linhas de violação foram escritas. */
    function scw_log_stop(string $file, string $needle): int
    {
        ini_restore('error_log');
        $content = (string) file_get_contents($file);
        @unlink($file);
        return substr_count($content, $needle);
    }
}

$scwPlans = ['pro' => ['name' => 'Pro'], 'basic' => ['name' => 'Basic']];

test('webhook/único: segunda assinatura ativa do membro gera a linha de ATENÇÃO — e grava mesmo assim', function () use ($scwPlans) {
    scw_app(['members' => ['enabled' => true, 'plans' => $scwPlans]]);
    $service = new SubscriptionService(new StripeClient('sk_test_x'));
    $file = scw_log_start();
    $service->syncFromStripeSubscription(scw_sub('sub_1', 'pro'), 1000, 'customer.subscription.created');
    $service->syncFromStripeSubscription(scw_sub('sub_2', 'basic'), 1001, 'customer.subscription.created');
    $n = scw_log_stop($file, '[Velk] ATENÇÃO: membro 5 tem assinaturas ativas além da regra');
    check_same(1, $n, 'a primeira não viola, a segunda viola: exatamente uma linha');
    check_same(2, (int) App::instance()->db->fetchColumn('SELECT COUNT(*) FROM member_subscriptions'), 'a violação não impede a gravação');
});

test('webhook/múltiplo: planos distintos não geram linha', function () use ($scwPlans) {
    scw_app(['members' => ['enabled' => true, 'multiple_plans' => true, 'plans' => $scwPlans]]);
    $service = new SubscriptionService(new StripeClient('sk_test_x'));
    $file = scw_log_start();
    $service->syncFromStripeSubscription(scw_sub('sub_1', 'pro'), 1000, 'customer.subscription.created');
    $service->syncFromStripeSubscription(scw_sub('sub_2', 'basic'), 1001, 'customer.subscription.created');
    check_same(0, scw_log_stop($file, '[Velk] ATENÇÃO:'));
});

test('webhook/múltiplo: o mesmo plano duas vezes gera linha', function () use ($scwPlans) {
    scw_app(['members' => ['enabled' => true, 'multiple_plans' => true, 'plans' => $scwPlans]]);
    $service = new SubscriptionService(new StripeClient('sk_test_x'));
    $file = scw_log_start();
    $service->syncFromStripeSubscription(scw_sub('sub_1', 'pro'), 1000, 'customer.subscription.created');
    $service->syncFromStripeSubscription(scw_sub('sub_2', 'pro'), 1001, 'customer.subscription.created');
    check_same(1, scw_log_stop($file, 'além da regra (multiple_plans: uma por plano): pro, pro'));
});

test('webhook: evento de CANCELAMENTO não gera linha, mesmo com a anomalia ainda existindo', function () use ($scwPlans) {
    scw_app(['members' => ['enabled' => true, 'plans' => $scwPlans]]);
    $service = new SubscriptionService(new StripeClient('sk_test_x'));
    $file = scw_log_start();
    $service->syncFromStripeSubscription(scw_sub('sub_1', 'pro'), 1000, 'customer.subscription.created');
    $service->syncFromStripeSubscription(scw_sub('sub_2', 'basic'), 1001, 'customer.subscription.created'); // 1 linha
    $service->syncFromStripeSubscription(scw_sub('sub_3', 'basic', 'canceled'), 1002, 'customer.subscription.deleted'); // não conta
    check_same(1, scw_log_stop($file, '[Velk] ATENÇÃO: membro 5'), 'cancelamento não é ativo: não reavalia');
});

// ─── Loja ────────────────────────────────────────────────────────────────

if (!function_exists('scw_order')) {
    /** Insere pedido + linhas. @param list<array{0:int,1:string}> $items [item_id, item_type] */
    function scw_order(int $id, ?int $memberId, string $status, array $items): Order
    {
        $db = App::instance()->db;
        $db->insert('orders', ['id' => $id, 'member_id' => $memberId, 'status' => $status, 'currency' => 'BRL',
            'total_amount' => 1000, 'stripe_session_id' => 'cs_' . $id]);
        foreach ($items as [$itemId, $type]) {
            $db->insert('order_items', ['order_id' => $id, 'item_id' => $itemId, 'item_type' => $type, 'title' => 'Item ' . $itemId,
                'unit_amount' => 1000, 'quantity' => 1]);
        }
        return Order::find($id);
    }
}

$scwLoja = ['item_types' => ['course' => ['sellable' => true, 'repeat_purchase' => false], 'ebook' => ['sellable' => true]]];

test('loja/webhook: segundo pedido PAGO do mesmo item de compra única gera a linha de ATENÇÃO', function () use ($scwLoja) {
    scw_app($scwLoja);
    scw_order(1, 7, 'paid', [[10, 'course']]);
    $segundo = scw_order(2, 7, 'paid', [[10, 'course']]);
    $file = scw_log_start();
    (new OrderService(new StripeClient('sk_test_x')))->logRepeatPurchaseViolations($segundo);
    check_same(1, scw_log_stop($file, '[Velk] ATENÇÃO: membro 7 comprou de novo o item 10 (Item 10), tipo de compra única — pedidos pagos: 2'));
});

test('loja/webhook: primeiro pedido, pedido de convidado e tipo repetível não geram linha', function () use ($scwLoja) {
    scw_app($scwLoja);
    $primeiro = scw_order(1, 7, 'paid', [[10, 'course']]);
    $convidado = scw_order(2, null, 'paid', [[10, 'course']]);
    scw_order(3, 7, 'paid', [[20, 'ebook']]);
    $ebook = scw_order(4, 7, 'paid', [[20, 'ebook']]);
    $service = new OrderService(new StripeClient('sk_test_x'));
    $file = scw_log_start();
    $service->logRepeatPurchaseViolations($primeiro);
    $service->logRepeatPurchaseViolations($convidado);
    $service->logRepeatPurchaseViolations($ebook);
    check_same(0, scw_log_stop($file, '[Velk] ATENÇÃO:'));
});
