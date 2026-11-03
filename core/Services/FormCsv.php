<?php

declare(strict_types=1);

namespace Velk\Services;

use Velk\Models\FormSubmission;
use Velk\Params;

/**
 * Os preenchimentos de um formulário em CSV — as regras num lugar só.
 *
 * O painel (`FormController::export()`) e o CLI (`bin/velk form:export`)
 * chamam isto e não sabem nada de CSV: quais colunas existem, em que ordem,
 * com que rótulo, o que vai em cada célula e como escapar. Duas cópias dessas
 * regras divergiriam no primeiro campo novo — o cabeçalho do painel e o do
 * CLI deixariam de bater, e ninguém perceberia até comparar dois arquivos.
 *
 * Tudo aqui é puro, menos `write()`, que só escreve no handle que recebeu.
 * Os rótulos traduzíveis chegam por um resolvedor em vez de `__()` direto:
 * os testes rodam sem App, e painel e CLI resolvem pelo mesmo catálogo.
 */
final class FormCsv
{
    /** Chave de grupo: um checkbox só no painel, sete colunas no arquivo. */
    public const GROUP_META = '@meta';

    /** Chave de grupo: um checkbox só no painel, uma coluna por parâmetro. */
    public const GROUP_PARAMS = '@params';

    /**
     * Os metadados, na ordem do arquivo, com a chave de i18n do rótulo.
     *
     * `id` entra aqui e não como coluna fixa: quem reimporta num CRM marca o
     * bloco; quem só quer a lista de contatos não carrega a coluna.
     *
     * As chaves de i18n passam por um resolvedor, não por `__()` literal, e
     * por isso o `bin/velk i18n:scan-admin` não as enxerga (aparecem como
     * "dead" no relatório dele). Quem garante que existem nos três idiomas é
     * o `form_export_i18n_test.php`.
     */
    public const META = [
        'id'           => 'forms.export_col_id',
        'created_at'   => 'forms.export_col_created_at',
        'ip'           => 'forms.export_col_ip',
        'user_agent'   => 'forms.export_col_user_agent',
        'referer'      => 'forms.export_col_referer',
        'email_status' => 'forms.export_col_email_status',
        'sent_to'      => 'forms.export_col_sent_to',
    ];

    /**
     * Primeiros caracteres que o Excel e o Sheets executam como fórmula — a
     * lista da OWASP para injeção em CSV.
     */
    private const FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    /** Marca de ordem de bytes do UTF-8: sem ela o Excel lê "José" como "JosÃ©". */
    private const BOM = "\xEF\xBB\xBF";

