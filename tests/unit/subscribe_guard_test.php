<?php

declare(strict_types=1);

use Velk\App;
use Velk\Config;
use Velk\Database;
use Velk\Front\MemberController;
use Velk\MemberAuth;
use Velk\Request;
use Velk\Session;

/**
 * Revisão de pagamentos 2026-09: um membro com assinatura ativa não abre um
 * segundo checkout.
 *
 * Nem subscribe() nem createCheckoutSession() conferiam activeForMember().
 * Um segundo envio do formulário — clique duplo, aba antiga, F5 — criava
 * uma SEGUNDA subscription no Stripe e o membro era cobrado duas vezes.
 *
 * O controle positivo (membro SEM assinatura) precisa passar pela guarda e
 * chegar ao Stripe. Sem chave de verdade a chamada falha e vira
 * `checkout_failed` — que é exatamente a prova de que a guarda deixou
 * passar e o próximo passo foi o Stripe.
 */

require_once dirname(__DIR__, 2) . '/core/helpers.php';

if (!function_exists('sg_boot')) {
    /**
     * @param list<array{0:int,1:int,2:string,3?:string}> $subs [id, member_id, status, plan_key='pro']
     * @param bool $multiple site.json → members.multiple_plans
     */
    function sg_boot(array $subs, bool $multiple = false): App
    {
        $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_x';
        $_ENV['STRIPE_WEBHOOK_SECRET'] = 'whsec_x';
        $rootPath = dirname(__DIR__, 2);
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, ['paths' => ['core' => $rootPath . '/core']]);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, [
            'members' => [
                'enabled' => true,
                'multiple_plans' => $multiple,
                'plans' => [
                    'pro' => ['name' => 'Pro', 'intervals' => ['month']],
                    'basic' => ['name' => 'Basic', 'intervals' => ['month']],
                ],
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
        // planos ativos com preço: sem isto findPlan(..., true) devolve null antes da guarda
        $pdo->exec("INSERT INTO settings (setting_key, value) VALUES ('options.subscriptions', '" .
            json_encode(['pro_active' => true, 'pro_price_month' => '29,90', 'basic_active' => true, 'basic_price_month' => '9,90', 'currency' => 'BRL']) . "')");
        $ins = $pdo->prepare("INSERT INTO member_subscriptions (id, member_id, plan_key, billing_interval, status, stripe_customer_id, stripe_subscription_id, created_at, updated_at)
                              VALUES (?, ?, ?, 'month', ?, 'cus_x', ?, '2026-01-01', '2026-01-01')");
        foreach ($subs as $row) {
            [$id, $memberId, $status] = $row;
            $ins->execute([$id, $memberId, $row[3] ?? 'pro', $status, 'sub_' . $id]);
        }

        $db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Database::class, 'pdo'))->setValue($db, $pdo);
        $app->db = $db;
        \Velk\Models\Option::flushCache(); // o cache estático de Option sobrevive entre boots no mesmo processo
        $_SESSION = [];
        $app->session = new Session();
        $app->memberAuth = new MemberAuth($app->db, $app->session);
        $app->session->put('member_id', 7);
        return $app;
    }
}

if (!function_exists('sg_subscribe')) {
    /** @return array{status:int, code:?string, subs:int} */
    function sg_subscribe(App $app, string $plan = 'pro'): array
    {
        $token = $app->session->csrfToken();
        $req = new Request('POST', '/member/subscribe', [], ['_csrf' => $token, 'plan' => $plan, 'interval' => 'month'], [],
            ['HTTP_HOST' => 'site.com', 'HTTP_REFERER' => 'https://site.com/planos'], []);
        $r = (new MemberController())->subscribe($req);
        $flash = $_SESSION['_member_status'] ?? null;
        $n = (int) $app->db->fetchColumn('SELECT COUNT(*) FROM member_subscriptions');
        return ['status' => $r->getStatus(), 'code' => is_array($flash) ? ($flash['code'] ?? null) : null, 'subs' => $n];
    }
}

test('subscribe: membro com assinatura ATIVA é barrado com already_subscribed', function () {
    $app = sg_boot([[1, 7, 'active']]);
    $r = sg_subscribe($app);
    check_same(302, $r['status']);
    check_same('already_subscribed', $r['code'], 'a guarda precisa explicar por quê');
    check_same(1, $r['subs'], 'nenhuma linha nova');
});

test('subscribe: trialing e past_due também contam como ativa', function () {
    foreach (['trialing', 'past_due'] as $st) {
        $r = sg_subscribe(sg_boot([[1, 7, $st]]));
        check_same('already_subscribed', $r['code'], "status {$st} é ativo e precisa barrar");
    }
});

test('subscribe: assinatura CANCELADA não barra — chega ao Stripe', function () {
    // Controle positivo: passou pela guarda, o próximo passo foi o Stripe
    // (que falha sem chave real) → checkout_failed. Se a guarda barrasse
    // indevidamente, o código seria already_subscribed.
    $r = sg_subscribe(sg_boot([[1, 7, 'canceled']]));
    check_same('checkout_failed', $r['code'], 'cancelada não é ativa; o fluxo tinha que seguir até o Stripe');
});

test('subscribe: assinatura de OUTRO membro não barra este', function () {
    $r = sg_subscribe(sg_boot([[1, 99, 'active']]));
    check_same('checkout_failed', $r['code']);
});

test('subscribe: sem assinatura nenhuma chega ao Stripe', function () {
    $r = sg_subscribe(sg_boot([]));
    check_same('checkout_failed', $r['code']);
});

// ─── Cardinalidade (spec 2026-09-07) ───────────────────────────────────

test('subscribe/único: ativa de OUTRO plano também barra — é uma por membro', function () {
    $r = sg_subscribe(sg_boot([[1, 7, 'active', 'basic']]), 'pro');
    check_same('already_subscribed', $r['code'], 'modo único: qualquer ativa barra');
    check_same(1, $r['subs']);
});

test('subscribe/múltiplo: ativa de OUTRO plano não barra — chega ao Stripe', function () {
    $r = sg_subscribe(sg_boot([[1, 7, 'active', 'basic']], true), 'pro');
    check_same('checkout_failed', $r['code'], 'basic + pro cabe; o fluxo tinha que seguir até o Stripe');
});

test('subscribe/múltiplo: o MESMO plano ativo barra — uma por plano', function () {
    $r = sg_subscribe(sg_boot([[1, 7, 'active', 'pro']], true), 'pro');
    check_same('already_subscribed', $r['code'], 'pro + pro é cobrança dupla pelo mesmo acesso');
    check_same(1, $r['subs']);
});

test('subscribe/múltiplo: o mesmo plano CANCELADO não barra', function () {
    $r = sg_subscribe(sg_boot([[1, 7, 'canceled', 'pro']], true), 'pro');
    check_same('checkout_failed', $r['code']);
});

$_ENV['STRIPE_SECRET_KEY'] = ''; $_ENV['STRIPE_WEBHOOK_SECRET'] = '';
