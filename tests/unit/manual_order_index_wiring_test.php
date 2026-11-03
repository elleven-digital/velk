<?php

declare(strict_types=1);

use Velk\Admin\Controllers\ItemController;
use Velk\Admin\Controllers\TermController;
use Velk\App;
use Velk\Auth;
use Velk\Config;
use Velk\Database;
use Velk\Request;
use Velk\Session;

/**
 * Item 3 da revisão final de "ordem manual": a fiação DENTRO de
 * ItemController::index() / TermController::index() não tinha cobertura
 * nenhuma.
 *
 * Provado por mutação: remover as chamadas de ensureOrderSeeded() dos dois
 * index(), ou trocar `'ordemManual' => Item::manualOrder(...)` por `false`,
 * não quebrava NENHUM teste da suíte antes deste arquivo — a segunda
 * mutação apaga a alça de arrasto do painel inteiro e a suíte não piscava.
 *
 * manual_order_view_test.php testa a VIEW isolada, incluída direto com
 * $ordemManual passado à mão — nunca passa pelo controller, então nunca
 * exercitou nem a chamada de semeadura nem a leitura de manualOrder() que a
 * alimentam de verdade. Este arquivo fecha essa lacuna: instancia
 * ItemController/TermController DE VERDADE (mesmo molde de
 * reorder_endpoint_test.php — App, Config, Session, Auth reais, Database
 * SQLite em memória via Reflection) e chama index() como o router chamaria,
 * afirmando duas coisas por teste: o banco (sort_order sai de tudo-em-0 pra
 * distinto quando a flag está ligada) e o HTML devolvido (presença/ausência
 * de data-sortable-table — o mesmo sinal que manual_order_view_test.php já
 * usa pra provar que a view recebeu $ordemManual).
 */

require_once dirname(__DIR__, 2) . '/core/helpers.php';

if (!function_exists('moi_boot')) {
    /**
     * App completo pronto pra instanciar um controller de admin de verdade e
     * chamar index() como o router chamaria — Config sintética, banco SQLite
     * em memória com as colunas que Item::fromRow()/Term::fromRow() esperam
     * (mesmo schema de mor_boot() em manual_order_reading_test.php),
     * Session/Auth reais e um usuário logável (mesmo molde de roe_boot() em
     * reorder_endpoint_test.php).
     *
     * @param array<string,mixed> $itemTypes
     * @param array<string,mixed> $taxonomies
     * @param list<array{0:int,1:string,2:int}> $items [id, type, sort_order]
     * @param list<array{0:int,1:string,2:int}> $terms [id, taxonomy, sort_order]
     */
    function moi_boot(array $itemTypes, array $taxonomies, array $items = [], array $terms = []): App
    {
        $rootPath = dirname(__DIR__, 2);

        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, ['paths' => ['core' => $rootPath . '/core']]);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, [
            'item_types' => $itemTypes,
            'taxonomies' => $taxonomies,
        ]);

        $app = new App($config);
        $app->basePath = '';

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec(
            'CREATE TABLE items (
                id INTEGER PRIMARY KEY, type TEXT, slug TEXT, title TEXT,
                template TEXT, status TEXT, fields TEXT, sort_order INTEGER NOT NULL DEFAULT 0,
                published_at TEXT, created_at TEXT, updated_at TEXT
            )'
        );
        $pdo->exec(
            'CREATE TABLE terms (
                id INTEGER PRIMARY KEY, taxonomy TEXT, slug TEXT, name TEXT,
                parent_id INTEGER, fields TEXT, sort_order INTEGER NOT NULL DEFAULT 0,
                created_at TEXT, updated_at TEXT
            )'
        );
        $pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY, email TEXT, password_hash TEXT, name TEXT,
                role TEXT, two_factor_enabled INTEGER DEFAULT 0, locale TEXT, created_at TEXT
            )'
        );

        $insItem = $pdo->prepare(
            'INSERT INTO items (id, type, slug, title, status, fields, sort_order, published_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($items as [$id, $type, $sortOrder]) {
            $ts = sprintf('2026-01-%02d 00:00:00', 30 - $id);
            $insItem->execute([$id, $type, 'slug-' . $id, 'Item ' . $id, 'published', '[]', $sortOrder, $ts, $ts, $ts]);
        }
        $insTerm = $pdo->prepare(
            'INSERT INTO terms (id, taxonomy, slug, name, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($terms as [$id, $taxonomy, $sortOrder]) {
            $ts = sprintf('2026-01-%02d 00:00:00', 30 - $id);
            $insTerm->execute([$id, $taxonomy, 'slug-' . $id, 'Termo ' . $id, $sortOrder, $ts, $ts]);
        }
        $pdo->exec(
            "INSERT INTO users (id, email, password_hash, name, role, two_factor_enabled, locale, created_at)
             VALUES (1, 'admin@teste.dev', '', 'Admin', 'admin', 0, NULL, '2026-01-01 00:00:00')"
        );

        $db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Database::class, 'pdo'))->setValue($db, $pdo);
        $app->db = $db;

        $_SESSION = [];
        $app->session = new Session();
        $app->auth = new Auth($app->db, $app->session);
        $app->adminI18n = new \Velk\Admin\I18n($rootPath . '/core/Admin/lang', 'en', 'en');

        return $app;
    }
}

