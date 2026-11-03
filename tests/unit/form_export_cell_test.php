<?php

declare(strict_types=1);

use Velk\Models\FormSubmission;
use Velk\Services\FormCsv;

/**
 * `FormCsv::cell()` — o valor de uma célula da exportação em CSV.
 *
 * As submissões saem de `FormSubmission::fromRow()`, que não consulta o banco.
 */

function fcsv_cell_sub(array $data = [], array $params = [], array $extra = []): FormSubmission
{
    return FormSubmission::fromRow(array_merge([
        'id'         => 42,
        'form_id'    => 'contato',
        'data'       => json_encode($data),
        'params'     => $params === [] ? null : json_encode($params),
        'created_at' => '2026-09-23 14:30:00',
    ], $extra));
}

function fcsv_cell_col(string $group, string $key): array
{
    return ['key' => $key, 'label' => $key, 'group' => $group];
}

test('cell: campo de texto sai como foi gravado', function () {
    check_same('Gustavo', FormCsv::cell(fcsv_cell_sub(['nome' => 'Gustavo']), fcsv_cell_col('field', 'nome')));
    // Checkbox grava '1' ou '', select grava a chave da opção — string sempre.
    check_same('1', FormCsv::cell(fcsv_cell_sub(['consent' => '1']), fcsv_cell_col('field', 'consent')));
    check_same('<4M', FormCsv::cell(fcsv_cell_sub(['revenue' => '<4M']), fcsv_cell_col('field', 'revenue')));
});

test('cell: campo de arquivo vira o nome do arquivo, não a URL', function () {
    $file = ['_file' => true, 'path' => 'form-uploads/ab/cd.pdf', 'name' => 'Currículo.pdf', 'size' => 1024, 'mime' => 'application/pdf'];
    check_same('Currículo.pdf', FormCsv::cell(fcsv_cell_sub(['cv' => $file]), fcsv_cell_col('field', 'cv')));
});

test('cell: campo ausente, ou de forma inesperada, vira vazio', function () {
    $s = fcsv_cell_sub(['nome' => 'Gustavo', 'estranho' => ['sem' => 'marcador']]);
    check_same('', FormCsv::cell($s, fcsv_cell_col('field', 'telefone')), 'campo adicionado depois ao site.json');
    check_same('', FormCsv::cell($s, fcsv_cell_col('field', 'estranho')), 'array sem _file');
});

test('cell: metadados; nulo vira vazio', function () {
    $s = fcsv_cell_sub([], [], ['ip' => '203.0.113.7', 'email_status' => 'sent', 'sent_to' => 'a@x.com, b@x.com']);
    check_same('42', FormCsv::cell($s, fcsv_cell_col('meta', 'id')));
    check_same('2026-09-23 14:30:00', FormCsv::cell($s, fcsv_cell_col('meta', 'created_at')));
    check_same('203.0.113.7', FormCsv::cell($s, fcsv_cell_col('meta', 'ip')));
    check_same('sent', FormCsv::cell($s, fcsv_cell_col('meta', 'email_status')));
    check_same('a@x.com, b@x.com', FormCsv::cell($s, fcsv_cell_col('meta', 'sent_to')));
    check_same('', FormCsv::cell($s, fcsv_cell_col('meta', 'referer')));
});

test('cell: parâmetro de rastreamento; ausente vira vazio', function () {
    $s = fcsv_cell_sub([], ['utm_source' => 'google', 'gclid' => 'abc123']);
    check_same('google', FormCsv::cell($s, fcsv_cell_col('params', 'utm_source')));
    check_same('abc123', FormCsv::cell($s, fcsv_cell_col('params', 'gclid')));
    check_same('', FormCsv::cell($s, fcsv_cell_col('params', 'fbclid')));
});

test('cell: TODA célula passa pelo escape — campo, metadado e parâmetro', function () {
    // `user_agent` e `referer` vêm do cliente HTTP; parâmetro é texto livre.
    $s = fcsv_cell_sub(['nome' => '=1+1'], ['utm_campaign' => '@x'], ['user_agent' => '-cmd', 'referer' => '+x']);
    check_same("'=1+1", FormCsv::cell($s, fcsv_cell_col('field', 'nome')));
    check_same("'-cmd", FormCsv::cell($s, fcsv_cell_col('meta', 'user_agent')));
    check_same("'+x", FormCsv::cell($s, fcsv_cell_col('meta', 'referer')));
    check_same("'@x", FormCsv::cell($s, fcsv_cell_col('params', 'utm_campaign')));
});
