<?php

declare(strict_types=1);

use Velk\App;
use Velk\Config;
use Velk\Database;
use Velk\Models\Item;
use Velk\Models\Term;

/**
 * v3.7.5 — o repasse de `sort_order` em Item::create() / Term::create().
 *
 * As duas create() aceitam `sort_order` no array de dados, com `?? 0` como
 * padrão. É esse repasse que faz um registro novo entrar no FIM da lista,
 * num tipo/taxonomia de ordem manual (ver manual_order_reading_test.php e
 * os chamadores em ItemController/TermController/Content::itemCreate, que
 * calculam a posição via nextSortOrder() e passam no payload).
 *
 * Sem cobertura direta em create(), a mutação que remove essa linha é
 * invisível para o resto da suíte: nenhum outro teste falha, porque a
 * maioria dos fixtures não liga a flag e nunca passa `sort_order`. É esse
 * buraco que este arquivo fecha — testando create() isolado, sem depender
 * de manual_order estar ligada em lugar nenhum.
 */

if (!function_exists('moc_boot')) {
    /**
     * App com um site.json vazio e um banco em memória contendo só as
     * colunas que Item::create() e Term::create() de fato gravam — não é o
     * schema completo de produção (migrations/), só o suficiente para os
     * INSERTs não falharem. Cada teste chama isto de novo, então cada um
     * parte de um banco vazio e isolado.
     */
    function moc_boot(): void
    {
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, ['paths' => []]);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, [
            'item_types' => [],
            'taxonomies' => [],
        ]);

        $app = new App($config);
        $app->basePath = '';

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec(
            'CREATE TABLE items (
                id INTEGER PRIMARY KEY, type TEXT, slug TEXT, parent_id INTEGER,
                title TEXT, template TEXT, status TEXT, fields TEXT,
                sort_order INTEGER NOT NULL DEFAULT 0,
                published_at TEXT, created_at TEXT, updated_at TEXT
            )'
        );
        // terms precisa também de template e das colunas *_translations:
        // Term::create() sempre inclui essas chaves no INSERT (viram NULL
        // quando o chamador não passa nada), então a tabela tem que ter as
        // colunas mesmo que este arquivo não as exercite.
        $pdo->exec(
            'CREATE TABLE terms (
                id INTEGER PRIMARY KEY, taxonomy TEXT, slug TEXT, name TEXT,
                parent_id INTEGER, fields TEXT,
                name_translations TEXT, slug_translations TEXT, fields_translations TEXT,
                template TEXT, sort_order INTEGER NOT NULL DEFAULT 0,
                created_at TEXT, updated_at TEXT
            )'
        );

        $db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Database::class, 'pdo'))->setValue($db, $pdo);
        $app->db = $db;
    }
}

if (!function_exists('moc_item_sort_order')) {
    function moc_item_sort_order(int $id): int
    {
        return (int) App::instance()->db->fetchColumn('SELECT sort_order FROM items WHERE id = ?', [$id]);
    }
}

if (!function_exists('moc_term_sort_order')) {
    function moc_term_sort_order(int $id): int
    {
        return (int) App::instance()->db->fetchColumn('SELECT sort_order FROM terms WHERE id = ?', [$id]);
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Item::create()
// ─────────────────────────────────────────────────────────────────────────

test('Item::create(): sort_order no array de dados grava o valor na linha', function () {
    moc_boot();

    $item = Item::create(['type' => 'produto', 'slug' => 'a', 'title' => 'A', 'sort_order' => 5]);

    check_same(5, moc_item_sort_order($item->id));
});

test('Item::create(): sem a chave sort_order grava 0 — caminho de quem não conhece a flag', function () {
    // Todo chamador que não sabe de manual_order (imports antigos, scripts,
    // outros pontos do próprio código) não passa sort_order nenhum. Esse
    // caminho precisa continuar idêntico: DEFAULT 0, sem exceção.
    moc_boot();

    $item = Item::create(['type' => 'produto', 'slug' => 'b', 'title' => 'B']);

    check_same(0, moc_item_sort_order($item->id));
});

test('Item::create(): valor grande de sort_order sobrevive intacto', function () {
    // 47 não é 0 nem 1 — se a mutação reintroduzir o bug (a chave some do
    // INSERT e a coluna cai no DEFAULT 0), este teste não pode passar por
    // coincidência com um valor pequeno.
    moc_boot();

    $item = Item::create(['type' => 'produto', 'slug' => 'c', 'title' => 'C', 'sort_order' => 47]);

    check_same(47, moc_item_sort_order($item->id));
});

// ─────────────────────────────────────────────────────────────────────────
// Term::create()
// ─────────────────────────────────────────────────────────────────────────

test('Term::create(): sort_order no array de dados grava o valor na linha', function () {
    moc_boot();

    $term = Term::create(['taxonomy' => 'categoria', 'slug' => 'a', 'name' => 'A', 'sort_order' => 5]);

    check_same(5, moc_term_sort_order($term->id));
});

test('Term::create(): sem a chave sort_order grava 0 — caminho de quem não conhece a flag', function () {
    moc_boot();

    $term = Term::create(['taxonomy' => 'categoria', 'slug' => 'b', 'name' => 'B']);

    check_same(0, moc_term_sort_order($term->id));
});

test('Term::create(): valor grande de sort_order sobrevive intacto', function () {
    moc_boot();

    $term = Term::create(['taxonomy' => 'categoria', 'slug' => 'c', 'name' => 'C', 'sort_order' => 47]);

    check_same(47, moc_term_sort_order($term->id));
});
