<?php

declare(strict_types=1);

use Velk\Admin\SaleFields;
use Velk\App;
use Velk\Config;

/**
 * O campo `price` que o engine anexa a todo tipo `sellable: true`, no editor
 * do item.
 *
 * Até a v3.9.0 o rótulo e a ajuda eram texto cravado em português no Config —
 * fora do catálogo do painel, então não seguiam o idioma de quem usa — e a
 * ajuda dizia "valor em reais" mesmo com `store.currency` em USD.
 */

require_once dirname(__DIR__, 2) . '/core/helpers.php';

if (!function_exists('sfx_boot')) {
    function sfx_boot(string $locale, array $store = []): App
    {
        $root = dirname(__DIR__, 2);
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, ['paths' => ['core' => $root . '/core']]);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, $store !== [] ? ['store' => $store] : []);
        $app = new App($config);
        $app->adminI18n = new \Velk\Admin\I18n($root . '/core/Admin/lang', $locale, 'en');
        return $app;
    }
}

test('preço da loja: o campo continua `price`, texto (é o que item_price() lê)', function () {
    sfx_boot('en');
    $fields = SaleFields::fields('BRL');
    check_same(1, count($fields));
    check_same('price', $fields[0]['name']);
    check_same('text', $fields[0]['type']);
});

test('preço da loja: rótulo segue o idioma do painel', function () {
    foreach (['en' => 'Price', 'pt' => 'Preço', 'es' => 'Precio'] as $locale => $label) {
        sfx_boot($locale);
        check_same($label, SaleFields::fields('BRL')[0]['label'], $locale);
    }
});

test('preço da loja: a ajuda cita a moeda da loja, e não "reais"', function () {
    foreach (['en', 'pt', 'es'] as $locale) {
        sfx_boot($locale);
        $help = (string) SaleFields::fields('USD')[0]['help'];
        check(str_contains($help, 'USD'), "{$locale}: {$help}");
        check(!preg_match('/reais|\bR\$/iu', $help), "{$locale}: {$help}");
    }
});

test('preço da loja: Config::saleFields() usa store.currency (BRL quando ausente)', function () {
    $app = sfx_boot('pt', ['currency' => 'eur']);
    check_same(SaleFields::fields('EUR'), $app->config->saleFields());
    check(str_contains((string) $app->config->saleFields()[0]['help'], 'EUR'));

    $semLoja = sfx_boot('pt');
    check(str_contains((string) $semLoja->config->saleFields()[0]['help'], 'BRL'));
});
