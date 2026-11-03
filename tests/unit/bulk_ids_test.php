<?php

declare(strict_types=1);

use Velk\Admin\Controllers\Controller;

test('parseBulkIds: array de strings numéricas vira lista de ints', function () {
    check_same([12, 15], Controller::parseBulkIds(['12', '15']));
});

test('parseBulkIds: descarta 0/negativo/não-numérico e deduplica', function () {
    check_same([15], Controller::parseBulkIds(['0', '-3', 'abc', '15', '15']));
});

test('parseBulkIds: entrada não-array (null, string, int, bool) vira []', function () {
    check_same([], Controller::parseBulkIds(null));
    check_same([], Controller::parseBulkIds('12,15'));
    check_same([], Controller::parseBulkIds(42));
    check_same([], Controller::parseBulkIds(true));
});

test('parseBulkIds: array vazio vira []', function () {
    check_same([], Controller::parseBulkIds([]));
});

test('parseBulkIds: preserva a ordem de primeira ocorrência', function () {
    check_same([3, 1, 2], Controller::parseBulkIds(['3', '1', '2']));
});

test('parseBulkIds: aceita ints "puros" (não só strings)', function () {
    check_same([7, 8], Controller::parseBulkIds([7, 8]));
});

test('parseBulkIds: corta em $max preservando os primeiros já deduplicados', function () {
    $raw = array_map('strval', range(1, 250));
    $result = Controller::parseBulkIds($raw, 200);
    check(count($result) === 200, 'esperava 200 ids, veio ' . count($result));
    check_same(range(1, 200), $result);
});

test('parseBulkIds: respeita $max customizado', function () {
    check_same([1, 2], Controller::parseBulkIds(['1', '2', '3'], 2));
});

test('parseBulkIds: nunca lança mesmo com lixo aninhado (array/objeto como elemento)', function () {
    check_same([5], Controller::parseBulkIds([['x'], 5, new stdClass(), null, false]));
});
