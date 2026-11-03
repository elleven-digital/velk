<?php

declare(strict_types=1);

use Velk\App;
use Velk\Commands\Forms;
use Velk\Services\FormCsv;

/**
 * `Forms::options()` e `Forms::export()` — as flags do `bin/velk form:export`,
 * os erros que as recusam, e o contrato de saída: o stdout é só o arquivo, e
 * todo erro vai para a stderr com saída 1.
 *
 * No CLI o erro é alto, ao contrário do painel: o que está na linha de
 * comando foi digitado agora, e um arquivo sem a coluna pedida seria um erro
 * silencioso. `export()` roda contra SQLite em memória, com a saída e o erro
 * em `php://memory`.
 */

// __raw() mora em core/helpers.php, que o autoloader de classes não carrega.
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__) . '/fixtures/form_export_boot.php';

function fcsv_cli_available(): array
{
    return FormCsv::columns(['fields' => [['name' => 'nome'], ['name' => 'email']]], fn(string $k): string => $k);
}

/** Um formulário `contato` com um lead real (1) e um spam (2). */
function fcsv_cli_app(): App
{
    $app = fexb_boot(
        ['fields' => [['name' => 'email', 'label' => 'E-mail']]],
        [[1, null, null, '{"email":"ana@x.com"}'], [2, 'honeypot', null, '{"email":"bot@x.com"}']]
    );
    $app->adminI18n = new \Velk\Admin\I18n(dirname(__DIR__, 2) . '/core/Admin/lang', 'en', 'en');
    return $app;
}

/**
 * Roda `Forms::export()` com a saída e o erro em memória.
 *
 * @return array{0:int, 1:string, 2:string} [código de saída, stdout, stderr]
 */
function fcsv_cli_run(App $app, string $formId, array $flags): array
{
    $out = fopen('php://memory', 'w+');
    $err = fopen('php://memory', 'w+');
    $code = Forms::export($app, $formId, $flags, $out, $err);
    rewind($out);
    rewind($err);
    $result = [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    fclose($out);
    fclose($err);
    return $result;
}

test('cli: sem flags, os padrões do painel — só os campos, todas as datas, ponto e vírgula, BOM', function () {
    $o = Forms::options([], fcsv_cli_available());
    check_same(['nome', 'email'], array_column($o['columns'], 'key'), 'metadados e rastreamento só com --cols');
    check_same(['date_from' => '', 'date_to' => ''], $o['filters']);
    check_same(';', $o['sep']);
    check_same(true, $o['bom']);
});

test('cli: --cols, --from, --to, --sep e --no-bom', function () {
    $o = Forms::options(
        ['cols' => 'email,@meta', 'from' => '2026-01-01', 'to' => '2026-01-31', 'sep' => ',', 'no-bom' => true],
        fcsv_cli_available()
    );
    check_same(
        ['email', 'id', 'created_at', 'ip', 'user_agent', 'referer', 'email_status', 'sent_to'],
        array_column($o['columns'], 'key')
    );
    check_same(['date_from' => '2026-01-01', 'date_to' => '2026-01-31'], $o['filters']);
    check_same(',', $o['sep']);
    check_same(false, $o['bom']);
});

test('cli: coluna desconhecida é erro, e o erro diz qual', function () {
    check_same(['error' => 'Unknown column: emial'], Forms::options(['cols' => 'nome,emial'], fcsv_cli_available()));
});

test('cli: --cols vazio, ou sem valor, é erro', function () {
    // O bin/velk entrega `--cols` sem `=` como true.
    check_same(['error' => '--cols needs at least one column'], Forms::options(['cols' => ''], fcsv_cli_available()));
    check_same(['error' => '--cols needs at least one column'], Forms::options(['cols' => true], fcsv_cli_available()));
});

test('cli: separador fora de ; e , é erro', function () {
    check_same(['error' => "--sep must be ';' or ','"], Forms::options(['sep' => '|'], fcsv_cli_available()));
    check_same(['error' => "--sep must be ';' or ','"], Forms::options(['sep' => true], fcsv_cli_available()));
});

test('cli: data fora de AAAA-MM-DD, ou que não existe, é erro', function () {
    check_same(['error' => '--from must be YYYY-MM-DD'], Forms::options(['from' => '01/02/2026'], fcsv_cli_available()));
    check_same(['error' => '--to must be YYYY-MM-DD'], Forms::options(['to' => '2026-02-30'], fcsv_cli_available()));
    check_same(['error' => '--from must be YYYY-MM-DD'], Forms::options(['from' => true], fcsv_cli_available()));
});

test('cli: export() escreve só o arquivo no stdout, e cada erro na stderr com saída 1', function () {
    [$code, $out, $err] = fcsv_cli_run(fcsv_cli_app(), 'contato', ['cols' => 'email', 'no-bom' => true]);
    check_same([0, "E-mail\nana@x.com\n", ''], [$code, $out, $err], 'só o lead real, e nada na stderr');
    check_same(
        [0, "E-mail\n", ''],
        fcsv_cli_run(fcsv_cli_app(), 'contato', ['cols' => 'email', 'no-bom' => true, 'from' => '2030-01-01']),
        'período sem leads: só o cabeçalho, saída 0'
    );

    check_same(
        [1, '', "Form 'nao-existe' not found in theme/site.json\n"],
        fcsv_cli_run(fcsv_cli_app(), 'nao-existe', [])
    );
    check_same([1, '', "Unknown column: emial\n"], fcsv_cli_run(fcsv_cli_app(), 'contato', ['cols' => 'emial']));
});

test('cli: falha de banco é erro na stderr com saída 1, antes de qualquer byte no stdout', function () {
    // Sem o try/catch a exceção sairia do bin/velk com 255, e com o debug
    // ligado o texto dela cairia no stdout — dentro do arquivo.
    $app = fcsv_cli_app();
    $app->db->pdo()->exec('DROP TABLE form_submissions');
    [$code, $out, $err] = fcsv_cli_run($app, 'contato', []);
    check_same(1, $code);
    check_same('', $out, 'nem o BOM nem o cabeçalho: a primeira consulta roda antes deles');
    check(str_starts_with($err, 'Export failed: '), $err);
});

test('cli: escrita recusada — disco cheio — é erro na stderr com saída 1, e não saída 0 com o arquivo pela metade', function () {
    // O `> leads.csv` num disco cheio: o write() recusa com exceção, e o
    // try/catch a entrega como os outros erros.
    $out = fopen('php://memory', 'r'); // só leitura: recusa toda escrita, sem aviso
    $err = fopen('php://memory', 'w+');
    $code = Forms::export(fcsv_cli_app(), 'contato', [], $out, $err);
    rewind($err);
    check_same(
        [1, "Export failed: could not write the CSV (disk full, or the output was closed)\n"],
        [$code, stream_get_contents($err)]
    );
    fclose($out);
    fclose($err);
});
