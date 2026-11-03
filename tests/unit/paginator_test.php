<?php

declare(strict_types=1);

use Velk\Paginator;

test('paginator: caso básico', function () {
    $pg = Paginator::make('2', 120, 50);
    check_same(2, $pg['page']);
    check_same(50, $pg['perPage']);
    check_same(50, $pg['offset']);
    check_same(3, $pg['totalPages']);
    check_same(120, $pg['total']);
});

test('paginator: lixo/ausente/zero/negativo viram página 1', function () {
    check_same(1, Paginator::make(null, 100)['page']);
    check_same(1, Paginator::make('', 100)['page']);
    check_same(1, Paginator::make('abc', 100)['page']);
    check_same(1, Paginator::make('0', 100)['page']);
    check_same(1, Paginator::make('-3', 100)['page']);
    check_same(1, Paginator::make(['x'], 100)['page']);
});

test('paginator: página além do fim clampa na última', function () {
    $pg = Paginator::make('999', 120, 50);
    check_same(3, $pg['page']);
    check_same(100, $pg['offset']);
});

test('paginator: total 0 → 1 página, offset 0', function () {
    $pg = Paginator::make('5', 0, 50);
    check_same(1, $pg['page']);
    check_same(1, $pg['totalPages']);
    check_same(0, $pg['offset']);
});

test('paginator: perPage custom e default', function () {
    check_same(60, Paginator::make(null, 10, 60)['perPage']);
    check_same(50, Paginator::make(null, 10)['perPage']);
    check_same(2, Paginator::make('2', 61, 60)['totalPages']);
});
