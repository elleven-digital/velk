<?php

declare(strict_types=1);

use Velk\Params;
use Velk\Services\FormCsv;

/**
 * `FormCsv::requested()`, `select()`, `unknown()` e `validDate()` — o que foi
 * pedido (checkboxes e período do painel, `--cols`/`--from`/`--to` do CLI)
 * resolvido no que sai no arquivo.
 */

function fcsv_select_available(): array
{
    return FormCsv::columns(
        ['fields' => [['name' => 'nome'], ['name' => 'email'], ['name' => 'telefone']]],
        fn(string $k): string => $k
    );
}

test('requested: lista do painel, string do CLI, ou nada', function () {
    check_same(['nome', 'email', '@meta'], FormCsv::requested(['nome', 'email', '@meta']));
    check_same(['nome', 'email', '@meta'], FormCsv::requested('nome, email,@meta'));
    check_same([], FormCsv::requested(null));
    check_same([], FormCsv::requested(''));
    check_same([], FormCsv::requested(true), 'flag sem valor no CLI chega como true');
});

test('requested: descarta o que não é string e as entradas vazias', function () {
    // `cols[x][]=…` montado à mão não pode virar "Array" numa comparação.
    check_same(['nome'], FormCsv::requested(['nome', ['aninhado'], 7, '', '  ']));
});

test('select: nada pedido = o padrão, só os campos (o link nu exporta os campos)', function () {
    $all = fcsv_select_available();
    check_same(['nome', 'email', 'telefone'], array_column(FormCsv::select([], $all), 'key'));
});

test('defaults: só os campos do formulário; sem campo nenhum, todas as colunas', function () {
    // Metadados e rastreamento são opcionais: o painel os traz desmarcados, e
    // o CLI e o link nu os deixam de fora.
    $all = fcsv_select_available();
    check_same(['nome', 'email', 'telefone'], array_column(FormCsv::defaults($all), 'key'));
    // Um formulário sem campo nenhum ainda exporta alguma coisa — os
    // metadados e o rastreamento —, em vez de um arquivo sem coluna.
    $semCampos = FormCsv::columns(['fields' => []], fn(string $k): string => $k);
    check_same($semCampos, FormCsv::defaults($semCampos));
});

test('select: grupos expandem, e a ordem é sempre a de columns()', function () {
    $all = fcsv_select_available();
    check_same(
        array_merge(['nome', 'telefone'], Params::WHITELIST),
        array_column(FormCsv::select(['@params', 'telefone', 'nome'], $all), 'key'),
        'campos na ordem de declaração, grupo no lugar dele — não na ordem do pedido'
    );
    check_same(
        ['id', 'created_at', 'ip', 'user_agent', 'referer', 'email_status', 'sent_to'],
        array_column(FormCsv::select(['@meta'], $all), 'key')
    );
});

test('select: coluna pedida duas vezes, ou por nome e por grupo, sai uma vez', function () {
    $all = fcsv_select_available();
    $out = array_column(FormCsv::select(['email', 'email', 'ip', '@meta'], $all), 'key');
    check_same(['email', 'id', 'created_at', 'ip', 'user_agent', 'referer', 'email_status', 'sent_to'], $out);
});

test('select: chave desconhecida é descartada em silêncio (link salvo não quebra)', function () {
    $all = fcsv_select_available();
    check_same(['email'], array_column(FormCsv::select(['email', 'campo_que_sumiu'], $all), 'key'));
    check_same([], FormCsv::select(['campo_que_sumiu'], $all), 'nada casou: quem chama decide o erro');
});

test('select: a chave simples é da primeira coluna que a tem — campo não puxa metadado nem rastreamento', function () {
    // `hidden` é tipo de campo, e um tema pode declarar `utm_source` ou `id`
    // como campo. A chave é do campo (os campos vêm primeiro); a coluna de
    // rastreamento ou de metadado de mesmo nome só vem pelo grupo.
    $all = FormCsv::columns(
        ['fields' => [['name' => 'nome'], ['name' => 'utm_source'], ['name' => 'id']]],
        fn(string $k): string => $k
    );
    check_same(
        ['field', 'field', 'field'],
        array_column(FormCsv::select(['nome', 'utm_source', 'id'], $all), 'group'),
        'só os três campos — nem o id dos metadados, nem o utm_source do rastreamento'
    );
    $comGrupo = FormCsv::select(['utm_source', '@params'], $all);
    check_same(1 + count(Params::WHITELIST), count($comGrupo), 'o campo, e o utm_source de rastreamento pelo grupo');
    check_same('field', $comGrupo[0]['group']);
});

test('unknown: o que não casa com coluna nem com grupo', function () {
    $all = fcsv_select_available();
    check_same([], FormCsv::unknown(['nome', '@meta', '@params', 'utm_source', 'created_at'], $all));
    check_same(['emial', '@tudo'], FormCsv::unknown(['nome', 'emial', '@tudo'], $all));
});

test('validDate: formato e calendário', function () {
    check(FormCsv::validDate('2024-02-29'), 'ano bissexto');
    check(!FormCsv::validDate('2026-02-29'), '29/02 fora de ano bissexto');
    check(!FormCsv::validDate('2026-1-5'));
    check(!FormCsv::validDate(' 2026-01-05'));
    check(!FormCsv::validDate("2026-01-05\n"), 'o $ do PCRE casa antes de \n sem o modificador D');
    check(!FormCsv::validDate(null));
});
