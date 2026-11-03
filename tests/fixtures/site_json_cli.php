<?php

declare(strict_types=1);

/**
 * Um comando do `bin/velk` rodado com chaves de topo trocadas no
 * theme/site.json real: grava o site.json alterado, roda o comando e devolve
 * a saída, restaurando o arquivo pelo backup — o bin/velk fixa o $rootPath e
 * lê o site.json do disco.
 *
 * É a mesma técnica do `schema_validate_form.php`, para qualquer chave de
 * topo e qualquer comando. Mora em `tests/fixtures/` para os testes que a
 * usam não carregarem cada um a sua cópia.
 */

if (!function_exists('sjc_velk')) {
    /**
     * @param array<string, mixed>  $patch chave de topo => valor (substitui; null tira a chave)
     * @param array<string, string> $env   variáveis para o subprocesso — o
     *   Env::load() não sobrescreve chave já presente, então vencem o .env
     */
    function sjc_velk(string $command, array $patch, array $env = []): string
    {
        $raiz = dirname(__DIR__, 2);
        $origem = $raiz . '/theme/site.json';
        if (!is_file($origem)) {
            throw new RuntimeException('sjc_velk: theme/site.json ausente — este teste precisa de um theme/ real');
        }
        $backup = $origem . '.sjc-backup';
        if (is_file($backup)) { rename($backup, $origem); }

        // Uma chave do patch com valor null sai do site.json.
        $site = array_filter(
            array_merge(json_decode((string) file_get_contents($origem), true), $patch),
            static fn($value) => $value !== null
        );

        if (!copy($origem, $backup)) { throw new RuntimeException('sjc_velk: backup falhou'); }
        try {
            file_put_contents($origem, json_encode($site, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $prefix = '';
            foreach ($env as $k => $v) { $prefix .= $k . '=' . escapeshellarg($v) . ' '; }
            $out = shell_exec($prefix . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($raiz . '/bin/velk') . ' ' . $command . ' 2>&1');
        } finally {
            if (!rename($backup, $origem)) { throw new RuntimeException('sjc_velk: restore falhou'); }
        }
        return is_string($out) ? $out : '';
    }
}
