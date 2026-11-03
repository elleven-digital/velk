<?php

declare(strict_types=1);

// is_safe_url / link_tag / collectLink usam helpers globais + o renderer.
require_once dirname(__DIR__, 2) . '/core/helpers.php';

use Velk\FieldRenderer;

test('safe_url: devolve a URL segura, ou vazio pra perigosa/não-string', function () {
    check_same('https://example.com', safe_url('https://example.com'));
    check_same('/interna', safe_url('/interna'));
    check_same('', safe_url('javascript:alert(1)'));
    check_same('', safe_url("java\tscript:alert(1)"));
    check_same('', safe_url('data:text/html,x'));
    check_same('', safe_url(null));   // mixed: option()/field() podem devolver null
    check_same('', safe_url(''));
    check_same('', safe_url(123));
});

test('is_safe_url: esquemas permitidos e URLs sem esquema passam', function () {
    check_same(true, is_safe_url('https://example.com/x'));
    check_same(true, is_safe_url('http://example.com'));
    check_same(true, is_safe_url('mailto:a@b.com'));
    check_same(true, is_safe_url('tel:+5511999999999'));
    check_same(true, is_safe_url('/pagina-interna'));
    check_same(true, is_safe_url('#ancora'));
    check_same(true, is_safe_url('?q=1'));
    check_same(true, is_safe_url('caminho/relativo'));
    check_same(true, is_safe_url('//cdn.example.com/a.js')); // protocol-relative
    check_same(true, is_safe_url('')); // vazio é inofensivo aqui (o chamador trata)
});

test('is_safe_url: esquemas perigosos são rejeitados', function () {
    check_same(false, is_safe_url('javascript:alert(1)'));
    check_same(false, is_safe_url('JavaScript:alert(1)'));   // case-insensitive
    check_same(false, is_safe_url('  javascript:alert(1)')); // espaço à esquerda
    check_same(false, is_safe_url("java\tscript:alert(1)"));  // tab no meio do esquema
    check_same(false, is_safe_url("java\nscript:alert(1)"));  // newline no meio
    check_same(false, is_safe_url('data:text/html,<script>alert(1)</script>'));
    check_same(false, is_safe_url('vbscript:msgbox(1)'));
    check_same(false, is_safe_url('file:///etc/passwd'));
});

test('link_tag: URL perigosa vira string vazia (nada renderizado)', function () {
    check_same('', link_tag(['url' => 'javascript:alert(1)', 'title' => 'x']));
    check_same('', link_tag(['url' => "java\tscript:alert(1)", 'title' => 'x']));
    // URL válida ainda renderiza normalmente:
    $html = link_tag(['url' => 'https://example.com', 'title' => 'Ok']);
    check(str_contains($html, 'href="https://example.com"'), 'href válido renderiza');
    check(str_contains($html, '>Ok</a>'), 'label renderiza');
});

test('collectLink: URL perigosa não é persistida (link vira null)', function () {
    // collectLink é privado — exercitamos via FieldRenderer::collect com um campo link.
    $defs = [['name' => 'cta', 'type' => 'link']];
    check_same(null, FieldRenderer::collect($defs, ['cta' => ['url' => 'javascript:alert(1)', 'title' => 'x']])['cta']);
    check_same(null, FieldRenderer::collect($defs, ['cta' => ['url' => "java\tscript:alert(1)"]])['cta']);
    // URL válida é persistida:
    $ok = FieldRenderer::collect($defs, ['cta' => ['url' => 'https://example.com', 'title' => 'Ok', 'new_tab' => '1']])['cta'];
    check($ok !== null && $ok['url'] === 'https://example.com' && $ok['title'] === 'Ok' && $ok['new_tab'] === true, 'link válido persiste');
});