if (!function_exists('moi_req')) {
    function moi_req(string $path): Request
    {
        return new Request('GET', $path, [], [], [], [], []);
    }
}

if (!function_exists('moi_sort_orders')) {
    /** @return list<int> sort_order de cada linha do escopo, na ordem de id. */
    function moi_sort_orders(App $app, string $tabela, string $colunaEscopo, string $escopo): array
    {
        $rows = $app->db->fetchAll("SELECT sort_order FROM {$tabela} WHERE {$colunaEscopo} = ? ORDER BY id", [$escopo]);
        return array_map(static fn($r): int => (int) $r['sort_order'], $rows);
    }
}

// ─── ItemController::index() ───────────────────────────────────────────────

test('ItemController::index semeia sort_order de verdade quando o tipo tem a flag', function () {
    $app = moi_boot(
        ['produto' => ['label' => 'P', 'manual_order' => true]],
        [],
        [[1, 'produto', 0], [2, 'produto', 0], [3, 'produto', 0]]
    );
    $app->session->put('user_id', 1);
    check_same([0, 0, 0], moi_sort_orders($app, 'items', 'type', 'produto'), 'estado inicial: tudo em 0 (pré-semeadura)');

    $resp = (new ItemController())->index(moi_req('/admin/items/produto'), ['type' => 'produto']);

    check_same(200, $resp->getStatus());
    $depois = moi_sort_orders($app, 'items', 'type', 'produto');
    check(
        count(array_unique($depois)) === 3,
        'index() deveria ter semeado — sort_order tinha que sair de tudo-em-0 pra três valores distintos, mas continuou ' . json_encode($depois)
    );
});

test('ItemController::index NÃO semeia quando o tipo não tem a flag', function () {
    $app = moi_boot(
        ['produto' => ['label' => 'P']],
        [],
        [[1, 'produto', 0], [2, 'produto', 0], [3, 'produto', 0]]
    );
    $app->session->put('user_id', 1);

    $resp = (new ItemController())->index(moi_req('/admin/items/produto'), ['type' => 'produto']);

    check_same(200, $resp->getStatus());
    check_same([0, 0, 0], moi_sort_orders($app, 'items', 'type', 'produto'), 'sem a flag, ensureOrderSeeded não pode ser chamado');
});

