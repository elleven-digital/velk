<?php

declare(strict_types=1);

use Velk\Admin\Controllers\Controller;
use Velk\Admin\Controllers\FormController;
use Velk\Request;

/**
 * O painel roda sem `'unsafe-inline'` no `script-src`: todo JavaScript vem de
 * arquivo (admin.js), e o que a página precisa passar para ele vai num bloco
 * de dados (`<script type="application/json">`), que o navegador guarda e
 * não executa. Assim um script injetado por uma falha de escape não roda.
 *
 * O `style-src` segue com `'unsafe-inline'` de propósito: são ~170 atributos
 * `style=` nas views, e CSS injetado tem muito menos alcance que JavaScript.
 */

require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__) . '/fixtures/form_export_boot.php';

if (!function_exists('acs_csp')) {
    function acs_csp(): string
    {
        return (string) (new ReflectionClassConstant(Controller::class, 'ADMIN_CSP'))->getValue();
    }
}

if (!function_exists('acs_admin_sources')) {
    /** @return array<string,string> caminho => conteúdo de tudo que vira HTML do painel */
    function acs_admin_sources(): array
    {
        $root = dirname(__DIR__, 2);
        $out = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/core/Admin/views'));
        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.php') && !str_contains($f->getPathname(), '/views/emails/')) {
                $out[substr($f->getPathname(), strlen($root) + 1)] = (string) file_get_contents($f->getPathname());
            }
        }
        $out['core/FieldRenderer.php'] = (string) file_get_contents($root . '/core/FieldRenderer.php');
        return $out;
    }
}

test('csp: o script-src do painel não tem unsafe-inline; o style-src mantém', function () {
    check(preg_match("/script-src ([^;]*);/", acs_csp(), $m) === 1, acs_csp());
    check_same("'self'", trim($m[1]));
    check(str_contains(acs_csp(), "style-src 'self' 'unsafe-inline'"), 'o style-src continua como estava');
});

test('csp: um formulário do painel só envia para o próprio site (form-action)', function () {
    // Com o script bloqueado, o que sobra para quem consegue injetar HTML é
    // um formulário falso ("sua sessão expirou, entre de novo") que manda a
    // senha para fora. O form-action não herda do default-src: sem ele, o
    // 'self' do default-src não cobre o envio de formulário.
    check(preg_match("/(?:^|; )form-action ([^;]*)(?:;|$)/", acs_csp(), $m) === 1, acs_csp());
    check_same("'self'", trim($m[1]));
});

test('csp: nenhuma tela do painel tem <script> executável embutido', function () {
    // Só passa <script src="…">, o bloco de dados (application/json) e o
    // modelo de HTML dos campos repetíveis (text/template) — os dois últimos
    // o navegador não executa.
    $bad = [];
    foreach (acs_admin_sources() as $path => $src) {
        preg_match_all('/<script\b[^>]*>/i', $src, $tags);
        foreach ($tags[0] as $tag) {
            if (!preg_match('/\bsrc=|type="(application\/json|text\/template)"/', $tag)) {
                $bad[] = "{$path}: {$tag}";
            }
        }
    }
    check_same([], $bad);
});

test('csp: nenhum handler inline (on…=) nem link javascript: no painel', function () {
    $bad = [];
    $sources = acs_admin_sources();
    $sources['core/Admin/assets/js/admin.js'] = (string) file_get_contents(dirname(__DIR__, 2) . '/core/Admin/assets/js/admin.js');
    foreach ($sources as $path => $src) {
        if (preg_match_all('/\son[a-z]+\s*=\s*["\']|href=["\']javascript:/i', $src, $m)) {
            $bad[] = $path . ': ' . implode(' ', $m[0]);
        }
    }
    check_same([], $bad, 'mude para data-autosubmit ou um listener no admin.js');
});

test('json_script: o bloco de dados não se deixa fechar por um </script> no conteúdo', function () {
    $data = ['a' => '</script><script>alert(1)</script>', 'b' => "O'Reilly & \"Cia\"", 'c' => 'ção'];
    $html = json_script('dados', $data);
    check(str_starts_with($html, '<script type="application/json" id="dados">'), $html);
    check(str_ends_with($html, '</script>'), $html);
    $inner = substr($html, strlen('<script type="application/json" id="dados">'), -strlen('</script>'));
    foreach (['<', '>', '&', "'"] as $raw) {
        check(!str_contains($inner, $raw), "caractere cru no bloco: {$raw}");
    }
    check_same($data, json_decode($inner, true), 'o JSON.parse do admin.js recebe o valor original');
});

if (!function_exists('acs_inner')) {
    /** O conteúdo de um bloco do json_script(), sem as tags. */
    function acs_inner(string $html, string $id): string
    {
        $open = '<script type="application/json" id="' . $id . '">';
        check(str_starts_with($html, $open) && str_ends_with($html, '</script>'), $html);
        return substr($html, strlen($open), -strlen('</script>'));
    }

    /** `$fn` com o error_log do PHP num arquivo; devolve o que foi registrado. */
    function acs_log(callable $fn): string
    {
        $file = tempnam(sys_get_temp_dir(), 'velk_acs_log_');
        $old = ini_set('error_log', $file);
        try {
            $fn();
        } finally {
            ini_set('error_log', $old === false ? '' : $old);
        }
        $out = (string) file_get_contents($file);
        @unlink($file);
        return $out;
    }
}

test('json_script: byte inválido de UTF-8 vira o caractere de substituição, e o bloco segue legível', function () {
    // Texto vindo de fora (um campo do tema, um import antigo em latin1):
    // sem a substituição o json_encode falha e o bloco sairia vazio.
    $inner = acs_inner(json_script('d', ['nome' => "Jo\xE3o"]), 'd');
    check_same(['nome' => "Jo\u{FFFD}o"], json_decode($inner, true), $inner);
});

test('json_script: um valor que o JSON não representa sai como null, e o motivo vai para o log', function () {
    $html = null;
    $log = acs_log(function () use (&$html) {
        $html = json_script('d', ['x' => NAN]);
    });
    check_same('null', acs_inner($html, 'd'), 'um bloco vazio faria o JSON.parse lançar');
    check(str_contains($log, '[Velk] json_script("d")'), $log);
});

test('layout: a página do painel traz os textos do JavaScript num bloco de dados', function () {
    $app = fexb_boot(['label' => 'Contato', 'fields' => [['name' => 'nome', 'label' => 'Nome', 'type' => 'text']]]);
    fexb_admin($app, true);
    $html = (new FormController())
        ->show(new Request('GET', '/admin/forms/contato', [], [], [], [], []), ['id' => 'contato'])
        ->getBody();
    check(!str_contains($html, 'window.VELK_ADMIN_I18N'), 'sobrou o script que atribui a variável');
    check(preg_match('#<script type="application/json" id="velk-admin-i18n">(.*?)</script>#s', $html, $m) === 1, 'bloco de dados ausente');
    $i18n = json_decode($m[1], true);
    check(is_array($i18n) && ($i18n['js_menu_open'] ?? '') !== '' && isset($i18n['js_bulk_selected_one']), (string) $m[1]);
});

test('layout: o painel e o iframe do seletor de mídia usam a mesma lista de textos', function () {
    $root = dirname(__DIR__, 2) . '/core/Admin/views/layout/';
    foreach (['main.php', 'embed.php'] as $layout) {
        check(str_contains((string) file_get_contents($root . $layout), "include __DIR__ . '/_js_i18n.php';"), $layout);
    }
});
