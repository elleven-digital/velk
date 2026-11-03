<?php

declare(strict_types=1);

/**
 * v3.7.5 — o script que esvazia o `_hp` no navegador.
 *
 * Contexto: o honeypot sozinho passou a reprovar humanos. O Chrome ignora
 * `autocomplete="off"` no preenchimento de perfil e, como `_hp` não
 * significa nada, classifica o campo pela POSIÇÃO no formulário; um `_hp`
 * antes de nome/e-mail/telefone é preenchido como se fosse o primeiro campo
 * real. Gerenciadores de senha preenchem direto no DOM, onde `autocomplete`
 * nem é consultado. `readonly` não resolve — houve falso positivo com e sem.
 *
 * O que estes testes travam:
 *
 *   1. O CAMPO continua igual. Tema que só renderiza `form_honeypot()` não
 *      pode quebrar.
 *   2. O script sai UMA vez por request, não uma por formulário.
 *   3. O listener de submit é em CAPTURA. É o detalhe que carrega o peso:
 *      em borbulha, o handler do próprio formulário roda ANTES, e um tema
 *      que envia por fetch monta o `FormData` com o campo ainda sujo — falha
 *      silenciosa, o pior tipo. Trocar `true` por `false` aqui reintroduz
 *      exatamente o bug que motivou a mudança.
 *   4. Também limpa em input/change, que é o que cobre `form.submit()`
 *      programático (esse não dispara evento de submit nenhum).
 *   5. O JS emitido compila.
 *
 * Comportamento provado em navegador real antes de virar teste: autofill com
 * evento limpa na hora; escrita silenciosa no DOM chega limpa tanto no
 * `FormData` do tema quanto no `$_POST` de um envio nativo.
 */

require_once dirname(__DIR__, 2) . '/core/helpers.php';

if (!function_exists('fhs_primeira_emissao')) {
    /**
     * `form_honeypot()` guarda um `static` para emitir o script uma vez só,
     * e outros testes do harness podem tê-lo chamado antes. Um subprocesso
     * limpo é a única forma honesta de observar a PRIMEIRA chamada.
     */
    function fhs_primeira_emissao(): string
    {
        $helpers = dirname(__DIR__, 2) . '/core/helpers.php';
        $tmp = tempnam(sys_get_temp_dir(), 'velk_hp_') . '.php';
        file_put_contents(
            $tmp,
            "<?php require " . var_export(dirname(__DIR__, 2) . '/core/Autoloader.php', true) . ";\n"
            . "Velk\\Autoloader::register(" . var_export(dirname(__DIR__, 2), true) . ");\n"
            . "require " . var_export($helpers, true) . ";\n"
            . "echo form_honeypot();\n"
            . "echo '<!--SEGUNDA-->';\n"
            . "echo form_honeypot();\n"
        );
        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' 2>&1');
        @unlink($tmp);

        return is_string($out) ? $out : '';
    }
}

if (!function_exists('fhs_script')) {
    function fhs_script(string $html): string
    {
        return preg_match('~<script[^>]*>(.*?)</script>~s', $html, $m) === 1 ? $m[1] : '';
    }
}

test('form_honeypot: o campo _hp continua exatamente o mesmo', function () {
    // Retrocompatibilidade literal: o servidor lê `$request->post['_hp']` e o
    // tema pode estar contando com o campo escondido do mesmo jeito.
    $html = form_honeypot();

    check(str_contains($html, 'name="_hp"'), 'perdeu o name="_hp"');
    check(str_contains($html, 'type="text"'), 'perdeu o type="text"');
    check(str_contains($html, 'tabindex="-1"'), 'perdeu o tabindex="-1"');
    check(str_contains($html, 'left:-9999px'), 'perdeu o posicionamento fora da tela');
});

test('form_honeypot: o script sai na primeira chamada e não se repete', function () {
    $out = fhs_primeira_emissao();
    [$primeira, $segunda] = explode('<!--SEGUNDA-->', $out, 2) + ['', ''];

    check(str_contains($primeira, '<script'), "primeira chamada deveria emitir o script; saída:\n{$out}");
    check(!str_contains($segunda, '<script'), 'segunda chamada não pode repetir o script — é um listener por request, não por formulário');
    check(str_contains($segunda, 'name="_hp"'), 'segunda chamada ainda precisa emitir o campo');
});

test('form_honeypot: o listener de submit é em CAPTURA', function () {
    // Em borbulha o tema AJAX monta o FormData antes de nós limparmos.
    $js = fhs_script(fhs_primeira_emissao());
    check($js !== '', 'não conseguiu extrair o JS emitido');

    check(
        preg_match('~addEventListener\(\s*[\'"]submit[\'"].*?,\s*true\s*\)~s', $js) === 1,
        'o listener de submit precisa ser registrado com capture=true'
    );
});

test('form_honeypot: também limpa em input e change', function () {
    // Cobre form.submit() programático, que não dispara submit nenhum.
    $js = fhs_script(fhs_primeira_emissao());

    check(str_contains($js, "addEventListener('input'"), 'faltou o listener de input');
    check(str_contains($js, "addEventListener('change'"), 'faltou o listener de change');
});

test('form_honeypot: o JS emitido compila', function () {
    $js = fhs_script(fhs_primeira_emissao());
    check($js !== '', 'não conseguiu extrair o JS emitido');

    $tmp = tempnam(sys_get_temp_dir(), 'velk_hp_') . '.js';
    file_put_contents($tmp, $js);
    $out = shell_exec('node --check ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);

    check_same('', trim((string) $out));
});
