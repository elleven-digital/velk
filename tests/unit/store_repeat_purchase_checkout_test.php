<?php

declare(strict_types=1);

use Velk\App;
use Velk\Config;
use Velk\Database;
use Velk\Front\StoreController;
use Velk\I18n;
use Velk\MemberAuth;
use Velk\Models\Item;
use Velk\Request;
use Velk\Session;

/**
 * repeat_purchase no checkout real (spec 2026-09-07). StoreController de
 * verdade + SQLite; sem chave Stripe real, o passo seguinte à guarda falha
 * e vira `checkout_failed` — que é a prova de que a guarda deixou passar
 * (mesmo controle positivo de subscribe_guard_test).
 *
 * I18n via reflection com activeLanguage pré-semeado: Item::field() chama
 * i18n->current(), que assim devolve na primeira linha sem tocar db/request
 * (técnica de button_label_i18n_defaults_test).
 */

require_once dirname(__DIR__, 2) . '/core/helpers.php';

if (!function_exists('src_boot')) {
    /**
     * Itens: 10 e 11 = course (compra única), 20 = ebook (repetível). Membro 7.
     * @param list<array{0:int,1:?int,2:string,3:list<int>}> $orders [id, member_id, status, item_ids]
     */
    function src_boot(array $orders, bool $login = true, bool $storeOn = true): App
    {
        $_ENV['STRIPE_SECRET_KEY'] = $storeOn ? 'sk_test_x' : '';
        $_ENV['STRIPE_STORE_WEBHOOK_SECRET'] = $storeOn ? 'whsec_x' : '';
        $rootPath = dirname(__DIR__, 2);
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, ['paths' => ['core' => $rootPath . '/core']]);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, [
            'members' => ['enabled' => true],
            'item_types' => [
                'course' => ['label' => 'Curso', 'sellable' => true, 'repeat_purchase' => false],
                'ebook' => ['label' => 'Ebook', 'sellable' => true],
            ],
            'taxonomies' => [],
            'store' => ['currency' => 'BRL'],
        ]);
        $app = new App($config);
        $app->basePath = '';

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, type TEXT, slug TEXT, parent_id INTEGER, title TEXT, template TEXT,
                    status TEXT, fields TEXT, sort_order INTEGER NOT NULL DEFAULT 0, published_at TEXT, created_at TEXT, updated_at TEXT,
                    title_translations TEXT, slug_translations TEXT, fields_translations TEXT)');
        $pdo->exec('CREATE TABLE members (id INTEGER PRIMARY KEY, email TEXT, name TEXT, password_hash TEXT, type TEXT,
                    fields TEXT, stripe_customer_id TEXT, two_factor_enabled INTEGER DEFAULT 0, email_verified_at TEXT,
                    last_login_at TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, member_id INTEGER NULL, email TEXT NOT NULL DEFAULT \'\',
                    status TEXT NOT NULL, currency TEXT NOT NULL DEFAULT \'BRL\', total_amount INTEGER NOT NULL DEFAULT 0,
                    stripe_session_id TEXT NOT NULL, stripe_payment_intent TEXT NULL, last_event_ts INTEGER NULL,
                    created_at TEXT NOT NULL DEFAULT \'2026-01-01\', updated_at TEXT NOT NULL DEFAULT \'2026-01-01\')');
        $pdo->exec('CREATE TABLE order_items (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL, item_id INTEGER NULL,
                    item_type TEXT NOT NULL DEFAULT \'course\', title TEXT NOT NULL DEFAULT \'x\', unit_amount INTEGER NOT NULL DEFAULT 1000,
                    quantity INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL DEFAULT \'2026-01-01\')');
        $it = $pdo->prepare("INSERT INTO items (id, type, slug, title, status, fields, published_at, created_at, updated_at)
                             VALUES (?, ?, ?, ?, 'published', ?, '2026-01-01', '2026-01-01', '2026-01-01')");
        $it->execute([10, 'course', 'curso-a', 'Curso A', json_encode(['price' => '100,00'])]);
        $it->execute([11, 'course', 'curso-b', 'Curso B', json_encode(['price' => '150,00'])]);
        $it->execute([20, 'ebook', 'ebook', 'Ebook', json_encode(['price' => '10,00'])]);
        $pdo->exec("INSERT INTO members (id, email, name, password_hash, type, fields, email_verified_at, created_at, updated_at)
                    VALUES (7, 'ana@t.dev', 'Ana', '', 'member', '[]', '2026-01-01', '2026-01-01', '2026-01-01')");
        $o = $pdo->prepare('INSERT INTO orders (id, member_id, status, stripe_session_id) VALUES (?, ?, ?, ?)');
        $li = $pdo->prepare('INSERT INTO order_items (order_id, item_id, item_type) VALUES (?, ?, ?)');
        foreach ($orders as [$id, $memberId, $status, $itemIds]) {
            $o->execute([$id, $memberId, $status, 'cs_' . $id]);
            foreach ($itemIds as $itemId) $li->execute([$id, $itemId, $itemId >= 20 ? 'ebook' : 'course']);
        }

        $db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Database::class, 'pdo'))->setValue($db, $pdo);
        $app->db = $db;

        $i18nRef = new ReflectionClass(I18n::class);
        $i18n = $i18nRef->newInstanceWithoutConstructor();
        $i18nRef->getProperty('config')->setValue($i18n, $config);
        $i18nRef->getProperty('activeLanguage')->setValue($i18n, $i18n->defaultLanguage());
        $app->i18n = $i18n;

        $_SESSION = [];
        $app->session = new Session();
        $app->memberAuth = new MemberAuth($app->db, $app->session);
        if ($login) {
            $app->session->put('member_id', 7);
        }
        return $app;
    }
}