    /**
     * PURO — todas as colunas que este formulário pode exportar, na ordem do
     * arquivo: os campos do site.json na ordem de declaração, depois os
     * metadados, depois os parâmetros de rastreamento.
     *
     * É a ÚNICA definição de ordem e rótulo: o painel desenha os checkboxes a
     * partir dela, o CLI valida `--cols` contra ela, o cabeçalho sai dela.
     *
     * Os parâmetros são derivados de `Params::WHITELIST`, não copiados: um
     * click ID novo lá é uma coluna nova aqui sem ninguém lembrar. O rótulo
     * deles é a própria chave — `utm_source` é o nome que quem trabalha com
     * mídia reconhece, e traduzi-lo atrapalharia reimportar na ferramenta de
     * anúncio.
     *
     * @param array<string,mixed>     $formDef `forms.<id>` do site.json
     * @param callable(string):string $label   resolve uma chave de i18n
     * @return list<array{key:string, label:string, group:string}>
     */
    public static function columns(array $formDef, callable $label): array
    {
        $cols = [];
        foreach ((array) ($formDef['fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }
            $name = (string) ($field['name'] ?? '');
            if ($name === '') {
                continue;
            }
            // Rótulo vazio cai no nome: uma coluna sem cabeçalho não diz o que
            // carrega, e na planilha vira uma célula em branco no topo.
            $fieldLabel = is_string($field['label'] ?? null) && trim($field['label']) !== ''
                ? $field['label']
                : $name;
            $cols[] = ['key' => $name, 'label' => $fieldLabel, 'group' => 'field'];
        }

        foreach (self::META as $key => $i18nKey) {
            $cols[] = ['key' => $key, 'label' => $label($i18nKey), 'group' => 'meta'];
        }

        foreach (Params::WHITELIST as $key) {
            $cols[] = ['key' => $key, 'label' => $key, 'group' => 'params'];
        }

        return $cols;
    }

    /**
     * PURO — o que veio do painel ou do CLI, normalizado numa lista de chaves.
     *
     * Aceita a lista do painel (`cols[]=…`), a string do CLI (`a,b,@meta`) ou
     * nada. Descarta o que não é string — `cols[x][]=…` montado à mão não
     * pode virar "Array" numa comparação — e as entradas vazias.
     *
     * @return list<string>
     */
    public static function requested(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (!is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item !== '') {
                $out[] = $item;
            }
        }
        return $out;
    }

    /**
     * PURO — o padrão da exportação: só os campos do formulário. É o que o
     * painel traz marcado, o que o CLI exporta sem `--cols` e o que um link
     * sem colunas leva. Metadados e rastreamento são opcionais: marca-se o
     * grupo no painel, ou pede-se `@meta`/`@params`. Um formulário sem campo
     * nenhum exporta tudo o que tem, em vez de um arquivo sem coluna.
     *
     * @param list<array{key:string, label:string, group:string}> $available
     * @return list<array{key:string, label:string, group:string}>
     */
    public static function defaults(array $available): array
    {
        $fields = array_values(array_filter($available, fn(array $c): bool => $c['group'] === 'field'));
        return $fields !== [] ? $fields : $available;
    }

    /**
     * PURO — as colunas pedidas, NA ORDEM DE `$available`, nunca na ordem em
     * que chegaram. Ordem estável é o que faz um link salvo produzir o mesmo
     * arquivo depois.
     *
     * `[]` = o padrão, `defaults()`: um link nu exporta os campos do
     * formulário. `@meta` e `@params` expandem para
     * o grupo; qualquer outra chave casa com a PRIMEIRA coluna de mesma `key`
     * na ordem de `columns()` — os campos vêm antes. Um campo chamado
     * `utm_source` ou `id` (`hidden` é tipo de campo) leva a chave, e a coluna
     * de rastreamento ou de metadado de mesmo nome só vem pelo grupo: sem
     * isso, desmarcar "Rastreamento" ainda exportaria a coluna, e o
     * cabeçalho sairia com `utm_source` repetido. Cada coluna aparece no
     * máximo uma vez, porque o laço percorre `$available` e não o pedido.
     * Chave desconhecida é descartada em silêncio — o link salvo não pode
     * quebrar porque alguém renomeou um campo no site.json. Quem precisa
     * acusá-la (o CLI) pergunta a `unknown()`.
     *
     * @param list<string> $requested
     * @param list<array{key:string, label:string, group:string}> $available
     * @return list<array{key:string, label:string, group:string}>
     */
    public static function select(array $requested, array $available): array
    {
        if ($requested === []) {
            return self::defaults($available);
        }
        $want = array_flip($requested);
        $owned = [];
        $out = [];
        foreach ($available as $col) {
            $byGroup = ($col['group'] === 'meta' && isset($want[self::GROUP_META]))
                || ($col['group'] === 'params' && isset($want[self::GROUP_PARAMS]));
            $byKey = isset($want[$col['key']]) && !isset($owned[$col['key']]);
            $owned[$col['key']] = true;
            if ($byGroup || $byKey) {
                $out[] = $col;
            }
        }
        return $out;
    }

    /**
     * PURO — as chaves pedidas que não casam com coluna nenhuma.
     *
     * Existe para o CLI falhar alto: um `--cols=emial` digitado agora tem de
     * dar erro, e não gerar um arquivo sem a coluna.
     *
     * @param list<string> $requested
     * @param list<array{key:string, label:string, group:string}> $available
     * @return list<string>
     */
    public static function unknown(array $requested, array $available): array
    {
        $known = [self::GROUP_META => true, self::GROUP_PARAMS => true];
        foreach ($available as $col) {
            $known[$col['key']] = true;
        }
        return array_values(array_filter($requested, fn(string $r): bool => !isset($known[$r])));
    }

    /**
     * PURO — `AAAA-MM-DD` de uma data que existe no calendário.
     *
     * O CLI recusa o que não passa; o painel ignora (o `<input type="date">`
     * de um navegador de verdade nunca manda outra coisa, então só chega aqui
     * uma URL montada à mão).
     *
     * O `D` na regex não é enfeite: sem ele o `$` do PCRE casa antes de uma
     * quebra de linha final, e "2026-01-05\n" passaria.
     */
    public static function validDate(mixed $value): bool
    {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m)) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * PURO — o nome do arquivo baixado: `<form-id>-<data>.csv`.
     *
     * Aspas, CR e LF saem do id antes de ele entrar no header
     * `Content-Disposition`. O id já foi conferido contra o site.json; isto é
     * a segunda barreira contra injeção de header, a mesma do `downloadFile()`.
     */
    public static function filename(string $formId, string $date): string
    {
        return str_replace(['"', "\r", "\n"], '', $formId) . '-' . $date . '.csv';
    }

