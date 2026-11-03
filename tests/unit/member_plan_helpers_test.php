<?php

declare(strict_types=1);

use Velk\App;
use Velk\Config;
use Velk\Database;
use Velk\MemberAuth;
use Velk\Models\Option;
use Velk\Session;

/**
 * Helpers de assinatura com VÁRIAS ativas (spec 2026-09-07). Antes tudo
 * passava por activeForMember() — a mais recente — e quem pagava pro e
 * basic era tratado como basic. Com members.multiple_plans, "alguma ativa
 * casa" é a única resposta certa para on_plan/require_plan.
 *
 * member_require_plan() faz header()+exit quando barra; em CLI header() é
 * no-op, mas exit derrubaria o runner. Por isso só o caminho que PASSA é
 * exercitado aqui — e é exatamente o caminho que a mutação "só a mais
 * recente" quebraria de forma barulhenta.
 */

require_once dirname(__DIR__, 2) . '/core/helpers.php';

if (!function_exists('mph_boot')) {
    /**
     * @param list<array{0:int,1:string,2:string}> $subs [id, plan_key, status] do membro 7
     * @param bool $multiple members.multiple_plans
     * @param bool $login  false = visitante anônimo
     */
    function mph_boot(array $subs, bool $multiple = false, bool $login = true): App
    {
        $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_x';
        $_ENV['STRIPE_WEBHOOK_SECRET'] = 'whsec_x';
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, []);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, [
            'members' => [
                'enabled' => true,
                'multiple_plans' => $multiple,
                'plans' => ['pro' => ['name' => 'Pro', 'intervals' => ['month']], 'basic' => ['name' => 'Basic', 'intervals' => ['month']]],
            ],
            'item_types' => [], 'taxonomies' => [],
        ]);
        $app = new App($config);
        $app->basePath = '';

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE members (id INTEGER PRIMARY KEY, email TEXT, name TEXT, password_hash TEXT, type TEXT,
                    fields TEXT, stripe_customer_id TEXT, two_factor_enabled INTEGER DEFAULT 0, email_verified_at TEXT,
                    last_login_at TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE member_subscriptions (id INTEGER PRIMARY KEY, member_id INTEGER, plan_key TEXT,
                    billing_interval TEXT, status TEXT, stripe_customer_id TEXT, stripe_subscription_id TEXT UNIQUE,
                    current_period_end TEXT, cancel_at_period_end INTEGER DEFAULT 0, last_event_ts INTEGER,
                    created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, value TEXT, updated_at TEXT)');
        $pdo->exec("INSERT INTO members (id, email, name, password_hash, type, fields, email_verified_at, created_at, updated_at)
                    VALUES (7, 'ana@t.dev', 'Ana', '', 'member', '[]', '2026-01-01', '2026-01-01', '2026-01-01')");
        $pdo->exec("INSERT INTO settings (setting_key, value) VALUES ('options.subscriptions', '" .
            json_encode(['pro_active' => true, 'pro_price_month' => '29,90', 'basic_active' => true, 'basic_price_month' => '9,90', 'currency' => 'BRL']) . "')");
        $ins = $pdo->prepare("INSERT INTO member_subscriptions (id, member_id, plan_key, billing_interval, status, stripe_customer_id, stripe_subscription_id, created_at, updated_at)
                              VALUES (?, 7, ?, 'month', ?, 'cus_x', ?, '2026-01-01', '2026-01-01')");
        foreach ($subs as [$id, $plan, $status]) {
            $ins->execute([$id, $plan, $status, 'sub_' . $id]);
        }

        $db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Database::class, 'pdo'))->setValue($db, $pdo);
        $app->db = $db;
        Option::flushCache();
        $_SESSION = [];
        $app->session = new Session();
        $app->memberAuth = new MemberAuth($app->db, $app->session);
        if ($login) {
            $app->session->put('member_id', 7);
        }
        return $app;
    }
}

// pro (id 1) é a mais antiga; basic (id 2) a mais recente.
$mphDuas = [[1, 'pro', 'active'], [2, 'basic', 'active'], [3, 'old', 'canceled']];

test('member_subscriptions: todas as ativas, mais recente primeiro; visitante recebe lista vazia', function () use ($mphDuas) {
    mph_boot($mphDuas, true);
    check_same(['basic', 'pro'], array_map(fn($s) => $s->planKey, member_subscriptions()));
    mph_boot($mphDuas, true, false);
    check_same([], member_subscriptions());
});

test('member_subscription / member_plan: continuam na mais recente', function () use ($mphDuas) {
    mph_boot($mphDuas, true);
    check_same('basic', member_subscription()?->planKey);
    check_same('Basic', member_plan()['name'] ?? null);
});

test('member_on_plan: casa com QUALQUER ativa, não só a mais recente', function () use ($mphDuas) {
    mph_boot($mphDuas, true);
    check_same(true, member_on_plan('pro'), 'pro é a mais antiga das ativas e tem que casar');
    check_same(true, member_on_plan('basic'));
    check_same(false, member_on_plan('old'), 'cancelada não casa');
    check_same(false, member_on_plan('vip'));
});

test('member_subscribed_plans: um plano por ativa, na ordem de member_subscriptions(); plano sumido vira forma mínima', function () {
    mph_boot([[1, 'pro', 'active'], [2, 'sumido', 'active']], true);
    $plans = member_subscribed_plans();
    check_same(['sumido', 'pro'], array_column($plans, 'key'));
    check_same('Pro', $plans[1]['name']);
    check_same(['key' => 'sumido', 'name' => 'sumido', 'prices' => []], $plans[0], 'plano removido do site.json');
    mph_boot([], true, false);
    check_same([], member_subscribed_plans());
});

test('member_can_subscribe/único: com uma ativa, nenhum plano pode; sem ativa, qualquer um', function () {
    mph_boot([[1, 'pro', 'active']]);
    check_same(false, member_can_subscribe('basic'));
    check_same(false, member_can_subscribe('pro'));
    mph_boot([[1, 'pro', 'canceled']]);
    check_same(true, member_can_subscribe('pro'));
});

test('member_can_subscribe/múltiplo: outro plano pode, o mesmo não; visitante nunca', function () {
    mph_boot([[1, 'pro', 'active']], true);
    check_same(true, member_can_subscribe('basic'));
    check_same(false, member_can_subscribe('pro'));
    mph_boot([], true, false);
    check_same(false, member_can_subscribe('pro'), 'sem login não há como assinar');
});

// Por último de propósito: com a implementação ANTIGA este teste faz exit e
// derruba o runner — tudo acima já terá sido reportado.
test('member_require_plan: passa quando qualquer ativa está em $keys (o caminho que barra faria exit)', function () use ($mphDuas) {
    mph_boot($mphDuas, true);
    member_require_plan('pro');          // a mais antiga
    member_require_plan(['vip', 'pro']); // lista com uma que casa
    member_require_plan();               // qualquer ativa
    check(true, 'chegou aqui sem exit');
});

$_ENV['STRIPE_SECRET_KEY'] = ''; $_ENV['STRIPE_WEBHOOK_SECRET'] = '';
