<?php

declare(strict_types=1);

use Velk\Config;
use Velk\Models\Language;
use Velk\Models\Page;
use Velk\Sitemap;

/**
 * Os montadores de path por idioma, na parte que não precisa de banco.
 *
 * Num idioma não-padrão, cada nível do path usa o slug traduzido — ou o
 * padrão, quando aquele nível não tem tradução (a regra completa, com
 * banco, está em slug_traduzido_padrao_test.php). Até a v3.8.x isso era
 * estrito: um nível sem tradução deixava o conteúdo sem endereço no idioma.
 *
 * Aqui: Page::fullPath no idioma padrão e na home (que não toca o banco em
 * idioma nenhum — o segmento dela é sempre vazio) e Sitemap::langUrl, que
 * monta a URL absoluta de um path num idioma.
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

// ── Page::fullPath — idioma padrão (sem DB) ─────────────────────────────

test('Page::fullPath: idioma padrão (ou nenhum) monta a cadeia de pais', function () {
    $c = config_with_site(['pages' => [
        'servicos'    => ['label' => 'Serviços'],
        'consultoria' => ['label' => 'Consultoria', 'parent' => 'servicos'],
    ]]);
    check_same('/servicos/consultoria', Page::fullPath('consultoria', $c));
});

// ── Page::fullPath — a home é universal em QUALQUER idioma (sem DB) ─────
// A home nunca tem slug pra traduzir (segmento default sempre ''), então
// fullPath nem consulta o DB por ela — dá pra provar isso sem bootar o App.
// (Uma página comum, não-home, bate no DB mesmo na raiz, para ler a
// tradução do PRÓPRIO nível; só a home, e qualquer página com segmento
// default vazio, têm esse atalho.)

test('Page::fullPath: home em idioma não-padrão → / (nada a traduzir)', function () {
    $c = config_with_site(['pages' => ['home' => ['label' => 'Home']]]);
    check_same('/', Page::fullPath('home', $c, 'en'));
    check_same('/', Page::fullPath('home', $c, 'es'));
});

test('Page::fullPath: página com "url":"" (segmento vazio) em idioma não-padrão → / (mesmo atalho)', function () {
    $c = config_with_site(['pages' => ['raiz-alt' => ['label' => 'X', 'url' => '']]]);
    check_same('/', Page::fullPath('raiz-alt', $c, 'en'));
});

test('Page::resolveByUrl: "/" sem página home no idioma padrão → null', function () {
    $c = config_with_site(['pages' => ['sobre' => ['label' => 'Sobre', 'url' => '/sobre']]]);
    check_same(null, Page::resolveByUrl('/', $c));
});

// ── Sitemap::langUrl — pura, via Reflection (privada+estática) ──────────
// Recebe o path JÁ montado por Page/Item/Term::fullPath no idioma — não
// toca DB nem Config, só prefixa a base e o idioma.

if (!function_exists('sitemap_lang_url')) {
    function sitemap_lang_url(string $base, Language $lang, string $path): string
    {
        // No setAccessible() — private/protected members are reflectable
        // by default since PHP 8.1.
        return (new ReflectionMethod(Sitemap::class, 'langUrl'))->invoke(null, $base, $lang, $path);
    }
}

if (!function_exists('mk_language')) {
    function mk_language(string $code, ?string $urlPrefix): Language
    {
        $lang = new Language();
        $lang->code = $code;
        $lang->label = $code;
        $lang->urlPrefix = $urlPrefix;
        return $lang;
    }
}

test('Sitemap::langUrl: home vira a raiz do idioma', function () {
    check_same('https://x.com/en/', sitemap_lang_url('https://x.com', mk_language('en', '/en'), '/'));
    check_same('https://x.com/', sitemap_lang_url('https://x.com', mk_language('en', ''), '/'));
});

test('Sitemap::langUrl: path com o prefixo do idioma', function () {
    check_same('https://x.com/en/services/consulting', sitemap_lang_url('https://x.com', mk_language('en', '/en'), '/services/consulting'));
});

test('Sitemap::langUrl: prefixo de idioma vazio (raiz) ainda monta corretamente', function () {
    check_same('https://x.com/services', sitemap_lang_url('https://x.com', mk_language('en', ''), '/services'));
});