    /**
     * PURO — o valor de uma célula, já escapado.
     *
     * Um valor gravado só tem duas formas: string, ou o array de metadados de
     * upload (marcador `_file`). Checkbox grava '1' ou '', select grava a
     * chave da opção, e o que chega do POST como array é gravado vazio — tudo
     * string. (A submissão que caiu no spam e foi recuperada com "não é
     * spam" entra na exportação com o valor múltiplo já achatado em string
     * pelo `spamValues()` — "a, b" — e o arquivo só com o nome enviado:
     * também string.)
     * O arquivo vira o nome dele, não a URL de download: a URL só funciona
     * para quem tem sessão no painel e vazaria caminho interno numa planilha
     * que circula por e-mail.
     *
     * Metadados e parâmetros passam pelo `escape()` também: `user_agent` e
     * `referer` vêm do cliente HTTP, e um parâmetro é texto livre de até 255
     * caracteres.
     *
     * @param array{key:string, label:string, group:string} $column
     */
    public static function cell(FormSubmission $s, array $column): string
    {
        $key = $column['key'];
        $value = match ($column['group']) {
            'field'  => self::fieldValue($s->data[$key] ?? null),
            'meta'   => self::metaValue($s, $key),
            'params' => (string) ($s->params[$key] ?? ''),
            default  => '',
        };
        return self::escape($value);
    }

    /**
     * PURO — o anti-fórmula: célula que começa com `=`, `+`, `-`, `@`, TAB ou
     * CR ganha um apóstrofo à frente.
     *
     * O conteúdo vem de visitante anônimo e o destino é o Excel ou o Sheets do
     * cliente, que executam esses prefixos: `=HYPERLINK(...)` no campo "nome"
     * vira exfiltração da planilha inteira. O `e()` do Velk não cobre isto —
     * é escape de HTML, contexto diferente.
     *
     * Efeito colateral aceito: um telefone digitado como `-11 99999` chega ao
     * Excel como texto. Perder a formatação de uma célula é melhor que
     * executar o conteúdo dela. Aspas, quebras de linha e o separador não são
     * tratados aqui — o `fputcsv()` cita o campo quando precisa.
     */
    public static function escape(string $value): string
    {
        if ($value !== '' && in_array($value[0], self::FORMULA_PREFIXES, true)) {
            return "'" . $value;
        }
        return $value;
    }

    /**
     * Emite BOM, cabeçalho e uma linha por submissão no handle recebido.
     *
     * Chamada UMA vez por arquivo, inclusive na exportação em blocos: quem
     * lê em blocos passa um gerador que os percorre por dentro
     * (`FormSubmission::exportRows()`), e não chama `write()` por bloco. É o
     * que mantém BOM e cabeçalho saindo uma vez só, sem flag de "já emiti"
     * guardada aqui — estado escondido que a próxima pessoa não esperaria.
     *
     * Não abre nem fecha o handle: o painel passa `php://output`, o CLI passa
     * `STDOUT`, o teste passa `php://memory` para ler de volta.
     *
     * O cabeçalho NÃO passa pelo `escape()`: os rótulos vêm do site.json e do
     * catálogo do admin, escritos por quem monta o site, e um rótulo legítimo
     * como "+55 Telefone" ganharia um apóstrofo visível.
     *
     * Uma escrita recusada — disco cheio, a saída fechada no meio — lança,
     * em vez de seguir: o `fputcsv()` devolve false e continua, e o arquivo
     * sairia truncado com o CLI terminando em 0. O `Forms::export()` entrega
     * a exceção como "Export failed", com saída 1.
     *
     * @param resource $out
     * @param iterable<FormSubmission> $rows
     * @param list<array{key:string, label:string, group:string}> $columns
     * @throws \RuntimeException quando uma escrita é recusada
     */
    public static function write($out, iterable $rows, array $columns, string $sep, bool $bom): void
    {
        if ($bom) {
            self::ensureWritten(fwrite($out, self::BOM));
        }
        // `$escape = ''` explícito: é o CSV da RFC 4180 (aspas dobradas, sem
        // barra invertida), e omitir o argumento é depreciado desde o PHP 8.4.
        self::ensureWritten(fputcsv($out, array_column($columns, 'label'), $sep, '"', ''));
        foreach ($rows as $s) {
            $line = [];
            foreach ($columns as $col) {
                $line[] = self::cell($s, $col);
            }
            self::ensureWritten(fputcsv($out, $line, $sep, '"', ''));
        }
    }

    /** O retorno de uma escrita do `write()`: false é recusa, e vira exceção. */
    private static function ensureWritten(int|false $written): void
    {
        if ($written === false) {
            throw new \RuntimeException('could not write the CSV (disk full, or the output was closed)');
        }
    }

    /** A célula de um campo do formulário — ver `cell()`. */
    private static function fieldValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value) && !empty($value['_file'])) {
            return (string) ($value['name'] ?? '');
        }
        return '';
    }

    /**
     * A célula de um metadado. `created_at` sai como o MySQL a devolve
     * (`AAAA-MM-DD HH:MM:SS`): não ambíguo, ordena certo em qualquer
     * ferramenta, e o Excel reconhece como data e hora em qualquer idioma.
     */
    private static function metaValue(FormSubmission $s, string $key): string
    {
        return match ($key) {
            'id'           => (string) $s->id,
            'created_at'   => $s->createdAt,
            'ip'           => (string) $s->ip,
            'user_agent'   => (string) $s->userAgent,
            'referer'      => (string) $s->referer,
            'email_status' => (string) $s->emailStatus,
            'sent_to'      => (string) $s->sentTo,
            default        => '',
        };
    }
}