test('ItemController::index passa ordemManual=true pra view quando o tipo tem a flag — HTML sai com o contrato de arrasto', function () {
    $app = moi_boot(
        ['produto' => ['label' => 'P', 'manual_order' => true]],
        [],
        [[1, 'produto', 1], [2, 'produto', 2]]
    );
    $app->session->put('user_id', 1);

    $resp = (new ItemController())->index(moi_req('/admin/items/produto'), ['type' => 'produto']);

    check_same(200, $resp->getStatus());
    check(str_contains($resp->getBody(), 'data-sortable-table'), 'com a flag, a view deveria ter recebido ordemManual=true (data-sortable-table ausente)');
});

test('ItemController::index passa ordemManual=false pra view quando o tipo não tem a flag — HTML sai sem o contrato de arrasto', function () {
    $app = moi_boot(
        ['produto' => ['label' => 'P']],
        [],
        [[1, 'produto', 1], [2, 'produto', 2]]
    );
    $app->session->put('user_id', 1);

    $resp = (new ItemController())->index(moi_req('/admin/items/produto'), ['type' => 'produto']);

    check_same(200, $resp->getStatus());
    check(!str_contains($resp->getBody(), 'data-sortable-table'), 'sem a flag, a view não deveria ter recebido ordemManual=true');
});

// ─── TermController::index() ───────────────────────────────────────────────

test('TermController::index semeia sort_order de verdade quando a taxonomia tem a flag', function () {
    $app = moi_boot(
        [],
        ['categoria' => ['label' => 'C', 'manual_order' => true]],
        [],
        [[1, 'categoria', 0], [2, 'categoria', 0], [3, 'categoria', 0]]
    );
    $app->session->put('user_id', 1);

    $resp = (new TermController())->index(moi_req('/admin/taxonomies/categoria'), ['taxonomy' => 'categoria']);

    check_same(200, $resp->getStatus());
    $depois = moi_sort_orders($app, 'terms', 'taxonomy', 'categoria');
    check(
        count(array_unique($depois)) === 3,
        'index() deveria ter semeado — sort_order tinha que sair de tudo-em-0 pra três valores distintos, mas continuou ' . json_encode($depois)
    );
});

test('TermController::index NÃO semeia quando a taxonomia não tem a flag', function () {
    $app = moi_boot(
        [],
        ['categoria' => ['label' => 'C']],
        [],
        [[1, 'categoria', 0], [2, 'categoria', 0], [3, 'categoria', 0]]
    );
    $app->session->put('user_id', 1);

    $resp = (new TermController())->index(moi_req('/admin/taxonomies/categoria'), ['taxonomy' => 'categoria']);

    check_same(200, $resp->getStatus());
    check_same([0, 0, 0], moi_sort_orders($app, 'terms', 'taxonomy', 'categoria'), 'sem a flag, ensureOrderSeeded não pode ser chamado');
});

test('TermController::index passa ordemManual=true pra view quando a taxonomia tem a flag — HTML sai com o contrato de arrasto', function () {
    $app = moi_boot(
        [],
        ['categoria' => ['label' => 'C', 'manual_order' => true]],
        [],
        [[1, 'categoria', 1], [2, 'categoria', 2]]
    );
    $app->session->put('user_id', 1);

    $resp = (new TermController())->index(moi_req('/admin/taxonomies/categoria'), ['taxonomy' => 'categoria']);

    check_same(200, $resp->getStatus());
    check(str_contains($resp->getBody(), 'data-sortable-table'), 'com a flag, a view deveria ter recebido ordemManual=true');
});

test('TermController::index passa ordemManual=false pra view quando a taxonomia não tem a flag — HTML sai sem o contrato de arrasto', function () {
    $app = moi_boot(
        [],
        ['categoria' => ['label' => 'C']],
        [],
        [[1, 'categoria', 1], [2, 'categoria', 2]]
    );
    $app->session->put('user_id', 1);

    $resp = (new TermController())->index(moi_req('/admin/taxonomies/categoria'), ['taxonomy' => 'categoria']);

    check_same(200, $resp->getStatus());
    check(!str_contains($resp->getBody(), 'data-sortable-table'), 'sem a flag, a view não deveria ter recebido ordemManual=true');
});
