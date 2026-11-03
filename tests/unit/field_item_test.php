<?php

declare(strict_types=1);

use Velk\FieldRenderer;

// item() é um helper global (não autoloadável); carregue-o. Os casos-guarda
// testados aqui (id vazio/0/inválido) retornam null SEM tocar o banco.
require_once dirname(__DIR__, 2) . '/core/helpers.php';

/** Coleta um único campo item e devolve o valor armazenado. */
if (!function_exists('item_collect')) {
    function item_collect(mixed $raw): mixed
    {
        $defs = [['name' => 'p', 'type' => 'item', 'item_type' => 'produto']];
        $result = FieldRenderer::collect($defs, ['p' => $raw]);
        return $result['p'];
    }
}

test('item/collect: id numérico vira int', function () {
    check_same(12, item_collect('12'));
    check_same(12, item_collect(12));
});

test('item/collect: zero vira null', function () {
    check_same(null, item_collect('0'));
    check_same(null, item_collect(0));
});

test('item/collect: vazio/ausente vira null', function () {
    check_same(null, item_collect(''));
    check_same(null, item_collect(null));
});

test('item/collect: não-numérico vira null', function () {
    check_same(null, item_collect('abc'));
    check_same(null, item_collect([5]));
});

test('item/collect: negativo vira null', function () {
    check_same(null, item_collect('-5'));
});

test('item: id vazio/0/null retorna null (sem banco)', function () {
    check_same(null, item(null));
    check_same(null, item(0));
    check_same(null, item('0'));
    check_same(null, item(''));
});

test('item: id não-numérico retorna null', function () {
    check_same(null, item('abc'));
});

test('item: id negativo retorna null', function () {
    check_same(null, item('-5'));
    check_same(null, item(-5));
});
