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
 * Task 5 de "ordem manual": os endpoints que o arrasto do painel chama.
 *
 * Os dois primeiros testes seguram sombreamento de rota — mesma preocupação
 * de segurança de rota que not_spam_test.php cobre para markNotSpam — e
 * continuam batendo direto no arquivo-fonte, porque é exatamente ORDEM DE
 * REGISTRO no router que precisam verificar; não há "comportamento" de
 * runtime para exercitar aqui, só posição de duas linhas.
 *
 * Os cinco que vinham depois testavam auth, CSRF, o 404 sem `manual_order`
 * e a validação de `$ids` do mesmo jeito — só que lendo o TEXTO do corpo de
 * reorder() (str_contains/strpos procurando 'requireAuth', '404',
 * 'is_array($ids)' etc.). Uma revisão provou que isso não protege nada:
 * aplicando isoladamente (1) a guarda de auth sem `return`, (2) a negação de
 * `!Item::manualOrder($type)` removida — o oposto exato do requisito — ou
 * (3) `is_array($ids)` invertido, a suíte inteira continuava verde. A
 * mutação 1 é buraco de segurança real, e é exatamente a classe de bug que
 * esses testes diziam vigiar.
 *
 * A partir daqui os testes instanciam ItemController/TermController DE
 * VERDADE — App, Config, Session e Auth reais, um Velk\Database apontando
 * pra SQLite em memória via Reflection (mesma injeção que manual_order_test.php
 * e manual_order_reading_test.php já fazem) — montam um Velk\Request e
 * chamam reorder() como o router chamaria. Cada caso afirma a DUPLA que
 * importa: o código de resposta E o estado do banco depois da chamada. Só o
 * código de resposta deixaria passar a mutação 1 (guarda de auth sem
 * `return` ainda cai no 404/200 abaixo dela, só não bloqueia mais nada); só
 * o estado do banco deixaria passar uma resposta de erro que gravasse mesmo
 * assim.
 */

if (!function_exists('reo_fonte')) {
    function reo_fonte(string $rel): string
    {
        return file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
    }
}

// admin_url()/base_path() (chamados por Controller::requireAuth()) são
// helpers globais de core/helpers.php — não vêm pelo autoloader de classes
// que o harness já registra. Cada função lá dentro é guardada por
// function_exists(), então um require_once (ou até um require repetido,
// como helpers_guard_integrity_test.php prova) é seguro independente da
// ordem entre os arquivos *_test.php.
require_once dirname(__DIR__, 2) . '/core/helpers.php';

