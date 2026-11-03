<?php

declare(strict_types=1);

use Velk\App;
use Velk\Config;
use Velk\Database;
use Velk\I18n;
use Velk\Models\Option;

/**
 * `options($key)` resolve o idioma ativo, como `option($path)` sempre fez.
 *
 * Até a v3.9.0 o helper devolvia `Option::getGroup()` cru: num site
 * multi-idioma, em /en, `option('footer.tagline')` vinha traduzido e
 * `options('footer')['tagline']` vinha no idioma padrão — o mesmo valor por
 * dois caminhos, com respostas diferentes, e nada avisava o autor do tema.
 */

require_once dirname(__DIR__, 2) . '/core/helpers.php';

if (!function_exists('oi_boot')) {
    /**
     * App com a tabela `settings` em memória e o idioma ativo já resolvido.
     * Sem `$comTraducoes`, a tabela nem tem a coluna `value_translations`:
     * qualquer leitura de tradução quebra a consulta — é assim que os testes
     * provam que o caminho do idioma padrão não paga uma consulta a mais.
     */
    function oi_boot(array $values, ?array $translations, string $lang, bool $i18n = true, bool $comTraducoes = true): void
    {
        $site = ['i18n' => ['enabled' => $i18n, 'default' => 'pt-BR', 'default_label' => 'Português']];
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, []);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, $site);
        $app = new App($config);

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        if ($comTraducoes) {
            $pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, value TEXT, value_translations TEXT, updated_at TEXT)');
            $pdo->prepare('INSERT INTO settings (setting_key, value, value_translations) VALUES (?, ?, ?)')
                ->execute(['options.footer', json_encode($values), $translations === null ? null : json_encode($translations)]);
        } else {
            $pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, value TEXT, updated_at TEXT)');
            $pdo->prepare('INSERT INTO settings (setting_key, value) VALUES (?, ?)')
                ->execute(['options.footer', json_encode($values)]);
        }
        $db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Database::class, 'pdo'))->setValue($db, $pdo);
        $app->db = $db;

        $i18nRef = new ReflectionClass(I18n::class);
        $i18nObj = $i18nRef->newInstanceWithoutConstructor();
        $i18nRef->getProperty('config')->setValue($i18nObj, $config);
        $i18nRef->getProperty('activeLanguage')->setValue($i18nObj, $lang);
        $app->i18n = $i18nObj;

        Option::flushCache();
    }

    function oi_values(): array
    {
        return [
            'tagline' => 'Olá',
            'about' => '<p>Sobre nós.</p>',
            'phone' => '(11) 4000-0000',
            'social' => [['network' => 'instagram', 'url' => 'https://instagram.com/velk']],
        ];
    }
}

test('Option::getGroupIn: o campo traduzido cobre o padrão; o resto cai no idioma padrão', function () {
    oi_boot(oi_values(), ['en' => ['tagline' => 'Hello', 'about' => '<p>About us.</p>']], 'en');
    check_same([
        'tagline' => 'Hello',
        'about' => '<p>About us.</p>',
        'phone' => '(11) 4000-0000',
        'social' => [['network' => 'instagram', 'url' => 'https://instagram.com/velk']],
    ], Option::getGroupIn('footer', 'en'));
});

test('Option::getGroupIn: vazio, null e richtext em branco contam como não traduzidos', function () {
    oi_boot(oi_values(), ['en' => ['tagline' => '', 'about' => '<p></p>', 'phone' => null]], 'en');
    check_same(oi_values(), Option::getGroupIn('footer', 'en'));
});

test('Option::getGroupIn: idioma sem tradução nenhuma devolve o grupo do idioma padrão', function () {
    oi_boot(oi_values(), ['es' => ['tagline' => 'Hola']], 'en');
    check_same(oi_values(), Option::getGroupIn('footer', 'en'));
    oi_boot(oi_values(), null, 'en');
    check_same(oi_values(), Option::getGroupIn('footer', 'en'));
});

test('Option::getGroupIn: repeater traduzido substitui o do padrão inteiro (merge raso, como fieldsIn)', function () {
    $social = [['network' => 'linkedin', 'url' => 'https://linkedin.com/company/velk']];
    oi_boot(oi_values(), ['en' => ['social' => $social]], 'en');
    check_same($social, Option::getGroupIn('footer', 'en')['social']);
});

test('options(): em idioma não padrão devolve o grupo traduzido', function () {
    oi_boot(oi_values(), ['en' => ['tagline' => 'Hello', 'about' => '<p></p>']], 'en');
    $grupo = options('footer');
    check_same('Hello', $grupo['tagline']);
    check_same('<p>Sobre nós.</p>', $grupo['about'], 'richtext em branco na tradução cai no padrão');
});

test('options(): cada campo de primeiro nível bate com option() no mesmo idioma', function () {
    oi_boot(oi_values(), ['en' => ['tagline' => 'Hello', 'about' => '<p></p>', 'phone' => '']], 'en');
    foreach (options('footer') as $campo => $valor) {
        check_same(option('footer.' . $campo), $valor, $campo);
    }
});

test('options(): no idioma padrão não lê value_translations', function () {
    oi_boot(oi_values(), null, 'pt-BR', true, false);
    check_same(oi_values(), options('footer'));
});

test('options(): num site de um idioma só não lê value_translations', function () {
    oi_boot(oi_values(), null, 'en', false, false);
    check_same(oi_values(), options('footer'));
});

Option::flushCache();
