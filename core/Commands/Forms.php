<?php

declare(strict_types=1);

namespace Velk\Commands;

use Velk\App;
use Velk\Models\FormSubmission;
use Velk\Services\FormCsv;

/**
 * `bin/velk form:export <form-id>` — os preenchimentos de um formulário em
 * CSV, no stdout.
 *
 * Os mesmos padrões do botão do painel — ponto e vírgula, BOM, todas as
 * colunas, todas as datas —, e as mesmas regras, que moram em
 * `Velk\Services\FormCsv`: um caminho não produz um arquivo diferente do
 * outro. Só leads reais (aprovados e completos), como no painel.
 *
 * O stdout é o arquivo e nada mais; erro vai para a stderr. É o stdout que a
 * skill do Velk recebe ao rodar este comando no servidor por SSH, então
 * `velk.sh --project . -- form:export contato > leads.csv` baixa o CSV de
 * produção numa linha.
 */
final class Forms
{
    /**
     * @param array<string, string|bool> $flags as flags já separadas pelo bin/velk
     * @param resource|null $out o arquivo — padrão STDOUT
     * @param resource|null $err as mensagens de erro — padrão STDERR
     * @return int código de saída
     */
    public static function export(App $app, string $formId, array $flags, $out = null, $err = null): int
    {
        $out ??= STDOUT;
        $err ??= STDERR;

        $form = $app->config->site('forms.' . $formId);
        if (!is_array($form)) {
            fwrite($err, "Form '{$formId}' not found in theme/site.json\n");
            return 1;
        }

        $available = FormCsv::columns($form, fn(string $key): string => __raw($key));
        $opts = self::options($flags, $available);
        if (isset($opts['error'])) {
            fwrite($err, $opts['error'] . "\n");
            return 1;
        }

        // Falha depois do boot — tabela ausente, conexão perdida no meio, uma
        // escrita recusada (disco cheio) — é erro na stderr com saída 1, como
        // os outros. Sem isto a exceção sairia do bin/velk com 255, e com o
        // debug ligado o texto dela cairia no stdout, dentro do arquivo. A
        // primeira consulta roda antes de qualquer byte: um erro ali — o
        // exportRows() a faz na chamada — não deixa um arquivo pela metade.
        // Banco inacessível de saída nem chega aqui: o bin/velk conecta no
        // boot e para antes, com "Setup error" e saída 2.
        try {
            $rows = FormSubmission::exportRows($formId, $opts['filters']);
            FormCsv::write($out, $rows, $opts['columns'], $opts['sep'], $opts['bom']);
        } catch (\Throwable $e) {
            fwrite($err, 'Export failed: ' . $e->getMessage() . "\n");
            return 1;
        }
        return 0;
    }

    /**
     * PURO — as flags do comando resolvidas em opções de exportação, ou o erro
     * que as recusa.
     *
     * Aqui o erro é alto, ao contrário do painel: o que está na linha de
     * comando foi digitado agora, e um `--cols=emial` que exportasse o arquivo
     * sem a coluna seria um erro silencioso. Uma flag passada sem valor
     * (`--cols`) chega do bin/velk como `true`, e conta como vazia.
     *
     * @param array<string, string|bool> $flags
     * @param list<array{key:string, label:string, group:string}> $available
     * @return array{error: string}|array{columns: list<array>, filters: array<string,string>, sep: string, bom: bool}
     */
    public static function options(array $flags, array $available): array
    {
        $columns = FormCsv::defaults($available);
        if (array_key_exists('cols', $flags)) {
            $requested = FormCsv::requested(is_string($flags['cols']) ? $flags['cols'] : '');
            if ($requested === []) {
                return ['error' => '--cols needs at least one column'];
            }
            $unknown = FormCsv::unknown($requested, $available);
            if ($unknown !== []) {
                return ['error' => 'Unknown column: ' . implode(', ', $unknown)];
            }
            $columns = FormCsv::select($requested, $available);
        }

        $filters = ['date_from' => '', 'date_to' => ''];
        foreach (['from' => 'date_from', 'to' => 'date_to'] as $flag => $filter) {
            if (!array_key_exists($flag, $flags)) {
                continue;
            }
            if (!FormCsv::validDate($flags[$flag])) {
                return ['error' => "--{$flag} must be YYYY-MM-DD"];
            }
            $filters[$filter] = (string) $flags[$flag];
        }

        $sep = ';';
        if (array_key_exists('sep', $flags)) {
            if ($flags['sep'] !== ';' && $flags['sep'] !== ',') {
                return ['error' => "--sep must be ';' or ','"];
            }
            $sep = $flags['sep'];
        }

        return [
            'columns' => $columns,
            'filters' => $filters,
            'sep'     => $sep,
            'bom'     => empty($flags['no-bom']),
        ];
    }
}
