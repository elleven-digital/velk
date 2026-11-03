<?php

declare(strict_types=1);

namespace Velk;

/**
 * Os blocos do tema dentro de um campo richtext.
 *
 * O tema declara um bloco no `site.json` (`richtext_blocks`), com os campos
 * dele e o arquivo do partial (`file`), e quem escreve o insere no meio do
 * texto pelo editor do painel. No HTML gravado o bloco é um marcador — uma
 * tag vazia com o tipo e os dados:
 *
 *     <div data-velk-block="bignumbers" data-velk-data="{…json…}"></div>
 *
 * O tipo de cada campo não vai no marcador: vem da declaração, como em todo
 * campo do Velk. No site, o helper `richtext()` troca o marcador pela saída
 * do partial declarado, que fica sempre em `theme/partials/richtext/` — como
 * um template, ligado pelo nome do arquivo no site.json, nunca pela chave.
 *
 * Esta classe é a parte pura: achar os marcadores e conferir a declaração.
 */
final class RichtextBlocks
{
    /** A chave de um bloco: vai para o HTML gravado. */
    private const KEY = '/^[a-z][a-z0-9_]*$/D';

    /** A pasta dos partials dos blocos, dentro de `theme/partials/`. */
    public const PARTIALS = 'richtext';

    /**
     * O `file` de um bloco: só um nome de arquivo — sem pasta, sem começar
     * por ponto, sem caractere de controle.
     */
    private const FILE = '~^[^/\\\\.\x00-\x1f][^/\\\\\x00-\x1f]*$~D';

    /**
     * Uma `<div>` vazia só com atributos entre aspas — duplas, como o editor
     * grava, ou simples, como se escreve à mão. O que está entre as aspas não
     * fecha a tag: navegador antigo não escapa `<` e `>` em atributo, e o
     * dado de um campo de texto formatado dentro do bloco tem os dois.
     */
    private const TAG = '~<div((?:\s+[a-zA-Z][a-zA-Z0-9_:.-]*=(?:"[^"]*"|\'[^\']*\'))+)\s*>\s*</div>~i';

    /** A tag de abertura de uma `<div>` com atributos — o marcador, vazio ou não. */
    private const OPEN = '~<div((?:\s+[a-zA-Z][a-zA-Z0-9_:.-]*=(?:"[^"]*"|\'[^\']*\'))+)\s*>~i';
    private const ATTR = '~\s+([a-zA-Z][a-zA-Z0-9_:.-]*)=(?:"([^"]*)"|\'([^\']*)\')~';

    public static function validKey(string $key): bool
    {
        return preg_match(self::KEY, $key) === 1;
    }

    /**
     * O arquivo do partial de um bloco, relativo a `theme/partials/richtext/`:
     * o `file` declarado, com `.php` no fim se faltar (como no `partial()`).
     * Null quando falta ou não é só um nome de arquivo.
     *
     * @param array<string, mixed> $def
     */
    public static function file(array $def): ?string
    {
        $file = $def['file'] ?? null;
        if (!is_string($file) || trim($file) === '' || preg_match(self::FILE, $file) !== 1) {
            return null;
        }
        return str_ends_with($file, '.php') ? $file : $file . '.php';
    }

    /** O HTML tem algum marcador? Barato, para o texto sem bloco sair sem regex. */
    public static function has(string $html): bool
    {
        return str_contains($html, 'data-velk-block');
    }

    /**
     * Troca cada marcador pelo que `$render` devolver.
     *
     * `$render` recebe o tipo como está no HTML — quem chama confere a chave
     * antes de usá-la — e os dados, ou null quando o JSON não é um objeto.
     * Uma `<div>` vazia sem `data-velk-block` não é marcador e fica como está.
     *
     * @param callable(string, ?array<string, mixed>): string $render
     */
    public static function replace(string $html, callable $render): string
    {
        if (!self::has($html)) {
            return $html;
        }
        $out = preg_replace_callback(self::TAG, static function (array $m) use ($render): string {
            $marker = self::marker($m[1]);
            return $marker === null ? $m[0] : $render($marker['type'], $marker['data']);
        }, $html);
        // Um HTML grande demais para o PCRE sai como veio: melhor o bloco
        // faltando do que a página em branco.
        return $out ?? $html;
    }

    /**
     * Os campos de um bloco sem `blocks` nos campos de texto, em qualquer
     * profundidade: não existe bloco dentro de bloco.
     *
     * @param array<int, mixed> $fields
     * @return array<int, mixed>
     */
    public static function withoutNestedBlocks(array $fields): array
    {
        foreach ($fields as $i => $field) {
            if (!is_array($field)) {
                continue;
            }
            if (($field['type'] ?? '') === 'richtext') {
                unset($field['blocks']);
            }
            if (is_array($field['fields'] ?? null)) {
                $field['fields'] = self::withoutNestedBlocks($field['fields']);
            }
            foreach (['tabs', 'layouts'] as $container) {
                foreach ((array) ($field[$container] ?? []) as $key => $part) {
                    if (is_array($part) && is_array($part['fields'] ?? null)) {
                        $field[$container][$key]['fields'] = self::withoutNestedBlocks($part['fields']);
                    }
                }
            }
            $fields[$i] = $field;
        }
        return $fields;
    }