if (!function_exists('src_checkout')) {
    /** @param list<int> $itemIds @return array{status:int, code:?string, orders:int} */
    function src_checkout(App $app, array $itemIds, int $qty = 1): array
    {
        $post = ['_csrf' => $app->session->csrfToken()];
        foreach ($itemIds as $i => $id) {
            $post['items'][$i] = ['id' => $id, 'qty' => $qty];
        }
        $req = new Request('POST', '/store/checkout', [], $post, [],
            ['HTTP_HOST' => 'site.com', 'HTTP_REFERER' => 'https://site.com/loja'], []);
        $r = (new StoreController())->checkout($req);
        $flash = $_SESSION['_store_status'] ?? null;
        return [
            'status' => $r->getStatus(),
            'code' => is_array($flash) ? ($flash['code'] ?? null) : null,
            'flash' => is_array($flash) ? ($flash['status'] ?? null) : null,
            'orders' => (int) $app->db->fetchColumn('SELECT COUNT(*) FROM orders'),
        ];
    }
}

test('checkout: membro que já tem o item de compra única recebe already_purchased (info) e nenhum pedido nasce', function () {
    $app = src_boot([[1, 7, 'paid', [10]]]);
    $r = src_checkout($app, [10]);
    check_same(302, $r['status']);
    check_same('already_purchased', $r['code']);
    check_same('info', $r['flash']);
    check_same(1, $r['orders'], 'só o pedido antigo');
});

test('checkout: item de compra única que o membro NÃO tem passa da guarda (controle positivo: checkout_failed)', function () {
    $r = src_checkout(src_boot([[1, 7, 'paid', [10]]]), [11]);
    check_same('checkout_failed', $r['code'], 'a guarda deixou passar; o Stripe é que falhou');
});

test('checkout: pedido PENDING do mesmo item não barra', function () {
    $r = src_checkout(src_boot([[1, 7, 'pending', [10]]]), [10]);
    check_same('checkout_failed', $r['code'], 'checkout abandonado não pode trancar o membro');
});

test('checkout: tipo repetível passa mesmo já comprado', function () {
    $r = src_checkout(src_boot([[1, 7, 'paid', [20]]]), [20]);
    check_same('checkout_failed', $r['code']);
});

test('checkout: convidado passa com item de compra única (decisão 2b)', function () {
    $r = src_checkout(src_boot([[1, null, 'paid', [10]]], false), [10]);
    check_same('checkout_failed', $r['code']);
});

test('checkout: carrinho misto segue com o que sobrou (o item possuído cai, o outro vai ao Stripe)', function () {
    $r = src_checkout(src_boot([[1, 7, 'paid', [10]]]), [10, 11]);
    check_same('checkout_failed', $r['code'], 'sobrou o 11: chegou ao Stripe');
});

test('checkout: carrinho só com itens inválidos continua cart_invalid, não already_purchased', function () {
    $r = src_checkout(src_boot([[1, 7, 'paid', [10]]]), [999]);
    check_same('cart_invalid', $r['code']);
});

test('item_purchasable: "mostro o botão?" — vendável E (repetível OU visitante OU ainda não comprou)', function () {
    src_boot([[1, 7, 'paid', [10, 20]]]);
    check_same(false, item_purchasable(Item::find(10)), 'course já comprado: não');
    check_same(true, item_purchasable(Item::find(11)), 'course ainda não comprado: sim');
    check_same(true, item_purchasable(Item::find(20)), 'ebook já comprado, mas repetível: sim');

    src_boot([[1, null, 'paid', [10]]], false);
    check_same(true, item_purchasable(Item::find(10)), 'visitante: sim');

    src_boot([], true, false);
    check_same(false, item_purchasable(Item::find(11)), 'loja desligada: não, mesmo sem compra');
});

$_ENV['STRIPE_SECRET_KEY'] = ''; $_ENV['STRIPE_STORE_WEBHOOK_SECRET'] = '';