if (!function_exists('roe_boot')) {
    /**
     * Monta um App completo — Config sintética (item_types/taxonomies do
     * chamador), banco SQLite em memória, Session e Auth reais — pronto pra
     * instanciar um controller de admin de verdade e chamar reorder() como
     * o router chamaria.
     *
     * @param array<string,mixed> $itemTypes
     * @param array<string,mixed> $taxonomies
     * @param list<array{0:int,1:string,2:int}> $items [id, type, sort_order]
     * @param list<array{0:int,1:string,2:int}> $terms [id, taxonomy, sort_order]
     */
    function roe_boot(array $itemTypes, array $taxonomies, array $items = [], array $terms = []): App
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
        // Só as colunas que ItemController/TermController::reorder() e a
        // cadeia que eles chamam (Item/Term::manualOrder/applyOrder →
        // ManualOrder::apply) realmente tocam — reorder() nunca semeia
        // (ensureOrderSeeded é só em index()), então published_at/created_at
        // não fazem falta aqui como fazem em manual_order_reading_test.php.
        $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, type TEXT NOT NULL, sort_order INTEGER NOT NULL DEFAULT 0)');
        $pdo->exec('CREATE TABLE terms (id INTEGER PRIMARY KEY, taxonomy TEXT NOT NULL, sort_order INTEGER NOT NULL DEFAULT 0)');
        $pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY, email TEXT, password_hash TEXT, name TEXT,
                role TEXT, two_factor_enabled INTEGER DEFAULT 0, locale TEXT, created_at TEXT
            )'
        );

        $insItem = $pdo->prepare('INSERT INTO items (id, type, sort_order) VALUES (?, ?, ?)');
        foreach ($items as [$id, $type, $sortOrder]) {
            $insItem->execute([$id, $type, $sortOrder]);
        }
        $insTerm = $pdo->prepare('INSERT INTO terms (id, taxonomy, sort_order) VALUES (?, ?, ?)');
        foreach ($terms as [$id, $taxonomy, $sortOrder]) {
            $insTerm->execute([$id, $taxonomy, $sortOrder]);
        }
        // Usuário único, só lido quando um teste efetivamente loga (via
        // $app->session->put('user_id', 1) direto — ver os testes abaixo).
        $pdo->exec(
            "INSERT INTO users (id, email, password_hash, name, role, two_factor_enabled, locale, created_at)
             VALUES (1, 'admin@teste.dev', '', 'Admin', 'admin', 0, NULL, '2026-01-01 00:00:00')"
        );

        $db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Database::class, 'pdo'))->setValue($db, $pdo);
        $app->db = $db;

        // $_SESSION zerado a cada boot: em CLI, Session lê/escreve direto no
        // superglobal (ver Session::boot()) em vez de guardar estado próprio
        // por instância — sem isto, um teste anterior que logou ou gerou
        // csrfToken() vazaria user_id/_csrf pro próximo. Mesmo cuidado que
        // sl_session() já toma em session_lazy_test.php.
        $_SESSION = [];
        $app->session = new Session();
        $app->auth = new Auth($app->db, $app->session);
        // Controller::__construct() sempre toca adminI18n->setCurrent().
        // Caminho real do catálogo (não usado por reorder(), que nunca
        // chama __()), só pra não depender de um path inventado.
        $app->adminI18n = new \Velk\Admin\I18n($rootPath . '/core/Admin/lang', 'en', 'en');

        return $app;
    }
}

if (!function_exists('roe_ordem')) {
    /** @return array<int,int> id => sort_order, dentro de um escopo (tabela + coluna de escopo). */
    function roe_ordem(App $app, string $tabela, string $colunaEscopo, string $escopo): array
    {
        $out = [];
        foreach ($app->db->fetchAll("SELECT id, sort_order FROM {$tabela} WHERE {$colunaEscopo} = ? ORDER BY id", [$escopo]) as $r) {
            $out[(int) $r['id']] = (int) $r['sort_order'];
        }
        return $out;
    }
}

if (!function_exists('roe_req')) {
    /** Request POST pronto pra chamar reorder() direto, fora do Router. */
    function roe_req(array $post, string $path = '/admin/x/reorder', array $server = []): Request
    {
        return new Request('POST', $path, [], $post, [], $server, []);
    }
}

test('/items/{type}/reorder existe, é POST, e vem antes de /items/{type}/{id}', function () {
    $src = reo_fonte('core/Admin/AdminRouter.php');

    $posReorder = strpos($src, "\$router->post(\$prefix . '/items/{type}/reorder'");
    check($posReorder !== false, 'rota de reorder de items ausente ou não é POST');

    $posId = strpos($src, "\$router->any(\$prefix . '/items/{type}/{id}'");
    check($posId !== false, 'rota {id} de items não encontrada (o teste ficou obsoleto?)');

    check($posReorder < $posId, 'reorder de items tem que ser registrada ANTES de {id}, senão "reorder" é capturado como id');
});

test('/taxonomies/{taxonomy}/reorder existe, é POST, e vem antes de /taxonomies/{taxonomy}/{id}', function () {
    $src = reo_fonte('core/Admin/AdminRouter.php');

    $posReorder = strpos($src, "\$router->post(\$prefix . '/taxonomies/{taxonomy}/reorder'");
    check($posReorder !== false, 'rota de reorder de taxonomias ausente ou não é POST — lembrando que o prefixo é /taxonomies/, não /terms/');

    $posId = strpos($src, "\$router->any(\$prefix . '/taxonomies/{taxonomy}/{id}'");
    check($posId !== false, 'rota {id} de taxonomias não encontrada (o teste ficou obsoleto?)');

    check($posReorder < $posId, 'reorder de taxonomias tem que ser registrada ANTES de {id}, senão "reorder" é capturado como id');
});

