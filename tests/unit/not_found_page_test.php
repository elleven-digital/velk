<?php

declare(strict_types=1);

use Velk\Config;

/**
 * Config exige rootPath + env.php no construtor — pra testes puros,
 * instancia sem construtor e injeta o array `site` via reflection.
 * (defaultLanguage(), pages() e site() leem só $this->site.)
 */
if (!function_exists('config_with_site')) {
    function config_with_site(array $site): Config
    {
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        $prop = new ReflectionProperty(Config::class, 'site');
        $prop->setValue($config, $site);
        return $config;
    }
}

test('notFoundPageKey: lê a chave quando string não-vazia', function () {
    $c = config_with_site(['not_found_page' => 'erro-404']);
    check_same('erro-404', $c->notFoundPageKey());
});

test('notFoundPageKey: ausente/vazia/não-string viram null', function () {
    check_same(null, config_with_site([])->notFoundPageKey());
    check_same(null, config_with_site(['not_found_page' => ''])->notFoundPageKey());
    check_same(null, config_with_site(['not_found_page' => 123])->notFoundPageKey());
    check_same(null, config_with_site(['not_found_page' => ['x']])->notFoundPageKey());
});

/** True quando a seção 1 (pages do site.json) NÃO casou o path. */
if (!function_exists('json_pages_miss')) {
    function json_pages_miss(string $path, Velk\Config $c): bool
    {
        try {
            return \Velk\Models\Page::resolveByUrl($path, $c) === null;
        } catch (\Throwable $e) {
            return true; // seção 2 (DB) alcançada = seção 1 não casou
        }
    }
}

test('resolveByUrl: a page apontada como 404 não resolve pela URL derivada', function () {
    $c = config_with_site([
        'pages' => [
            'sobre' => ['label' => 'Sobre', 'template' => 'sobre.php'],
            'erro-404' => ['label' => 'Não encontrada', 'template' => '404.php'],
        ],
        'not_found_page' => 'erro-404',
    ]);
    // Page normal continua resolvendo (seção 1, sem DB).
    $hit = \Velk\Models\Page::resolveByUrl('/sobre', $c);
    check($hit !== null && $hit['key'] === 'sobre', 'page normal resolve');
    // A 404 não resolve nem pela URL derivada da key.
    check(json_pages_miss('/erro-404', $c), 'a 404 não casa por URL derivada');
});

test('resolveByUrl: url declarada por engano na page 404 também não resolve', function () {
    $c = config_with_site([
        'pages' => [
            'erro-404' => ['label' => 'X', 'template' => '404.php', 'url' => '/oops'],
        ],
        'not_found_page' => 'erro-404',
    ]);
    check(json_pages_miss('/oops', $c), 'url declarada na 404 não casa');
});
