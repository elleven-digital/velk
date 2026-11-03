<?php

declare(strict_types=1);

use Velk\FieldRenderer;

// link_tag() é um helper global (não autoloadável) que usa e(); carregue-o.
require_once dirname(__DIR__, 2) . '/core/helpers.php';

/** Coleta um único campo link e devolve o valor armazenado. */
if (!function_exists('link_collect')) {
    function link_collect(mixed $raw): mixed
    {
        $defs = [['name' => 'cta', 'type' => 'link']];
        $result = FieldRenderer::collect($defs, ['cta' => $raw]);
        return $result['cta'];
    }
}

test('link/collect: sub-array completo vira objeto sanitizado', function () {
    $out = link_collect(['url' => 'https://velk.com', 'title' => 'Site', 'new_tab' => '1']);
    check_same(['title' => 'Site', 'url' => 'https://velk.com', 'new_tab' => true], $out);
});

test('link/collect: url vazia vira null (title/new_tab ignorados)', function () {
    check_same(null, link_collect(['url' => '', 'title' => 'Ignorado', 'new_tab' => '1']));
});

test('link/collect: url só com espaços vira null', function () {
    check_same(null, link_collect(['url' => '   ', 'title' => 'x']));
});

test('link/collect: url é trimada', function () {
    $out = link_collect(['url' => '  https://x.com  ']);
    check_same('https://x.com', $out['url']);
});

test('link/collect: title só com espaços é trimado', function () {
    $out = link_collect(['url' => 'https://x.com', 'title' => '  Olá  ']);
    check_same('Olá', $out['title']);
});

test('link/collect: new_tab "0" é false', function () {
    $out = link_collect(['url' => 'https://x.com', 'new_tab' => '0']);
    check_same(false, $out['new_tab']);
});

test('link/collect: new_tab ausente é false', function () {
    $out = link_collect(['url' => 'https://x.com']);
    check_same(false, $out['new_tab']);
});

test('link/collect: raw não-array (string) vira null', function () {
    check_same(null, link_collect('oops'));
});

test('link/collect: url ausente (sem a chave) vira null', function () {
    check_same(null, link_collect(['title' => 'sem url']));
});

test('link_tag: link nulo/vazio retorna string vazia', function () {
    check_same('', link_tag(null));
    check_same('', link_tag(['url' => '']));
    check_same('', link_tag(['url' => '   ']));
});

test('link_tag: monta <a> com href escapado', function () {
    $html = link_tag(['url' => 'https://x.com', 'new_tab' => false], 'Clique');
    check_same('<a href="https://x.com">Clique</a>', $html);
});

test('link_tag: new_tab adiciona target e rel', function () {
    $html = link_tag(['url' => 'https://x.com', 'new_tab' => true]);
    check(strpos($html, 'target="_blank"') !== false, 'tem target=_blank');
    check(strpos($html, 'rel="noopener noreferrer"') !== false, 'tem rel');
});

test('link_tag: sem new_tab não adiciona target', function () {
    $html = link_tag(['url' => 'https://x.com', 'new_tab' => false]);
    check(strpos($html, 'target=') === false, 'sem target');
});

test('link_tag: texto cai pra title e depois pra url', function () {
    check(strpos(link_tag(['url' => 'https://x.com', 'title' => 'Meu site']), '>Meu site</a>') !== false, 'usa title');
    check(strpos(link_tag(['url' => 'https://x.com']), '>https://x.com</a>') !== false, 'cai pra url');
});

test('link_tag: attrs extras viram atributos escapados', function () {
    $html = link_tag(['url' => 'https://x.com'], 'Ir', ['class' => 'btn']);
    check(strpos($html, 'class="btn"') !== false, 'tem class');
});

test('link_tag: escapa url e texto (XSS)', function () {
    $html = link_tag(['url' => 'https://x.com"><script>', 'new_tab' => false], '<b>oi</b>');
    check(strpos($html, '<script>') === false, 'url não injeta <script>');
    check(strpos($html, '<b>oi</b>') === false, 'texto é escapado');
});
