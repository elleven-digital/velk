<?php

declare(strict_types=1);

use Velk\FieldRenderer as FR;

/**
 * `show_if` — visibilidade condicional de campo.
 *
 * Duas coisas aqui não são detalhe:
 *
 * A comparação é TOLERANTE. Um `boolean` volta como bool do JSON e como
 * "1"/"" de um POST cru; um select de números volta como string. Com `===`,
 * `"equals": true` falharia justamente no caso mais comum — checkbox
 * controlando um bloco.
 *
 * Condição malformada deixa o campo VISÍVEL. Sumir por erro de configuração é
 * pior que um campo a mais na tela, e o schema:validate acusa antes.
 */

if (!function_exists('si_defs')) {
    function si_defs(): array
    {
        return [
            ['name' => 'tem_cta', 'type' => 'boolean'],
            ['name' => 'url', 'type' => 'url', 'required' => true,
             'show_if' => ['field' => 'tem_cta', 'equals' => true]],
        ];
    }
}

test('equals com booleano funciona nas formas que o valor chega', function () {
    $f = ['show_if' => ['field' => 'x', 'equals' => true]];

    foreach ([true, 1, '1', 'on'] as $v) {
        check_same(true, FR::matchesShowIf($f, ['x' => $v]), 'deveria casar: ' . var_export($v, true));
    }
    foreach ([false, 0, '0', '', null] as $v) {
        check_same(false, FR::matchesShowIf($f, ['x' => $v]), 'não deveria casar: ' . var_export($v, true));
    }
    // Ausente é o mesmo que falso — campo nunca preenchido.
    check_same(false, FR::matchesShowIf($f, []));
});

test('equals com string compara por valor, não por tipo', function () {
    $f = ['show_if' => ['field' => 'tipo', 'equals' => 'video']];
    check_same(true,  FR::matchesShowIf($f, ['tipo' => 'video']));
    check_same(false, FR::matchesShowIf($f, ['tipo' => 'mapa']));
    check_same(false, FR::matchesShowIf($f, []));

    // Select de números: o valor volta string, a condição pode vir number.
    $n = ['show_if' => ['field' => 'n', 'equals' => 2]];
    check_same(true, FR::matchesShowIf($n, ['n' => '2']));
    check_same(true, FR::matchesShowIf($n, ['n' => 2]));
});

test('in aceita qualquer um da lista', function () {
    $f = ['show_if' => ['field' => 'tipo', 'in' => ['mapa', 'video']]];
    check_same(true,  FR::matchesShowIf($f, ['tipo' => 'mapa']));
    check_same(true,  FR::matchesShowIf($f, ['tipo' => 'video']));
    check_same(false, FR::matchesShowIf($f, ['tipo' => 'texto']));
    check_same(false, FR::matchesShowIf($f, []));

    // Lista vazia não casa com nada — mas o schema:validate reprova antes.
    check_same(false, FR::matchesShowIf(['show_if' => ['field' => 't', 'in' => []]], ['t' => 'x']));
});

test('condição malformada deixa VISÍVEL, nunca escondido', function () {
    foreach ([
        [],                                   // sem show_if
        ['show_if' => 'sim'],                 // não é objeto
        ['show_if' => ['equals' => 'x']],     // sem field
        ['show_if' => ['field' => '', 'equals' => 'x']],
        ['show_if' => ['field' => 'x']],      // sem operador
    ] as $i => $f) {
        check_same(true, FR::matchesShowIf($f, []), "caso #{$i} deveria ficar visível");
    }
});

test('escondido perde o required; visível recupera', function () {
    // O ponto: `required` invisível trava o submit sem nada na tela.
    $off = FR::renderFields(si_defs(), ['tem_cta' => false]);
    check(preg_match('/data-show-if=[^>]*hidden/', $off) === 1, 'deveria vir hidden');
    check(preg_match('/name="fields\[url\]"[^>]*required/', $off) !== 1, 'não pode ficar required escondido');

    $on = FR::renderFields(si_defs(), ['tem_cta' => true]);
    check(preg_match('/data-show-if=[^>]*hidden/', $on) !== 1, 'não deveria vir hidden');
    check(preg_match('/name="fields\[url\]"[^>]*required/', $on) === 1, 'deveria voltar required');

    // E o JS precisa saber que era obrigatório, pra devolver ao reaparecer.
    check(str_contains($off, 'data-required-when-visible="1"'), 'faltou a marca pro JS');
});