    /**
     * Os problemas da declaração dos blocos de um site, para o
     * `schema:validate`. Lista vazia: está certo, ou o site não usa blocos.
     *
     * @return list<string>
     */
    public static function validate(Config $config): array
    {
        $site = (array) $config->site();
        $problems = [];

        $declared = [];
        if (array_key_exists('richtext_blocks', $site)) {
            $blocks = $site['richtext_blocks'];
            if (!is_array($blocks) || ($blocks !== [] && array_is_list($blocks))) {
                $problems[] = 'site.json → richtext_blocks: must be an object keyed by block key.';
                $blocks = [];
            }
            foreach ($blocks as $key => $def) {
                $key = (string) $key;
                if (!self::validKey($key)) {
                    $problems[] = 'site.json → richtext_blocks: "' . $key . '" is not a valid block key — use lowercase letters, digits and _, starting with a letter.';
                    continue;
                }
                $declared[] = $key;
                array_push($problems, ...self::validateBlock($key, $def, $config));
            }
        }

        // Todo campo de texto com `blocks`, em qualquer lugar do site.json —
        // página, tipo de item, template, opções, membros, grupo, dentro de
        // repetidor. Os de dentro dos blocos são conferidos acima.
        $fields = [];
        foreach ($site as $topKey => $node) {
            if ($topKey !== 'richtext_blocks') {
                self::richtextWithBlocks($node, $fields);
            }
        }
        $firstUse = null;
        foreach ($fields as $field) {
            $name = FieldRenderer::nameOf($field, '?');
            $list = $field['blocks'];
            if (!is_array($list) || !array_is_list($list)) {
                $problems[] = 'site.json: richtext field "' . $name . '": "blocks" must be a list of block keys.';
                continue;
            }
            foreach ($list as $key) {
                if (!is_string($key) || !in_array($key, $declared, true)) {
                    $problems[] = 'site.json: richtext field "' . $name . '" lists the block "'
                        . (is_scalar($key) ? (string) $key : gettype($key)) . '", which is not declared in "richtext_blocks".';
                } else {
                    $firstUse ??= $name;
                }
            }
        }

        // Sem o helper no tema, o marcador é uma <div> vazia: o autor insere
        // o bloco e ele nunca aparece no site.
        if ($firstUse !== null && !self::themeCallsHelper($config->path('theme'))) {
            $problems[] = 'site.json: richtext field "' . $firstUse . '" accepts blocks, but no theme file calls richtext() — '
                . 'the blocks would never show on the site. Print the field with <?= richtext(field(\'' . $firstUse . '\')) /* trusted */ ?>.';
        }

        return $problems;
    }

    /** @return list<string> */
    private static function validateBlock(string $key, mixed $def, Config $config): array
    {
        $where = 'Block "' . $key . '"';
        if (!is_array($def)) {
            return [$where . ': definition must be an object.'];
        }

        $problems = [];
        if (!is_string($def['label'] ?? null) || trim($def['label']) === '') {
            $problems[] = $where . ': "label" is required (the name shown in the editor).';
        }

        $fields = $def['fields'] ?? null;
        if (!is_array($fields) || $fields === [] || !array_is_list($fields)) {
            $problems[] = $where . ': needs a non-empty "fields" list.';
        } else {
            $resolved = $config->resolveFields($fields);

            $nested = [];
            self::richtextWithBlocks($resolved, $nested);
            foreach ($nested as $field) {
                $problems[] = $where . ': the richtext field "' . FieldRenderer::nameOf($field, '?')
                    . '" can\'t accept blocks — there is no block inside a block.';
            }

            // O partial recebe os campos como variáveis (o extract() do
            // partial()): um nome que não é variável de PHP, ou que o
            // partial() já usa, chegaria lá sem valor e sem aviso.
            foreach (array_keys(FieldRenderer::collect($resolved, [])) as $name) {
                $name = (string) $name;
                if (in_array($name, ['this', '__data', '__file'], true)) {
                    $problems[] = $where . ': field "' . $name . '" can\'t be a variable in the partial — the name is taken, pick another.';
                } elseif (preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/D', $name) !== 1) {
                    $problems[] = $where . ': field "' . $name . '" can\'t be a variable in the partial — '
                        . 'use letters, digits and _, not starting with a digit.';
                }
            }
        }

        // O partial é ligado pelo nome do arquivo, como os templates: a pasta
        // é fixa, e o nome vem da declaração, nunca da chave.
        $folder = 'theme/partials/' . self::PARTIALS . '/';
        $file = self::file($def);
        if (!array_key_exists('file', $def)) {
            $problems[] = $where . ': "file" is required — the name of its partial in ' . $folder . '.';
        } elseif ($file === null) {
            $problems[] = $where . ': "file" must be just a file name, without folders — the partial is always in ' . $folder . '.';
        } elseif (!is_file($config->path('theme') . '/partials/' . self::PARTIALS . '/' . $file)) {
            $problems[] = $where . ': file not found — create ' . $folder . $file . '.';
        }

        return $problems;
    }

