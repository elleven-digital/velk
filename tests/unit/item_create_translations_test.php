<?php

declare(strict_types=1);

use Velk\App;
use Velk\Config;
use Velk\Database;
use Velk\Models\Item;

/**
 * As traduções preenchidas antes do primeiro save vão para a linha do item.
 *
 * Até a v3.8.x, Item::create() montava o INSERT com uma lista fixa de chaves
 * e deixava de fora `title_translations`, `slug_translations` e
 * `fields_translations`, que o ItemController já entrega no payload. Um post
 * novo com título, slug e corpo digitados na aba "en" nascia com as três
 * colunas NULL, e a aba voltava vazia depois do redirect, sem aviso. Só o
 * segundo save (Item::save) gravava as traduções.
 *
 * A regra é a do save(): mapa vazio vira NULL, o resto vira JSON sem escape
 * de acento. Quem não passa as chaves (o `item:create` do CLI) continua com
 * as colunas NULL.
 */

if (!function_exists('ict_boot')) {
    /**
     * App com um site.json vazio e a tabela `items` em memória, com as
     * colunas que Item::create() grava. Cada teste chama isto de novo e
     * parte de um banco vazio.
     */
    function ict_boot(): void
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
                title TEXT, title_translations TEXT, slug_translations TEXT,
                template TEXT, status TEXT, fields TEXT, fields_translations TEXT,
                sort_order INTEGER NOT NULL DEFAULT 0,
                published_at TEXT, created_at TEXT, updated_at TEXT
            )'
        );

        $db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Database::class, 'pdo'))->setValue($db, $pdo);
        $app->db = $db;
    }

    /**
     * As três colunas de tradução como estão na linha, sem passar pelo
     * decodeJson de Item::fromRow (que devolve [] tanto para NULL quanto
     * para um JSON inválido).
     *
     * @return array{title_translations: ?string, slug_translations: ?string, fields_translations: ?string}
     */
    function ict_columns(int $id): array
    {
        return App::instance()->db->fetch(
            'SELECT title_translations, slug_translations, fields_translations FROM items WHERE id = ?',
            [$id]
        );
    }

    /** O payload que o ItemController monta para um post novo com a aba "en" preenchida. */
    function ict_payload(array $extra = []): array
    {
        return $extra + [
            'type' => 'post',
            'slug' => 'ola-mundo',
            'title' => 'Olá, mundo',
            'status' => 'draft',
            'fields' => ['content' => '<p>Corpo.</p>'],
        ];
    }
}

test('Item::create(): título, slug e campos traduzidos no primeiro save ficam no item', function () {
    ict_boot();

    $item = Item::create(ict_payload([
        'title_translations' => ['en' => 'Hello, world'],
        'slug_translations' => ['en' => 'hello-world'],
        'fields_translations' => ['en' => ['content' => '<p>Body.</p>']],
    ]));

    check_same(['en' => 'Hello, world'], $item->titleTranslations);
    check_same(['en' => 'hello-world'], $item->slugTranslations);
    check_same(['en' => ['content' => '<p>Body.</p>']], $item->fieldsTranslations);

    // O que a aba "en" lê de volta depois do redirect.
    check_same('Hello, world', $item->titleIn('en'));
    check_same('hello-world', $item->slugIn('en'));
    check_same('<p>Body.</p>', $item->fieldIn('content', 'en'));

    // O idioma padrão segue nas colunas de sempre.
    check_same('Olá, mundo', $item->title);
    check_same('ola-mundo', $item->slug);
    check_same(['content' => '<p>Corpo.</p>'], $item->fields);
});

test('Item::create(): tradução com acento é gravada sem escape, como no save()', function () {
    ict_boot();

    $item = Item::create(ict_payload([
        'title_translations' => ['es' => 'Publicación'],
        'slug_translations' => ['es' => 'publicacion'],
        'fields_translations' => ['es' => ['content' => '<p>Sección.</p>']],
    ]));

    $columns = ict_columns($item->id);
    check_same('{"es":"Publicación"}', $columns['title_translations']);
    check_same('{"es":"publicacion"}', $columns['slug_translations']);
    check_same('{"es":{"content":"<p>Sección.<\/p>"}}', $columns['fields_translations']);
});

test('Item::create(): mapa de tradução vazio grava NULL, como no save()', function () {
    ict_boot();

    $item = Item::create(ict_payload([
        'title_translations' => [],
        'slug_translations' => [],
        'fields_translations' => [],
    ]));

    check_same(
        ['title_translations' => null, 'slug_translations' => null, 'fields_translations' => null],
        ict_columns($item->id)
    );
});

test('Item::create(): só uma das três traduções preenchida grava essa e deixa as outras NULL', function () {
    ict_boot();

    $item = Item::create(ict_payload([
        'title_translations' => ['en' => 'Hello, world'],
        'slug_translations' => [],
        'fields_translations' => [],
    ]));

    check_same(
        ['title_translations' => '{"en":"Hello, world"}', 'slug_translations' => null, 'fields_translations' => null],
        ict_columns($item->id)
    );
});

test('Item::create(): sem as chaves de tradução as colunas ficam NULL — caminho do item:create do CLI', function () {
    ict_boot();

    $item = Item::create(ict_payload());

    check_same(
        ['title_translations' => null, 'slug_translations' => null, 'fields_translations' => null],
        ict_columns($item->id)
    );
    check_same([], $item->titleTranslations);
    check_same([], $item->slugTranslations);
    check_same([], $item->fieldsTranslations);
});

test('Item::create() e Item::save() deixam a mesma linha para as mesmas traduções', function () {
    ict_boot();

    $translations = [
        'title_translations' => ['en' => 'Hello, world', 'es' => 'Hola, mundo'],
        'slug_translations' => ['en' => 'hello-world'],
        'fields_translations' => ['en' => ['content' => '<p>Body.</p>', 'featured' => true]],
    ];

    $created = Item::create(ict_payload($translations));

    // O caminho que já funcionava: nasce sem tradução, grava no segundo save.
    $saved = Item::create(ict_payload(['slug' => 'segundo-save']));
    $saved->save($translations);

    check_same(ict_columns($saved->id), ict_columns($created->id));
});
