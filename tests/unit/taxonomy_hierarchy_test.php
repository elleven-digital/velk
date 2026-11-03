<?php

declare(strict_types=1);

use Velk\Config;

/**
 * Hierarquia em taxonomias. A flag (isTaxonomyHierarchical) é pura e testável
 * via config_with_site(). Term::findChild, Term::fullPath e a resolução do
 * roteador tocam o DB → validação por script de integração/browser, como
 * itens e custom pages.
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

test('isTaxonomyHierarchical: lê a flag "hierarchical" da taxonomia', function () {
    $c = config_with_site(['taxonomies' => [
        'topic'    => ['hierarchical' => true],
        'tag'      => [],
        'category' => ['hierarchical' => false],
    ]]);
    check_same(true, $c->isTaxonomyHierarchical('topic'));
    check_same(false, $c->isTaxonomyHierarchical('tag'), 'ausente → false');
    check_same(false, $c->isTaxonomyHierarchical('category'), 'explicit false → false');
    check_same(false, $c->isTaxonomyHierarchical('inexistente'), 'taxonomia inexistente → false');
});

test('isTaxonomyHierarchical: valores não-booleanos não ativam', function () {
    $c = config_with_site(['taxonomies' => [
        'a' => ['hierarchical' => 1],
        'b' => ['hierarchical' => 'true'],
    ]]);
    check_same(false, $c->isTaxonomyHierarchical('a'), '1 (int) não é true estrito');
    check_same(false, $c->isTaxonomyHierarchical('b'), '"true" (string) não é true estrito');
});
