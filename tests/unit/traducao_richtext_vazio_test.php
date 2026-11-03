<?php

declare(strict_types=1);

use Velk\App;
use Velk\Config;
use Velk\Database;
use Velk\Models\Item;
use Velk\Models\Option;
use Velk\Models\Page;
use Velk\Models\Term;

/**
 * Um richtext sem nada escrito, gravado como tradução, não esconde o idioma
 * padrão.
 *
 * Até a v3.8.x, salvar pelo painel gravava `"content": "<p></p>"` em cada
 * idioma cuja aba ficou em branco: o editor de texto nunca envia '', e o
 * filtro das traduções só reconhecia '' como vazio. Na leitura, `fieldIn()`
 * devolvia esse parágrafo vazio, e a página do idioma traduzido saía sem o
 * corpo, em vez de mostrar o do idioma padrão.
 *
 * A gravação deixou de guardar isso (field_translation_overrides_test). Estes
 * testes são do que JÁ está no banco: a leitura trata o parágrafo vazio como
 * tradução ausente, sem ninguém ter de salvar o conteúdo de novo.
 */

if (!function_exists('trv_translations')) {
    /** O que o painel gravava para um post com as abas de tradução em branco. */
    function trv_translations(): string
    {
        return (string) json_encode([
            'en' => ['content' => '<p></p>', 'dek' => 'Summary'],
            'es' => ['content' => '<p></p>'],
        ]);
    }

    function trv_fields(): string
    {
        return (string) json_encode(['content' => '<p>Corpo.</p>', 'dek' => 'Resumo']);
    }

    function trv_item(): Item
    {
        return Item::fromRow([
            'id' => 1, 'type' => 'post', 'slug' => 'a', 'title' => 'A', 'template' => null, 'status' => 'published',
            'fields' => trv_fields(), 'created_at' => '2026-01-01', 'updated_at' => '2026-01-01',
            'fields_translations' => trv_translations(),
        ]);
    }

    function trv_page(): Page
    {
        return Page::fromRow([
            'id' => 1, 'page_key' => 'sobre', 'title' => 'Sobre',
            'fields' => trv_fields(), 'fields_translations' => trv_translations(),
        ]);
    }

    function trv_term(): Term
    {
        return Term::fromRow([
            'id' => 1, 'taxonomy' => 'category', 'slug' => 'a', 'name' => 'A',
            'fields' => trv_fields(), 'fields_translations' => trv_translations(),
        ]);
    }

    /** Um app com a tabela `settings` em memória, para o Option ler. */
    function trv_boot_options(array $values, array $translations): void
    {
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, []);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, []);
        $app = new App($config);

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, value TEXT, value_translations TEXT, updated_at TEXT)');
        $pdo->prepare('INSERT INTO settings (setting_key, value, value_translations) VALUES (?, ?, ?)')
            ->execute(['options.footer', json_encode($values), json_encode($translations)]);

        $db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Database::class, 'pdo'))->setValue($db, $pdo);
        $app->db = $db;
        Option::flushCache();
    }
}

foreach (['Item' => 'trv_item', 'Page' => 'trv_page', 'Term' => 'trv_term'] as $trvModel => $trvMake) {
    test("{$trvModel}::fieldIn: <p></p> gravado como tradução cai no idioma padrão", function () use ($trvMake) {
        $record = $trvMake();
        check_same('<p>Corpo.</p>', $record->fieldIn('content', 'en'));
        check_same('<p>Corpo.</p>', $record->fieldIn('content', 'es'));
    });

    test("{$trvModel}::fieldIn: a tradução de verdade ao lado continua valendo", function () use ($trvMake) {
        check_same('Summary', $trvMake()->fieldIn('dek', 'en'));
    });

    test("{$trvModel}::fieldsIn: <p></p> não cobre o valor do idioma padrão", function () use ($trvMake) {
        $record = $trvMake();
        check_same(['content' => '<p>Corpo.</p>', 'dek' => 'Summary'], $record->fieldsIn('en'));
        check_same(['content' => '<p>Corpo.</p>', 'dek' => 'Resumo'], $record->fieldsIn('es'));
    });
}

test('fieldIn / fieldsIn: richtext traduzido só com imagem ou bloco do tema é tradução', function () {
    foreach ([
        '<img src="/storage/uploads/a.jpg" alt="">',
        '<div data-velk-block="bignumbers" data-velk-data="{}"></div>',
        '<table><tbody><tr><td><p></p></td></tr></tbody></table>',
    ] as $html) {
        $item = trv_item();
        $item->fieldsTranslations['en']['content'] = $html;
        check_same($html, $item->fieldIn('content', 'en'), $html);
        check_same($html, $item->fieldsIn('en')['content'], $html);
    }
});

test('fieldIn / fieldsIn: valor que não é HTML passa como antes', function () {
    $item = trv_item();
    $item->fields = ['featured' => true, 'qty' => 5, 'shots' => [1], 'subtitle' => 'Olá'];
    $item->fieldsTranslations = ['en' => ['featured' => false, 'qty' => 0, 'shots' => [2], 'subtitle' => ' ']];
    check_same(false, $item->fieldIn('featured', 'en'));
    check_same(0, $item->fieldIn('qty', 'en'));
    check_same([2], $item->fieldIn('shots', 'en'));
    check_same(' ', $item->fieldIn('subtitle', 'en'));
    check_same(['featured' => false, 'qty' => 0, 'shots' => [2], 'subtitle' => ' '], $item->fieldsIn('en'));
});

test('Option::getPathIn: <p></p> gravado como tradução cai no idioma padrão', function () {
    trv_boot_options(
        ['about' => '<p>Sobre nós.</p>', 'tagline' => 'Olá'],
        ['en' => ['about' => '<p></p>', 'tagline' => 'Hello']]
    );
    check_same('<p>Sobre nós.</p>', Option::getPathIn('footer.about', 'en'));
    check_same('Hello', Option::getPathIn('footer.tagline', 'en'));
    Option::flushCache();
});

test('Option::getPathIn: richtext traduzido com texto continua valendo', function () {
    trv_boot_options(['about' => '<p>Sobre nós.</p>'], ['en' => ['about' => '<p>About us.</p>']]);
    check_same('<p>About us.</p>', Option::getPathIn('footer.about', 'en'));
    Option::flushCache();
});

test('fieldIn / fieldsIn: um campo de texto traduzido só com uma tag em linha é tradução', function () {
    // Ícone ou bandeira por idioma num campo text/textarea: não é o que o
    // editor de texto grava em branco (que começa por um bloco, como <p>).
    $icones = ['<i class="fi fi-us"></i>', '<span class="flag flag-us"></span>', '<a href="/en"></a>'];
    foreach (['Item' => 'trv_item', 'Page' => 'trv_page', 'Term' => 'trv_term'] as $modelo => $make) {
        foreach ($icones as $html) {
            $record = $make();
            $record->fields['icon'] = '<i class="fi fi-br"></i>';
            $record->fieldsTranslations['en']['icon'] = $html;
            check_same($html, $record->fieldIn('icon', 'en'), "{$modelo}::fieldIn {$html}");
            check_same($html, $record->fieldsIn('en')['icon'], "{$modelo}::fieldsIn {$html}");
        }
    }
});

test('Option::getPathIn: um campo de texto traduzido só com uma tag em linha é tradução', function () {
    trv_boot_options(['icon' => '<i class="fi fi-br"></i>'], ['en' => ['icon' => '<i class="fi fi-us"></i>']]);
    check_same('<i class="fi fi-us"></i>', Option::getPathIn('footer.icon', 'en'));
    Option::flushCache();
});
