<?php

declare(strict_types=1);

use Velk\App;
use Velk\Config;
use Velk\Database;
use Velk\Models\MemberSubscription;

/**
 * Cardinalidade de assinaturas (spec 2026-09-07): as flags de site.json, a
 * regra pura e a leitura plural. Nada aqui toca Stripe.
 *
 * members.multiple_plans só liga com o literal true; repeat_purchase só
 * restringe com o literal false — schema:validate rejeita o resto, aqui o
 * runtime só não pode travar por um valor estranho.
 */

if (!function_exists('sc_config')) {
    function sc_config(array $site): Config
    {
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, []);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, $site);
        return $config;
    }
}

test('Config::allowsMultiplePlans: ausente é false', function () {
    check_same(false, sc_config(['members' => ['plans' => ['pro' => ['name' => 'Pro']]]])->allowsMultiplePlans());
    check_same(false, sc_config([])->allowsMultiplePlans());
});

test('Config::allowsMultiplePlans: só o literal true liga', function () {
    check_same(true, sc_config(['members' => ['multiple_plans' => true]])->allowsMultiplePlans());
    check_same(false, sc_config(['members' => ['multiple_plans' => 'true']])->allowsMultiplePlans(), 'string não liga');
    check_same(false, sc_config(['members' => ['multiple_plans' => 1]])->allowsMultiplePlans(), 'inteiro não liga');
    check_same(false, sc_config(['members' => ['multiple_plans' => false]])->allowsMultiplePlans());
});

test('Config::isItemTypeRepeatPurchase: ausente é true, tipo desconhecido é true', function () {
    $c = sc_config(['item_types' => ['course' => ['sellable' => true]]]);
    check_same(true, $c->isItemTypeRepeatPurchase('course'));
    check_same(true, $c->isItemTypeRepeatPurchase('nao-existe'));
});

test('Config::isItemTypeRepeatPurchase: só o literal false restringe', function () {
    check_same(false, sc_config(['item_types' => ['course' => ['sellable' => true, 'repeat_purchase' => false]]])->isItemTypeRepeatPurchase('course'));
    check_same(true, sc_config(['item_types' => ['course' => ['sellable' => true, 'repeat_purchase' => 'false']]])->isItemTypeRepeatPurchase('course'), 'string não restringe');
    check_same(true, sc_config(['item_types' => ['course' => ['sellable' => true, 'repeat_purchase' => 0]]])->isItemTypeRepeatPurchase('course'), 'zero não restringe');
});

test('Config::singlePurchaseTypes: só tipos VENDÁVEIS com repeat_purchase false, na ordem de declaração', function () {
    $c = sc_config(['item_types' => [
        'talk'   => ['repeat_purchase' => false],                       // não vendável: fora
        'course' => ['sellable' => true, 'repeat_purchase' => false],   // dentro
        'ebook'  => ['sellable' => true],                               // repetível: fora
        'seat'   => ['sellable' => true, 'repeat_purchase' => false],   // dentro
        'legacy' => ['sellable' => false, 'repeat_purchase' => false],  // fora
    ]]);
    check_same(['course', 'seat'], $c->singlePurchaseTypes());
    check_same([], sc_config(['item_types' => ['ebook' => ['sellable' => true]]])->singlePurchaseTypes());
    check_same([], sc_config([])->singlePurchaseTypes());
});

if (!function_exists('sc_app')) {
    /** @param list<array{0:int,1:int,2:string,3:string}> $subs [id, member_id, plan_key, status] */
    function sc_app(array $subs): App
    {
        $app = new App(sc_config(['members' => ['enabled' => true]]));
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE member_subscriptions (id INTEGER PRIMARY KEY, member_id INTEGER, plan_key TEXT,
                    billing_interval TEXT, status TEXT, stripe_customer_id TEXT, stripe_subscription_id TEXT UNIQUE,
                    current_period_end TEXT, cancel_at_period_end INTEGER DEFAULT 0, last_event_ts INTEGER,
                    created_at TEXT, updated_at TEXT)');
        $ins = $pdo->prepare("INSERT INTO member_subscriptions (id, member_id, plan_key, billing_interval, status, stripe_customer_id, stripe_subscription_id, created_at, updated_at)
                              VALUES (?, ?, ?, 'month', ?, 'cus_x', ?, '2026-01-01', '2026-01-01')");
        foreach ($subs as [$id, $memberId, $plan, $status]) {
            $ins->execute([$id, $memberId, $plan, $status, 'sub_' . $id]);
        }
        $db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Database::class, 'pdo'))->setValue($db, $pdo);
        $app->db = $db;
        return $app;
    }
}

test('violatesCardinality: tabela-verdade do modo único (uma por membro)', function () {
    check_same(false, MemberSubscription::violatesCardinality([], false));
    check_same(false, MemberSubscription::violatesCardinality(['pro'], false));
    check_same(true, MemberSubscription::violatesCardinality(['pro', 'basic'], false), 'duas ativas viola, mesmo de planos diferentes');
    check_same(true, MemberSubscription::violatesCardinality(['pro', 'pro'], false));
});

test('violatesCardinality: tabela-verdade do modo múltiplo (uma por plano)', function () {
    check_same(false, MemberSubscription::violatesCardinality([], true));
    check_same(false, MemberSubscription::violatesCardinality(['pro'], true));
    check_same(false, MemberSubscription::violatesCardinality(['pro', 'basic'], true), 'planos distintos cabem');
    check_same(true, MemberSubscription::violatesCardinality(['pro', 'pro'], true), 'o mesmo plano duas vezes viola');
    check_same(true, MemberSubscription::violatesCardinality(['pro', 'basic', 'pro'], true));
});

test('groupActiveByMember: agrupa por membro preservando a ordem de entrada', function () {
    $rows = [
        ['member_id' => '7', 'plan_key' => 'basic', 'status' => 'active'],
        ['member_id' => '9', 'plan_key' => 'pro', 'status' => 'trialing'],
        ['member_id' => '7', 'plan_key' => 'pro', 'status' => 'past_due'],
    ];
    $g = MemberSubscription::groupActiveByMember($rows);
    check_same([7, 9], array_keys($g));
    check_same([['plan_key' => 'basic', 'status' => 'active'], ['plan_key' => 'pro', 'status' => 'past_due']], $g[7]);
    check_same([['plan_key' => 'pro', 'status' => 'trialing']], $g[9]);
    check_same([], MemberSubscription::groupActiveByMember([]));
});

test('activeListForMember: todas as ativas do membro, mais recente primeiro; canceladas e outros membros fora', function () {
    sc_app([[1, 7, 'pro', 'active'], [2, 7, 'basic', 'trialing'], [3, 7, 'old', 'canceled'], [4, 8, 'pro', 'active'], [5, 7, 'vip', 'past_due']]);
    $list = MemberSubscription::activeListForMember(7);
    check_same([5, 2, 1], array_map(fn($s) => $s->id, $list), 'ORDER BY id DESC');
    check_same(['vip', 'basic', 'pro'], array_map(fn($s) => $s->planKey, $list));
    check_same([], MemberSubscription::activeListForMember(99));
});

test('activeForMember: continua devolvendo a mais recente das ativas', function () {
    sc_app([[1, 7, 'pro', 'active'], [2, 7, 'basic', 'active'], [3, 7, 'old', 'canceled']]);
    check_same(2, MemberSubscription::activeForMember(7)?->id);
    check_same(null, MemberSubscription::activeForMember(99));
});
