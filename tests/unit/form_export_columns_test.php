<?php

declare(strict_types=1);

use Velk\Params;
use Velk\Services\FormCsv;

/**
 * `FormCsv::columns()` — a lista canônica de colunas da exportação em CSV.
 *
 * É a única definição de ordem e rótulo: o painel desenha os checkboxes a
 * partir dela, o CLI valida `--cols` contra ela, o cabeçalho do arquivo sai
 * dela. Se a ordem mudar, um link de exportação salvo passa a produzir outro
 * arquivo.
 */

function fcsv_columns_form(): array
{
    return [
        'fields' => [
            ['name' => 'nome', 'label' => 'Nome', 'type' => 'text'],
            ['name' => 'email', 'label' => 'E-mail', 'type' => 'email'],
            ['name' => 'curriculo', 'type' => 'file'],                 // sem label
            ['name' => 'obs', 'label' => '   ', 'type' => 'textarea'], // label em branco
            ['label' => 'Sem nome', 'type' => 'text'],                 // sem name: não é coluna
            'lixo',                                                     // nem é array
        ],
    ];
}

/** Resolvedor que deixa visível qual chave de i18n foi pedida. */
function fcsv_columns_label(string $key): string
{
    return '[' . $key . ']';
}

/** @return list<array{key:string, label:string, group:string}> */
function fcsv_columns_group(array $cols, string $group): array
{
    return array_values(array_filter($cols, fn(array $c): bool => $c['group'] === $group));
}

test('columns: campos na ordem de declaração, depois metadados, depois rastreamento', function () {
    $cols = FormCsv::columns(fcsv_columns_form(), 'fcsv_columns_label');

    check_same(['nome', 'email', 'curriculo', 'obs'], array_column(fcsv_columns_group($cols, 'field'), 'key'));

    $groups = array_column($cols, 'group');
    check_same(4, array_search('meta', $groups, true), 'o primeiro metadado vem logo depois do último campo');
    check_same(4 + 7, array_search('params', $groups, true), 'o rastreamento vem logo depois dos sete metadados');
    check_same(4 + 7 + count(Params::WHITELIST), count($cols));
});

test('columns: rótulo do site.json; sem rótulo, ou em branco, o nome do campo', function () {
    $fields = fcsv_columns_group(FormCsv::columns(fcsv_columns_form(), 'fcsv_columns_label'), 'field');
    check_same(['Nome', 'E-mail', 'curriculo', 'obs'], array_column($fields, 'label'));
});

test('columns: os sete metadados, na ordem, com o rótulo vindo do resolvedor', function () {
    $meta = fcsv_columns_group(FormCsv::columns([], 'fcsv_columns_label'), 'meta');
    check_same(
        ['id', 'created_at', 'ip', 'user_agent', 'referer', 'email_status', 'sent_to'],
        array_column($meta, 'key')
    );
    check_same('[forms.export_col_id]', $meta[0]['label']);
    check_same('[forms.export_col_sent_to]', $meta[6]['label']);
});

test('columns: o rastreamento É a whitelist do Params — derivado, não copiado', function () {
    // Um click ID novo em Params::WHITELIST tem de virar coluna aqui sem
    // ninguém lembrar. Comparar contra a constante, e não contra uma lista
    // escrita neste teste, é o que prende isso.
    $params = fcsv_columns_group(FormCsv::columns([], 'fcsv_columns_label'), 'params');
    check_same(Params::WHITELIST, array_column($params, 'key'));
    check_same(Params::WHITELIST, array_column($params, 'label'), 'o rótulo é a própria chave');
});

test('columns: formulário sem campos ainda exporta metadados e rastreamento', function () {
    $semCampos = 7 + count(Params::WHITELIST);
    check_same($semCampos, count(FormCsv::columns([], 'fcsv_columns_label')));
    check_same($semCampos, count(FormCsv::columns(['fields' => 'nao-e-lista'], 'fcsv_columns_label')));
});
