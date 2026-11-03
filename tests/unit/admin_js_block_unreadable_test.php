<?php

declare(strict_types=1);

/**
 * Guardas do editor de texto (admin.js) para um marcador de bloco que a janela
 * do bloco não consegue mostrar: dados que não são um objeto JSON, ou
 * conteúdo dentro da tag. Antes, salvar o texto trocava os dados por `{}` e
 * apagava o conteúdo de dentro; agora os dois voltam como vieram, e o cartão
 * fica sem o botão Editar.
 *
 * O JavaScript do painel não tem executor de testes; o comportamento foi
 * conferido no navegador, com o admin.js real (salvar, recortar e colar).
 */

if (!function_exists('ajb_js')) {
    function ajb_js(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/core/Admin/assets/js/admin.js');
    }
}

test('editor de texto: dados ilegíveis de um bloco voltam como vieram', function () {
    $js = ajb_js();
    check(str_contains($js, "renderHTML: (attrs) => ({ 'data-velk-data': attrs.raw ?? JSON.stringify(attrs.data) })"), 'o data-velk-data sai do `raw` quando há');
    check(str_contains($js, 'readBlockData(value) === null ? value : null'), '`raw` guarda o valor que não é objeto JSON');
});

test('editor de texto: o conteúdo de dentro do marcador volta para dentro da tag', function () {
    $js = ajb_js();
    check(str_contains($js, "parseHTML: (el) => el.innerHTML.trim(),"), 'lido da tag; espaço em branco não conta');
    check(str_contains($js, 'data-velk-inner="([^"]*)"><\\/div>/g'), 'cleanRichHtml devolve o conteúdo');
    check(str_contains($js, "area.innerHTML = value;\n  return area.value;"), 'decodificado num textarea, sem montar HTML na página');
});

test('editor de texto: o cartão de um bloco ilegível não tem Editar', function () {
    $js = ajb_js();
    check(str_contains($js, "const unreadable = node.attrs.raw !== null || node.attrs.inner !== '';"));
    check(str_contains($js, 'if (known && !unreadable) {'));
});

test('editor de texto: o marcador colado entra vazio — conteúdo de outra página não vai para o site', function () {
    $js = ajb_js();
    $start = strpos($js, 'const stripDisallowedBlocks = ');
    $paste = substr($js, (int) $start, (int) strpos($js, '};', (int) $start) - (int) $start);
    check(str_contains($paste, "el.removeAttribute('data-velk-inner');") && str_contains($paste, 'el.replaceChildren();'));
});

test('editor de texto: o parágrafo vazio no fim sai também depois de um bloco com conteúdo guardado', function () {
    check(str_contains(ajb_js(), '(?: data-velk-inner="[^"]*")?><\\/div>)(?:<p><\\/p>)+$/'));
});
