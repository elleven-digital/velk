<?php

declare(strict_types=1);

/**
 * M2: the `_VelkPathTranslator` class in core/helpers.php is declared inside
 * `if (!class_exists(...)) { class _VelkPathTranslator { ... } }` so a
 * double-include of helpers.php (two different require paths resolving to
 * the same file) doesn't fatal with "Cannot redeclare class". That only
 * works if the guard checks the class actually declared inside the block.
 *
 * Two angles, both against the REAL source (not a hand-copied mirror that
 * could silently drift from it):
 *   1. Static: the class_exists(...) argument in the guard matches the name
 *      of the class declared right after it. This is what directly encodes
 *      "guard checks the actual declared class name" and fails immediately
 *      if someone reverts to a wrong/phantom name.
 *   2. Behavioral: the exact guarded block, isolated and double-included in
 *      a subprocess, really doesn't fatal. Run in a subprocess (not in this
 *      process) because a genuine redeclare fatal is a compile-time error
 *      PHP cannot catch with try/catch — it would take down the whole suite.
 *      Isolated to just this block (not the whole helpers.php) so it can't
 *      be confounded by unrelated bugs elsewhere in that large file.
 */

if (!function_exists('helpers_php_source')) {
    function helpers_php_source(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/core/helpers.php');
    }
}

test('M2 (static): a guarda class_exists() referencia o nome REAL da classe declarada no bloco', function () {
    $src = helpers_php_source();
    check(
        (bool) preg_match('/class_exists\(\s*([\'"])([^\'"]*)\1\s*\)\s*\)\s*\{\s*(?:\/\*\*(?:[^*]|\*(?!\/))*\*\/\s*)?class\s+([A-Za-z_][A-Za-z0-9_]*)/s', $src, $m),
        'não encontrou o padrão "if (!class_exists(...)) { class X" em helpers.php — a guarda foi removida/reestruturada?'
    );
    check_same($m[3], $m[2], "a guarda checa '{$m[2]}' mas a classe declarada é '{$m[3]}' — double include fatalizaria");
});

test('M2 (static): o nome checado é a classe global _VelkPathTranslator, não um namespace fantasma', function () {
    $src = helpers_php_source();
    check(str_contains($src, "class_exists('_VelkPathTranslator')"), 'guarda deve checar exatamente a classe global _VelkPathTranslator');
    check(!str_contains($src, "class_exists('Velk\\\\Internal\\\\PathTranslator')"), 'não pode voltar a checar o nome namespaced que nunca existiu');
});

if (!function_exists('extract_path_translator_guard_block')) {
    /** Extrai só o bloco `if (!class_exists('_VelkPathTranslator')) { ... }` do source real. */
    function extract_path_translator_guard_block(string $src): ?string
    {
        $start = strpos($src, "if (!class_exists('_VelkPathTranslator'))");
        if ($start === false) return null;
        // Conta chaves a partir do primeiro '{' após o início do bloco pra
        // achar o '}' que fecha o if — robusto ao corpo interno (que tem
        // suas próprias chaves aninhadas).
        $openPos = strpos($src, '{', $start);
        if ($openPos === false) return null;
        $depth = 0;
        $len = strlen($src);
        for ($i = $openPos; $i < $len; $i++) {
            if ($src[$i] === '{') $depth++;
            elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $start, $i - $start + 1);
                }
            }
        }
        return null;
    }
}

test('M2 (behavioral): o bloco real da guarda, incluído duas vezes isolado, não fataliza', function () {
    $block = extract_path_translator_guard_block(helpers_php_source());
    check($block !== null, 'não conseguiu extrair o bloco da guarda do source real');
    check(str_contains($block, 'class _VelkPathTranslator'), 'bloco extraído não contém a declaração da classe — extração quebrada');

    $tmp = tempnam(sys_get_temp_dir(), 'velk_guard_') . '.php';
    // App é só usado em type-hints dentro do método translate(); não é
    // chamado neste teste (não invocamos translate()), então não precisa
    // existir de verdade — só declaramos o bloco duas vezes e confirmamos
    // que o processo filho não fataliza.
    file_put_contents($tmp, "<?php\n" . $block . "\n" . $block . "\necho 'DOUBLE_INCLUDE_OK';");
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);

    check(is_string($out) && str_contains($out, 'DOUBLE_INCLUDE_OK'), "bloco duplicado deveria rodar sem fatal; saída real:\n{$out}");
    check(!is_string($out) || !str_contains($out, 'Cannot redeclare'), "não pode fatalizar por redeclaração; saída:\n{$out}");
});