test('a condição viaja pro JS no atributo', function () {
    $html = FR::renderFields(si_defs(), ['tem_cta' => false]);
    check(str_contains($html, 'data-show-if='), 'faltou data-show-if');
    // Aspas do JSON escapadas — senão fecham o atributo.
    check(!str_contains($html, 'data-show-if="{"'), 'JSON não escapado quebraria o atributo');
});

test('campo sem show_if não ganha atributo nenhum', function () {
    $html = FR::renderFields([['name' => 'x', 'type' => 'text']], []);
    check(!str_contains($html, 'data-show-if'), 'campo comum não deveria ter data-show-if');
    check(!str_contains($html, 'hidden'), 'campo comum não deveria vir hidden');
});

test('schema:validate cobre os erros que somem em silêncio', function () {
    $casos = [
        'inexistente' => [[['name' => 'u', 'type' => 'url', 'show_if' => ['field' => 'nao', 'equals' => 1]]], 'same scope'],
        'auto'        => [[['name' => 'u', 'type' => 'url', 'show_if' => ['field' => 'u', 'equals' => 1]]], 'itself'],
        'sem op'      => [[['name' => 't', 'type' => 'text'], ['name' => 'u', 'type' => 'url', 'show_if' => ['field' => 't']]], 'needs one of'],
        'dois ops'    => [[['name' => 't', 'type' => 'text'], ['name' => 'u', 'type' => 'url', 'show_if' => ['field' => 't', 'equals' => 1, 'in' => [1]]]], 'only one of'],
        'in inválido' => [[['name' => 't', 'type' => 'text'], ['name' => 'u', 'type' => 'url', 'show_if' => ['field' => 't', 'in' => 'x']]], 'must be a list'],
        'não objeto'  => [[['name' => 't', 'type' => 'text'], ['name' => 'u', 'type' => 'url', 'show_if' => 'sim']], 'must be an object'],
    ];
    foreach ($casos as $nome => [$defs, $esperado]) {
        $issues = FR::validateFields($defs, 'teste');
        check($issues !== [], "{$nome} deveria reprovar");
        check(str_contains($issues[0] ?? '', $esperado), "{$nome}: mensagem deveria citar '{$esperado}'");
    }
});

test('escopo: group é transparente, repetidor não', function () {
    // Dentro de group o campo compartilha o array plano da linha/formulário.
    $comGroup = [
        ['type' => 'group', 'fields' => [['name' => 't', 'type' => 'text']]],
        ['name' => 'u', 'type' => 'url', 'show_if' => ['field' => 't', 'equals' => 'x']],
    ];
    check_same([], FR::validateFields($comGroup, 'teste'));

    // Já uma linha de repetidor é escopo próprio — não enxerga o de fora.
    $cruzando = [
        ['name' => 't', 'type' => 'text'],
        ['name' => 'r', 'type' => 'repeater', 'fields' => [
            ['name' => 'u', 'type' => 'url', 'show_if' => ['field' => 't', 'equals' => 'x']],
        ]],
    ];
    check(FR::validateFields($cruzando, 'teste') !== [], 'atravessar o repetidor deveria reprovar');

    // Mas dentro da MESMA linha funciona.
    $dentro = [
        ['name' => 'r', 'type' => 'repeater', 'fields' => [
            ['name' => 'd', 'type' => 'boolean'],
            ['name' => 'c', 'type' => 'text', 'show_if' => ['field' => 'd', 'equals' => true]],
        ]],
    ];
    check_same([], FR::validateFields($dentro, 'teste'));
});