    /**
     * Junta em `$found` toda definição de campo de texto que tem a chave
     * `blocks`, em qualquer profundidade de `$node`.
     *
     * @param list<array<string, mixed>> $found
     */
    private static function richtextWithBlocks(mixed $node, array &$found): void
    {
        if (!is_array($node)) {
            return;
        }
        if (($node['type'] ?? null) === 'richtext' && array_key_exists('blocks', $node)) {
            $found[] = $node;
        }
        foreach ($node as $child) {
            self::richtextWithBlocks($child, $found);
        }
    }

    /** Algum arquivo PHP do tema chama `richtext(`? */
    private static function themeCallsHelper(string $themePath): bool
    {
        if ($themePath === '' || !is_dir($themePath)) {
            return false;
        }
        // CATCH_GET_CHILD: um subdiretório que não dá para abrir é pulado, em
        // vez de derrubar a conferência inteira.
        $files = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($themePath, \FilesystemIterator::SKIP_DOTS),
                static fn(\SplFileInfo $f): bool => !($f->isDir() && in_array($f->getFilename(), ['node_modules', '.git'], true))
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        foreach ($files as $file) {
            // O PHP aceita `richtext (…)` e não diferencia maiúsculas em nome
            // de função; `my_richtext(` é outra função.
            if ($file->isFile() && $file->isReadable() && strtolower($file->getExtension()) === 'php'
                && preg_match('/\brichtext\s*\(/i', (string) file_get_contents($file->getPathname())) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Os marcadores de bloco que o editor do painel não consegue mostrar sem
     * perder algo: dados que não são um objeto JSON, ou conteúdo dentro da
     * tag (o marcador é vazio). No site, um bloco assim não sai. O CLI
     * recusa esses marcadores no conteúdo que recebe.
     *
     * @return list<string> um problema por marcador
     */
    public static function markerProblems(string $html): array
    {
        if (!self::has($html)) {
            return [];
        }
        // As tags que citam data-velk-block, pelo começo de cada uma; a regex
        // estrita (atributos bem fechados) precisa casar na mesma posição —
        // senão a tag está quebrada, tipicamente uma aspa crua num valor.
        preg_match_all('~<div\b[^>]*data-velk-block~i', $html, $loose, PREG_OFFSET_CAPTURE);
        preg_match_all(self::OPEN, $html, $strict, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $byOffset = [];
        foreach ($strict as $tag) {
            $byOffset[$tag[0][1]] = $tag;
        }
        $problems = [];
        foreach ($loose[0] as [, $offset]) {
            $tag = $byOffset[$offset] ?? null;
            if ($tag === null) {
                $problems[] = 'a block marker tag can\'t be read — check the quotes of its attributes (an apostrophe inside data-velk-data=\'…\' ends the value).';
                continue;
            }
            $attrs = self::attributes($tag[1][0]);
            if (!isset($attrs['data-velk-block'])) {
                continue;
            }
            $name = substr((string) preg_replace('/[^A-Za-z0-9_-]/', '?', $attrs['data-velk-block']), 0, 60);
            $rest = substr($html, $tag[0][1] + strlen($tag[0][0]));
            if (preg_match('~^\s*</div>~i', $rest) !== 1) {
                $problems[] = 'block "' . $name . '": the marker must be an empty tag — <div data-velk-block="…" data-velk-data="…"></div> — with nothing inside.';
            }
            $json = trim($attrs['data-velk-data'] ?? '{}');
            if (!str_starts_with($json, '{') || !is_array(json_decode($json, true))) {
                $problems[] = 'block "' . $name . '": data-velk-data is not a JSON object.';
            }
        }
        return $problems;
    }

    /**
     * Os atributos de uma tag, nome em minúsculas => valor decodificado.
     * Atributo repetido: vale o primeiro, como no navegador (e no editor) —
     * senão o painel mostraria um bloco e o site outro.
     *
     * @return array<string, string>
     */
    private static function attributes(string $attributes): array
    {
        preg_match_all(self::ATTR, $attributes, $pairs, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $attrs = [];
        foreach ($pairs as $pair) {
            $attrs[strtolower($pair[1])] ??= html_entity_decode((string) ($pair[2] ?? $pair[3] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return $attrs;
    }

    /** @return array{type: string, data: ?array<string, mixed>}|null */
    private static function marker(string $attributes): ?array
    {
        $attrs = self::attributes($attributes);
        if (!isset($attrs['data-velk-block'])) {
            return null;
        }
        $json = $attrs['data-velk-data'] ?? '';
        $data = $json === '' ? [] : json_decode($json, true);
        return ['type' => $attrs['data-velk-block'], 'data' => is_array($data) ? $data : null];
    }
}
