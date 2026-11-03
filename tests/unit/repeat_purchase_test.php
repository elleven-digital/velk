<?php

declare(strict_types=1);

use Velk\App;
use Velk\Config;
use Velk\Database;
use Velk\Models\Order;
use Velk\Services\OrderService;

/**
 * repeat_purchase (spec 2026-09-07): a regra pura da loja e as leituras de
 * Order que a alimentam. Tipo de compra única é item de UMA unidade
 * (quantidade 1, membro ou convidado); membro que já tem o item não paga
 * de novo; convidado passa (decisão 2b).
 */

if (!function_exists('rp_line')) {
    function rp_line(int $id, string $type, int $qty = 1): array
    {
        return ['item_id' => $id, 'item_type' => $type, 'title' => "Item {$id}", 'unit_amount' => 1000, 'quantity' => $qty];
    }
}

if (!function_exists('rp_app')) {
    /** App + SQLite com orders/order_items. @param list<array{0:int,1:?int,2:string,3:list<int>}> $orders [id, member_id, status, item_ids] */
    function rp_app(array $orders): App
    {
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, []);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, []);
        $app = new App($config);

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, member_id INTEGER NULL, email TEXT NOT NULL DEFAULT \'\',
                    status TEXT NOT NULL, currency TEXT NOT NULL DEFAULT \'BRL\', total_amount INTEGER NOT NULL DEFAULT 0,
                    stripe_session_id TEXT NOT NULL, stripe_payment_intent TEXT NULL, last_event_ts INTEGER NULL,
                    created_at TEXT NOT NULL DEFAULT \'2026-01-01\', updated_at TEXT NOT NULL DEFAULT \'2026-01-01\')');
        $pdo->exec('CREATE TABLE order_items (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL, item_id INTEGER NULL,
                    item_type TEXT NOT NULL DEFAULT \'course\', title TEXT NOT NULL DEFAULT \'x\', unit_amount INTEGER NOT NULL DEFAULT 1000,
                    quantity INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL DEFAULT \'2026-01-01\')');
        $o = $pdo->prepare('INSERT INTO orders (id, member_id, status, stripe_session_id) VALUES (?, ?, ?, ?)');
        $li = $pdo->prepare('INSERT INTO order_items (order_id, item_id) VALUES (?, ?)');
        foreach ($orders as [$id, $memberId, $status, $itemIds]) {
            $o->execute([$id, $memberId, $status, 'cs_' . $id]);
            foreach ($itemIds as $itemId) $li->execute([$id, $itemId]);
        }
        $db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Database::class, 'pdo'))->setValue($db, $pdo);
        $app->db = $db;
        return $app;
    }
}

// ─── Regra pura ─────────────────────────────────────────────────────────

test('enforceRepeatPurchase: sem tipo de compra única, nada muda (nem quantidade)', function () {
    $lines = [rp_line(10, 'course', 3), rp_line(20, 'ebook', 2)];
    check_same($lines, OrderService::enforceRepeatPurchase($lines, [], [10, 20]));
});

test('enforceRepeatPurchase: tipo de compra única força quantidade 1 — membro ou convidado', function () {
    $out = OrderService::enforceRepeatPurchase([rp_line(10, 'course', 3)], ['course'], []);
    check_same(1, $out[0]['quantity'], 'convidado (owned vazio) também recebe o clamp');
});

test('enforceRepeatPurchase: item de compra única que o membro já tem é removido; os outros ficam', function () {
    $out = OrderService::enforceRepeatPurchase([rp_line(10, 'course'), rp_line(11, 'course'), rp_line(20, 'ebook', 2)], ['course'], [10]);
    check_same([11, 20], array_column($out, 'item_id'));
    check_same(2, $out[1]['quantity'], 'tipo repetível não recebe clamp');
});

test('enforceRepeatPurchase: duas linhas do mesmo item de compra única viram uma; tipo repetível pode repetir', function () {
    $out = OrderService::enforceRepeatPurchase([rp_line(10, 'course'), rp_line(10, 'course'), rp_line(20, 'ebook'), rp_line(20, 'ebook')], ['course'], []);
    check_same([10, 20, 20], array_column($out, 'item_id'));
});

test('enforceRepeatPurchase: tudo possuído esvazia o carrinho; ordem das linhas restantes é preservada', function () {
    check_same([], OrderService::enforceRepeatPurchase([rp_line(10, 'course'), rp_line(11, 'course')], ['course'], [11, 10]));
    $out = OrderService::enforceRepeatPurchase([rp_line(20, 'ebook'), rp_line(10, 'course'), rp_line(21, 'ebook')], ['course'], []);
    check_same([20, 10, 21], array_column($out, 'item_id'));
});

// ─── Leituras de Order ──────────────────────────────────────────────────

$rpPedidos = [
    [1, 7, 'paid', [10, 11]],
    [2, 7, 'pending', [12]],
    [3, 7, 'paid', [10]],      // 10 comprado duas vezes
    [4, 8, 'paid', [13]],      // outro membro
    [5, null, 'paid', [14]],   // convidado
];

test('Order::paidItemIdsForMember: só ids do carrinho que o membro tem em pedido PAGO, sem repetir', function () use ($rpPedidos) {
    rp_app($rpPedidos);
    $ids = Order::paidItemIdsForMember(7, [10, 11, 12, 13, 14, 99]);
    sort($ids);
    check_same([10, 11], $ids, 'pending, outro membro e convidado ficam fora; 10 aparece uma vez');
    check_same([], Order::paidItemIdsForMember(7, []));
    check_same([], Order::paidItemIdsForMember(99, [10]));
});

test('Order::countPaidForMemberContainingItem: conta pedidos pagos distintos', function () use ($rpPedidos) {
    rp_app($rpPedidos);
    check_same(2, Order::countPaidForMemberContainingItem(7, 10));
    check_same(1, Order::countPaidForMemberContainingItem(7, 11));
    check_same(0, Order::countPaidForMemberContainingItem(7, 12), 'pending não conta');
    check_same(0, Order::countPaidForMemberContainingItem(7, 13), 'pedido de outro membro não conta');
});

test('Order::paidForMemberContainingItem: mantém o contrato bool', function () use ($rpPedidos) {
    rp_app($rpPedidos);
    check_same(true, Order::paidForMemberContainingItem(7, 11));
    check_same(false, Order::paidForMemberContainingItem(7, 12));
});
