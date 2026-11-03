<?php

declare(strict_types=1);

/**
 * O `bin/velk schema:validate` rodado com um formulário trocado: mescla
 * `$formDef` no PRIMEIRO formulário do theme/site.json real, roda o comando e
 * devolve a saída, restaurando o arquivo pelo backup — o bin/velk fixa o
 * $rootPath e lê o site.json do disco. É o molde de
 * payment_schema_validate_test.php e form_step_schema_test.php num lugar só,
 * para o teste novo não virar a terceira cópia.
 */

if (!function_exists('svf_validate')) {
    /** @param array<string,mixed> $formDef mesclado em forms.<primeiro do tema> */
    function svf_validate(array $formDef): string
    {
        $raiz = dirname(__DIR__, 2);
        $origem = $raiz . '/theme/site.json';
        if (!is_file($origem)) {
            throw new RuntimeException('svf_validate: theme/site.json ausente — este teste precisa de um theme/ real');
        }
        $backup = $origem . '.svf-backup';
        if (is_file($backup)) { rename($backup, $origem); }

        $site = json_decode((string) file_get_contents($origem), true);
        $first = array_key_first((array) ($site['forms'] ?? []));
        if ($first === null) {
            throw new RuntimeException('svf_validate: o theme/site.json não declara nenhum formulário');
        }
        $site['forms'][$first] = array_merge($site['forms'][$first], $formDef);

        if (!copy($origem, $backup)) { throw new RuntimeException('svf_validate: backup falhou'); }
        try {
            file_put_contents($origem, json_encode($site, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($raiz . '/bin/velk') . ' schema:validate 2>&1');
        } finally {
            if (!rename($backup, $origem)) { throw new RuntimeException('svf_validate: restore falhou'); }
        }
        return is_string($out) ? $out : '';
    }
}
