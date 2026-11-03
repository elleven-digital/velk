<?php

declare(strict_types=1);

use Velk\Config;

/**
 * Hierarquia em itens. A flag do item_type (isItemTypeHierarchical) é pura
 * e testável via config_with_site(). A montagem de path reusa PathChain
 * (coberto pelos testes de buildPath). Item::fullPath e a resolução do
 * roteador tocam o DB → validação no browser, como as custom pages.
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

test('isItemTypeHierarchical: lê a flag "hierarchical" do item_type', function () {
    $c = config_with_site(['item_types' => [
        'docs' => ['hierarchical' => true],
        'blog' => [],
        'x'    => ['hierarchical' => false],
    ]]);
    check_same(true, $c->isItemTypeHierarchical('docs'));
    check_same(false, $c->isItemTypeHierarchical('blog'), 'ausente → false');
    check_same(false, $c->isItemTypeHierarchical('x'), 'explicit false → false');
    check_same(false, $c->isItemTypeHierarchical('inexistente'), 'tipo inexistente → false');
});

test('isItemTypeHierarchical: valores não-booleanos não ativam', function () {
    $c = config_with_site(['item_types' => [
        'a' => ['hierarchical' => 1],
        'b' => ['hierarchical' => 'true'],
    ]]);
    check_same(false, $c->isItemTypeHierarchical('a'), '1 (int) não é true estrito');
    check_same(false, $c->isItemTypeHierarchical('b'), '"true" (string) não é true estrito');
});
