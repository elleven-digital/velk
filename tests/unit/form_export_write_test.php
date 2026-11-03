<?php

declare(strict_types=1);

use Velk\Models\FormSubmission;
use Velk\Services\FormCsv;

/**
 * `FormCsv::write()` e `filename()` — o arquivo emitido, lido de volta de um
 * `php://memory`.
 */

function fcsv_write_sub(int $id, string $nome): FormSubmission
{
    return FormSubmission::fromRow([
        'id'         => $id,
        'form_id'    => 'contato',
        'data'       => json_encode(['nome' => $nome, 'email' => "{$id}@x.com"]),
        'created_at' => '2026-09-23 10:00:00',
    ]);
}

/** @param iterable<FormSubmission> $rows */
function fcsv_write_run(iterable $rows, string $sep, bool $bom): string
{
    $columns = [
        ['key' => 'nome', 'label' => 'Nome', 'group' => 'field'],
        ['key' => 'email', 'label' => 'E-mail', 'group' => 'field'],
    ];
    $h = fopen('php://memory', 'w+');
    FormCsv::write($h, $rows, $columns, $sep, $bom);
    rewind($h);
    $out = (string) stream_get_contents($h);
    fclose($h);
    return $out;
}

/**
 * Uma saída que recusa uma escrita — a de número `$recusa`, contando de 1 —
 * e aceita as outras. Recusa como o PHP recusa: a escrita devolve false, sem
 * aviso. Uma só, e não todas dali em diante: com o disco cheio para sempre,
 * a escrita seguinte lançaria no lugar da que ficou sem conferência, e o
 * teste não veria a falta.
 */
final class FcsvWriteRecusaUma
{
    public static int $recusa = 0;
    private static int $feitas = 0;
    /** @var resource|null o PHP preenche em todo wrapper de stream */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        self::$feitas = 0;
        return true;
    }

    public function stream_write(string $data): int|false
    {
        return ++self::$feitas === self::$recusa ? false : strlen($data);
    }
}
stream_wrapper_register('fcsvrecusa', FcsvWriteRecusaUma::class);

test('write: BOM, cabeçalho com os rótulos, uma linha por submissão', function () {
    $out = fcsv_write_run([fcsv_write_sub(2, 'Bia'), fcsv_write_sub(1, 'Ana')], ';', true);
    check_same("\xEF\xBB\xBFNome;E-mail\nBia;2@x.com\nAna;1@x.com\n", $out);
});

test('write: vírgula e sem BOM quando pedido (--sep=, --no-bom)', function () {
    check_same("Nome,E-mail\nAna,1@x.com\n", fcsv_write_run([fcsv_write_sub(1, 'Ana')], ',', false));
});

test('write: valor com o separador, aspas ou quebra de linha sai citado', function () {
    // O escape de CSV é do fputcsv (aspas dobradas, RFC 4180) — o FormCsv só
    // cuida do de fórmula.
    $out = fcsv_write_run([fcsv_write_sub(1, "Ana; \"a Bia\"\nda Silva")], ';', false);
    check_same("Nome;E-mail\n\"Ana; \"\"a Bia\"\"\nda Silva\";1@x.com\n", $out);
});

test('write: um gerador, como o de exportRows(), produz UM BOM e UM cabeçalho', function () {
    // É assim que painel e CLI entregam as linhas: o gerador percorre os
    // blocos do banco por dentro, e write() é chamada uma vez só.
    $gerador = (function () {
        yield fcsv_write_sub(3, 'Caio');
        yield fcsv_write_sub(2, 'Bia');
        yield fcsv_write_sub(1, 'Ana');
    })();
    $out = fcsv_write_run($gerador, ';', true);
    check_same(1, substr_count($out, "\xEF\xBB\xBF"));
    check_same(1, substr_count($out, 'Nome;E-mail'));
    check_same(4, substr_count($out, "\n"), 'cabeçalho + três linhas');
});

test('write: zero submissões ainda produz o cabeçalho', function () {
    check_same("\xEF\xBB\xBFNome;E-mail\n", fcsv_write_run([], ';', true));
});

test('write: o cabeçalho não passa pelo escape de fórmula, e barra invertida não escapa aspas', function () {
    // O rótulo vem do site.json, escrito por quem monta o site: "+55 Telefone"
    // é legítimo e não pode ganhar apóstrofo. E o CSV é o da RFC 4180 — aspas
    // dobradas, nunca \" —, que é como o Excel lê: com o $escape padrão do
    // fputcsv, o valor a\"b sairia sem a aspa dobrada, e a planilha leria
    // outra coisa.
    $h = fopen('php://memory', 'w+');
    FormCsv::write($h, [FormSubmission::fromRow([
        'id'         => 1,
        'form_id'    => 'contato',
        'data'       => json_encode(['tel' => '=1+1', 'obs' => 'a\\"b']),
        'created_at' => '2026-09-23 10:00:00',
    ])], [
        ['key' => 'tel', 'label' => '+55 Telefone', 'group' => 'field'],
        ['key' => 'obs', 'label' => 'Obs', 'group' => 'field'],
    ], ';', false);
    rewind($h);
    // O rótulo sai entre aspas só porque tem espaço (é o fputcsv), sem
    // apóstrofo. O valor tem uma barra invertida e uma aspa: a aspa é
    // dobrada, a barra passa como está.
    check_same("\"+55 Telefone\";Obs\n'=1+1;\"a\\\"\"b\"\n", stream_get_contents($h));
    fclose($h);
});

test('write: escrita recusada — disco cheio — é exceção, não um arquivo truncado em silêncio', function () {
    // O fputcsv que não consegue escrever devolve false e segue: sem a
    // exceção, o CLI terminaria com saída 0 e um leads.csv pela metade. Cada
    // escrita é conferida — a do BOM, a do cabeçalho e a de cada linha.
    foreach (['do BOM' => 1, 'do cabeçalho' => 2, 'de uma linha' => 3] as $qual => $n) {
        FcsvWriteRecusaUma::$recusa = $n;
        $h = fopen('fcsvrecusa://saida', 'w');
        $erro = null;
        try {
            FormCsv::write($h, [fcsv_write_sub(1, 'Ana')], [['key' => 'nome', 'label' => 'Nome', 'group' => 'field']], ';', true);
        } catch (RuntimeException $e) {
            $erro = $e->getMessage();
        }
        fclose($h);
        check_same('could not write the CSV (disk full, or the output was closed)', $erro, "a recusa {$qual}");
    }
});

test('filename: <form-id>-<data>.csv, sem aspas nem quebra de linha', function () {
    check_same('contato-2026-09-23.csv', FormCsv::filename('contato', '2026-09-23'));
    // Segunda barreira contra injeção de header: o id já foi conferido
    // contra o site.json antes de chegar aqui.
    check_same('contatoX-Injected: 1-2026-09-23.csv', FormCsv::filename("contato\"\r\nX-Injected: 1", '2026-09-23'));
});
