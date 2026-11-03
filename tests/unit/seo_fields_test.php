<?php

declare(strict_types=1);

use Velk\Admin\SeoFields;
use Velk\App;
use Velk\Commands\Content;
use Velk\Config;

/**
 * Os campos de SEO que o engine anexa a toda página e a todo tipo com página
 * (meta_title, meta_description, og_image), no editor e no `item:schema` /
 * `page:schema` do CLI.
 *
 * Até a v3.9.0 o rótulo e a ajuda eram texto cravado em português no Config:
 * fora do catálogo do painel, um admin em inglês lia o título da seção em
 * inglês e os três campos dela em português.
 */

require_once dirname(__DIR__, 2) . '/core/helpers.php';

if (!function_exists('seo_boot')) {
    function seo_boot(string $locale): App
    {
        $root = dirname(__DIR__, 2);
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, ['paths' => ['core' => $root . '/core']]);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, []);
        $app = new App($config);
        $app->adminI18n = new \Velk\Admin\I18n($root . '/core/Admin/lang', $locale, 'en');
        return $app;
    }

    /** @return array<string, array<string, mixed>> */
    function seo_by_name(array $fields): array
    {
        $out = [];
        foreach ($fields as $f) {
            $out[$f['name']] = $f;
        }
        return $out;
    }
}

test('SEO: nomes e tipos não mudam (são as chaves gravadas em fields)', function () {
    seo_boot('en');
    check_same(
        ['meta_title' => 'text', 'meta_description' => 'textarea', 'og_image' => 'image'],
        array_map(fn($f) => $f['type'], seo_by_name(SeoFields::fields()))
    );
});

test('SEO: rótulos seguem o idioma do painel', function () {
    $esperado = [
        'en' => ['Meta title', 'Meta description', 'Share image'],
        'pt' => ['Meta título', 'Meta descrição', 'Imagem de compartilhamento'],
        'es' => ['Meta título', 'Meta descripción', 'Imagen para compartir'],
    ];
    foreach ($esperado as $locale => $labels) {
        seo_boot($locale);
        check_same($labels, array_column(SeoFields::fields(), 'label'), $locale);
    }
});

test('SEO: toda ajuda vem do catálogo, sem texto em português no painel em inglês', function () {
    seo_boot('en');
    foreach (SeoFields::fields() as $f) {
        check((string) ($f['help'] ?? '') !== '', $f['name'] . ' sem ajuda');
        check(!preg_match('/[ãçõéí]|\b(aparece|usado|mostrada)\b/iu', (string) $f['help']), $f['name'] . ': ' . $f['help']);
    }
});

test('SEO: Config::seoFields() entrega os campos traduzidos', function () {
    $app = seo_boot('pt');
    check_same(SeoFields::fields(), $app->config->seoFields());
});

test('SEO: o schema do CLI (expandFields) anexa os campos traduzidos só a quem tem página', function () {
    $app = seo_boot('en');
    $expand = new ReflectionMethod(Content::class, 'expandFields');
    $comPagina = seo_by_name($expand->invoke(null, $app, [['name' => 'resumo', 'type' => 'text']], true));
    check_same('Meta title', $comPagina['meta_title']['label']);
    check(isset($comPagina['resumo']), 'os campos do tipo continuam lá');
    $semPagina = seo_by_name($expand->invoke(null, $app, [['name' => 'resumo', 'type' => 'text']], false));
    check(!isset($semPagina['meta_title']), 'has_page: false não ganha SEO');
});
