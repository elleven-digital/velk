<?php

declare(strict_types=1);

use Velk\Services\Money;

test('toCents: vírgula decimal (pt-BR)', function () {
    check_same(2990, Money::toCents('29,90'));
});

test('toCents: ponto decimal', function () {
    check_same(2990, Money::toCents('29.90'));
});

test('toCents: milhar com ponto, decimal com vírgula', function () {
    check_same(123456, Money::toCents('1.234,56'));
});

test('toCents: milhar com vírgula, decimal com ponto', function () {
    check_same(123456, Money::toCents('1,234.56'));
});

test('toCents: inteiro sem separador = reais', function () {
    check_same(299000, Money::toCents('2990'));
});

test('toCents: float', function () {
    check_same(2990, Money::toCents(29.9));
});

test('toCents: int', function () {
    check_same(3000, Money::toCents(30));
});

test('toCents: símbolo de moeda é ignorado', function () {
    check_same(5990, Money::toCents('R$ 59,90'));
});

test('toCents: vazio e null viram null', function () {
    check_same(null, Money::toCents(''));
    check_same(null, Money::toCents(null));
});

// Correctness I9: ponto solitário (sem vírgula) em notação de milhar pt-BR
// não pode ser confundido com separador decimal — "1.500" é mil e
// quinhentos reais, não um real e meio.
test('toCents: ponto solitário como milhar pt-BR (1.500 = mil e quinhentos)', function () {
    check_same(150000, Money::toCents('1.500'));
});

test('toCents: múltiplos pontos de milhar (1.234.567)', function () {
    check_same(123456700, Money::toCents('1.234.567'));
});

test('toCents: ponto com 1 casa continua decimal (1.5)', function () {
    check_same(150, Money::toCents('1.5'));
});

test('toCents: ponto com 2 casas continua decimal (1.50)', function () {
    check_same(150, Money::toCents('1.50'));
});

test('toCents: ponto decimal en-US com 2 casas continua inalterado (29.90)', function () {
    check_same(2990, Money::toCents('29.90'));
});

test('toCents: milhar com ponto + decimal com vírgula continua inalterado (1.500,00)', function () {
    check_same(150000, Money::toCents('1.500,00'));
});

test('toCents: vírgula decimal pt-BR continua inalterada (49,90)', function () {
    check_same(4990, Money::toCents('49,90'));
});

test('format: BRL', function () {
    check_same('R$ 29,90', Money::format(2990, 'BRL'));
    check_same('R$ 1.234,56', Money::format(123456, 'BRL'));
});

test('format: moeda genérica', function () {
    check_same('USD 59.90', Money::format(5990, 'USD'));
});