// ─── ItemController::reorder() — comportamento de verdade ─────────────────

test('ItemController::reorder sem autenticação: redireciona (302) e não mexe no banco', function () {
    $app = roe_boot(['produto' => ['label' => 'P', 'manual_order' => true]], [], [[1, 'produto', 10], [2, 'produto', 20]]);
    // CSRF válido MESMO sem login — a sessão existe e csrfToken() não exige
    // usuário autenticado (é assim que a própria tela de login protege seu
    // formulário). Isola a guarda de auth de propósito: sem um token válido
    // aqui, uma guarda de auth quebrada ainda seria barrada pelo CSRF por
    // baixo, e o teste passaria sem provar que requireAuth() protege nada.
    $csrf = $app->session->csrfToken();
    $antes = roe_ordem($app, 'items', 'type', 'produto');

    $resp = (new ItemController())->reorder(roe_req(['ids' => [2, 1], '_csrf' => $csrf]), ['type' => 'produto']);

    check_same(302, $resp->getStatus(), 'sem sessão logada, reorder deveria redirecionar pro login');
    check_same($antes, roe_ordem($app, 'items', 'type', 'produto'), 'sem autenticação, nenhuma linha pode ser reordenada');
});

test('ItemController::reorder autenticado com CSRF inválido: 403 e não mexe no banco', function () {
    $app = roe_boot(['produto' => ['label' => 'P', 'manual_order' => true]], [], [[1, 'produto', 10], [2, 'produto', 20]]);
    $app->session->put('user_id', 1);
    $antes = roe_ordem($app, 'items', 'type', 'produto');

    $resp = (new ItemController())->reorder(roe_req(['ids' => [2, 1], '_csrf' => 'token-invalido'], '/admin/x/reorder', ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']), ['type' => 'produto']);

    check_same(403, $resp->getStatus());
    check_same($antes, roe_ordem($app, 'items', 'type', 'produto'), 'CSRF inválido não pode mexer no banco, mesmo autenticado');
});

test('ItemController::reorder autenticado+CSRF válido, tipo sem manual_order: 404 e não mexe no banco', function () {
    $app = roe_boot(['produto' => ['label' => 'P']], [], [[1, 'produto', 10], [2, 'produto', 20]]);
    $app->session->put('user_id', 1);
    $csrf = $app->session->csrfToken();
    $antes = roe_ordem($app, 'items', 'type', 'produto');

    $resp = (new ItemController())->reorder(roe_req(['ids' => [2, 1], '_csrf' => $csrf]), ['type' => 'produto']);

    check_same(404, $resp->getStatus(), 'tipo existe mas não tem manual_order — deveria 404');
    check_same($antes, roe_ordem($app, 'items', 'type', 'produto'), 'tipo sem a flag não pode ter sort_order gravado');
});

test('ItemController::reorder autenticado+CSRF válido, tipo inexistente no schema: 404 e não mexe no banco', function () {
    // 'produto' não é declarado em item_types nenhum — schema mudou (ou o
    // tipo nunca existiu) mas linhas antigas ainda estão na tabela.
    $app = roe_boot([], [], [[1, 'produto', 10], [2, 'produto', 20]]);
    $app->session->put('user_id', 1);
    $csrf = $app->session->csrfToken();
    $antes = roe_ordem($app, 'items', 'type', 'produto');

    $resp = (new ItemController())->reorder(roe_req(['ids' => [2, 1], '_csrf' => $csrf]), ['type' => 'produto']);

    check_same(404, $resp->getStatus(), 'tipo fora do schema — deveria 404');
    check_same($antes, roe_ordem($app, 'items', 'type', 'produto'), 'tipo fora do schema não pode ter sort_order gravado');
});

test('ItemController::reorder autenticado+CSRF válido, tipo com a flag: 200 e permuta de verdade no banco', function () {
    $app = roe_boot(
        ['produto' => ['label' => 'P', 'manual_order' => true]],
        [],
        [[1, 'produto', 10], [2, 'produto', 20], [3, 'produto', 30]]
    );
    $app->session->put('user_id', 1);
    $csrf = $app->session->csrfToken();

    $resp = (new ItemController())->reorder(roe_req(['ids' => [3, 1, 2], '_csrf' => $csrf]), ['type' => 'produto']);

    check_same(200, $resp->getStatus());
    check_same(['ok' => true], json_decode($resp->getBody(), true));
    // {10,20,30} redistribuídos na ordem visual [3,1,2]: 3 fica com a
    // primeira posição (10), 1 com a segunda (20), 2 com a terceira (30) —
    // mesma semântica de ManualOrder::apply() já provada em manual_order_test.php.
    check_same([1 => 20, 2 => 30, 3 => 10], roe_ordem($app, 'items', 'type', 'produto'));
});

test('ItemController::reorder com ids como string em vez de lista: 422 e não mexe no banco', function () {
    $app = roe_boot(['produto' => ['label' => 'P', 'manual_order' => true]], [], [[1, 'produto', 10], [2, 'produto', 20]]);
    $app->session->put('user_id', 1);
    $csrf = $app->session->csrfToken();
    $antes = roe_ordem($app, 'items', 'type', 'produto');

    $resp = (new ItemController())->reorder(roe_req(['ids' => '1,2', '_csrf' => $csrf]), ['type' => 'produto']);

    check_same(422, $resp->getStatus(), 'ids não é array — deveria 422');
    check_same($antes, roe_ordem($app, 'items', 'type', 'produto'), 'ids malformado não pode mexer no banco');
});

test('ItemController::reorder com ids de outro tipo misturados: 200, e o outro tipo fica intocado', function () {
    $app = roe_boot(
        ['produto' => ['label' => 'P', 'manual_order' => true]],
        [],
        [[1, 'produto', 10], [2, 'produto', 20], [9, 'servico', 99]]
    );
    $app->session->put('user_id', 1);
    $csrf = $app->session->csrfToken();

    $resp = (new ItemController())->reorder(roe_req(['ids' => [2, 9, 1], '_csrf' => $csrf]), ['type' => 'produto']);

    check_same(200, $resp->getStatus());
    check_same(99, roe_ordem($app, 'items', 'type', 'servico')[9], 'id de outro tipo não pode ser tocado');
    check_same([1 => 20, 2 => 10], roe_ordem($app, 'items', 'type', 'produto'));
});

// ─── TermController::reorder() — os mesmos sete casos ──────────────────────

test('TermController::reorder sem autenticação: redireciona (302) e não mexe no banco', function () {
    $app = roe_boot([], ['categoria' => ['label' => 'C', 'manual_order' => true]], [], [[1, 'categoria', 10], [2, 'categoria', 20]]);
    // Mesmo isolamento de IT1: CSRF válido sem login, pra a guarda de auth
    // ser a única coisa em teste.
    $csrf = $app->session->csrfToken();
    $antes = roe_ordem($app, 'terms', 'taxonomy', 'categoria');

    $resp = (new TermController())->reorder(roe_req(['ids' => [2, 1], '_csrf' => $csrf]), ['taxonomy' => 'categoria']);

    check_same(302, $resp->getStatus(), 'sem sessão logada, reorder deveria redirecionar pro login');
    check_same($antes, roe_ordem($app, 'terms', 'taxonomy', 'categoria'), 'sem autenticação, nenhuma linha pode ser reordenada');
});

test('TermController::reorder autenticado com CSRF inválido: 403 e não mexe no banco', function () {
    $app = roe_boot([], ['categoria' => ['label' => 'C', 'manual_order' => true]], [], [[1, 'categoria', 10], [2, 'categoria', 20]]);
    $app->session->put('user_id', 1);
    $antes = roe_ordem($app, 'terms', 'taxonomy', 'categoria');

    $resp = (new TermController())->reorder(roe_req(['ids' => [2, 1], '_csrf' => 'token-invalido'], '/admin/x/reorder', ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']), ['taxonomy' => 'categoria']);

    check_same(403, $resp->getStatus());
    check_same($antes, roe_ordem($app, 'terms', 'taxonomy', 'categoria'), 'CSRF inválido não pode mexer no banco, mesmo autenticado');
});

test('TermController::reorder autenticado+CSRF válido, taxonomia sem manual_order: 404 e não mexe no banco', function () {
    $app = roe_boot([], ['categoria' => ['label' => 'C']], [], [[1, 'categoria', 10], [2, 'categoria', 20]]);
    $app->session->put('user_id', 1);
    $csrf = $app->session->csrfToken();
    $antes = roe_ordem($app, 'terms', 'taxonomy', 'categoria');

    $resp = (new TermController())->reorder(roe_req(['ids' => [2, 1], '_csrf' => $csrf]), ['taxonomy' => 'categoria']);

    check_same(404, $resp->getStatus(), 'taxonomia existe mas não tem manual_order — deveria 404');
    check_same($antes, roe_ordem($app, 'terms', 'taxonomy', 'categoria'), 'taxonomia sem a flag não pode ter sort_order gravado');
});

test('TermController::reorder autenticado+CSRF válido, taxonomia inexistente no schema: 404 e não mexe no banco', function () {
    $app = roe_boot([], [], [], [[1, 'categoria', 10], [2, 'categoria', 20]]);
    $app->session->put('user_id', 1);
    $csrf = $app->session->csrfToken();
    $antes = roe_ordem($app, 'terms', 'taxonomy', 'categoria');

    $resp = (new TermController())->reorder(roe_req(['ids' => [2, 1], '_csrf' => $csrf]), ['taxonomy' => 'categoria']);

    check_same(404, $resp->getStatus(), 'taxonomia fora do schema — deveria 404');
    check_same($antes, roe_ordem($app, 'terms', 'taxonomy', 'categoria'), 'taxonomia fora do schema não pode ter sort_order gravado');
});

test('TermController::reorder autenticado+CSRF válido, taxonomia com a flag: 200 e permuta de verdade no banco', function () {
    $app = roe_boot(
        [],
        ['categoria' => ['label' => 'C', 'manual_order' => true]],
        [],
        [[1, 'categoria', 10], [2, 'categoria', 20], [3, 'categoria', 30]]
    );
    $app->session->put('user_id', 1);
    $csrf = $app->session->csrfToken();

    $resp = (new TermController())->reorder(roe_req(['ids' => [3, 1, 2], '_csrf' => $csrf]), ['taxonomy' => 'categoria']);

    check_same(200, $resp->getStatus());
    check_same(['ok' => true], json_decode($resp->getBody(), true));
    check_same([1 => 20, 2 => 30, 3 => 10], roe_ordem($app, 'terms', 'taxonomy', 'categoria'));
});

test('TermController::reorder com ids como string em vez de lista: 422 e não mexe no banco', function () {
    $app = roe_boot([], ['categoria' => ['label' => 'C', 'manual_order' => true]], [], [[1, 'categoria', 10], [2, 'categoria', 20]]);
    $app->session->put('user_id', 1);
    $csrf = $app->session->csrfToken();
    $antes = roe_ordem($app, 'terms', 'taxonomy', 'categoria');

    $resp = (new TermController())->reorder(roe_req(['ids' => '1,2', '_csrf' => $csrf]), ['taxonomy' => 'categoria']);

    check_same(422, $resp->getStatus(), 'ids não é array — deveria 422');
    check_same($antes, roe_ordem($app, 'terms', 'taxonomy', 'categoria'), 'ids malformado não pode mexer no banco');
});

test('TermController::reorder com ids de outra taxonomia misturados: 200, e a outra taxonomia fica intocada', function () {
    $app = roe_boot(
        [],
        ['categoria' => ['label' => 'C', 'manual_order' => true]],
        [],
        [[1, 'categoria', 10], [2, 'categoria', 20], [9, 'tag', 99]]
    );
    $app->session->put('user_id', 1);
    $csrf = $app->session->csrfToken();

    $resp = (new TermController())->reorder(roe_req(['ids' => [2, 9, 1], '_csrf' => $csrf]), ['taxonomy' => 'categoria']);

    check_same(200, $resp->getStatus());
    check_same(99, roe_ordem($app, 'terms', 'taxonomy', 'tag')[9], 'id de outra taxonomia não pode ser tocado');
    check_same([1 => 20, 2 => 10], roe_ordem($app, 'terms', 'taxonomy', 'categoria'));
});
