<?php

declare(strict_types=1);

/**
 * Guarda da barra do editor de texto (richtext), que gruda no topo da tela
 * enquanto o autor rola um texto longo, e da tela que acompanha o que a barra
 * insere.
 *
 * A barra sempre teve `position: sticky`, e nunca grudou: a caixa do editor
 * cortava os cantos com `overflow: hidden`, e um ancestral com overflow
 * diferente de `visible` vira o contêiner de rolagem do sticky — a barra
 * grudava numa caixa que não rola. O mesmo vale para as caixas em volta de um
 * editor: a linha do repetidor, a do campo flexível e o conjunto de abas.
 *
 * Nem o CSS nem o JavaScript do painel têm executor de testes: este arquivo
 * lê o admin.css e o admin.js, como os outros `admin_*_test.php`. O
 * comportamento em si foi conferido no navegador — num texto de 90
 * parágrafos, com a tela rolada 3000 px, a barra estava a -1789 px do topo
 * com `hidden` e em 0 px com `clip`; uma imagem inserida com o cursor fora da
 * tela ficava a 5433 px do topo numa janela de 900 px, e a primeira linha de
 * uma tabela nova ficava atrás da barra de salvar.
 */

if (!function_exists('rts_css')) {
    function rts_css(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/core/Admin/assets/css/admin.css');
    }

    function rts_js(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/core/Admin/assets/js/admin.js');
    }

    /** O corpo da primeira regra do admin.css com exatamente este seletor. */
    function rts_rule(string $selector): string
    {
        $found = preg_match('/^' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/m', rts_css(), $m);
        check($found === 1, "regra não encontrada no admin.css: {$selector}");
        return $m[1] ?? '';
    }

    /** O trecho do admin.js entre dois marcos, sem o segundo. */
    function rts_slice(string $from, string $to): string
    {
        $js = rts_js();
        $start = strpos($js, $from);
        $end = $start === false ? false : strpos($js, $to, $start);
        check($start !== false && $end !== false, "trecho não encontrado no admin.js: {$from}");
        return substr($js, (int) $start, (int) $end - (int) $start);
    }
}

test('barra do editor: gruda no topo da tela', function () {
    $toolbar = rts_rule('.richtext__toolbar');
    check(preg_match('/position:\s*sticky;/', $toolbar) === 1, 'a barra é sticky');
    check(preg_match('/top:\s*0;/', $toolbar) === 1, 'e gruda no topo');
});

test('barra do editor: nenhuma caixa em volta dela vira contêiner de rolagem', function () {
    // `clip` corta nos cantos arredondados como `hidden`, sem criar contêiner
    // de rolagem. A ordem importa: o `hidden` é o que vale no navegador que
    // não conhece `clip`, e só o último dos dois vale nos outros.
    foreach (['.richtext', '.repeater__row', '.flexible-field__row', '.field-tabs'] as $selector) {
        check(
            preg_match('/overflow:\s*hidden;\s*overflow:\s*clip;/', rts_rule($selector)) === 1,
            "{$selector} corta com `overflow: clip` (e `hidden` antes dele, para o navegador sem `clip`)"
        );
    }
});

test('barra do editor: em tela estreita gruda abaixo da barra fixa do topo', function () {
    $css = rts_css();
    // A altura da barra do topo (.app::before) e o `top` da barra do editor
    // são o mesmo número: se um mudar, o outro vai junto.
    check(
        preg_match('/\.app::before\s*\{[^}]*position:\s*fixed;[^}]*height:\s*(\d+)px;/', $css, $bar) === 1,
        'a barra fixa do topo (.app::before) tem altura em px'
    );
    check(
        preg_match('/\.app \.richtext__toolbar\s*\{\s*top:\s*(\d+)px;/', $css, $toolbar) === 1,
        'dentro de .app, a barra do editor tem um `top` próprio'
    );
    check_same($bar[1] ?? null, $toolbar[1] ?? null, 'o `top` da barra do editor é a altura da barra do topo');
});

test('barra do editor: em tela de celular é uma linha só, que rola de lado', function () {
    // Quebrada em linhas ela tem quatro ou cinco delas (118 px numa tela de
    // 375 px): grudada, cobriria boa parte da tela.
    check(
        preg_match(
            '/@media \(max-width: 699px\)\s*\{\s*\.richtext__toolbar-row\s*\{[^}]*flex-wrap:\s*nowrap;[^}]*overflow-x:\s*auto;/',
            rts_css()
        ) === 1,
        'até 699px a barra não quebra em linhas e rola de lado'
    );
});

test('editor: ao levar a tela até o cursor, desconta as barras grudadas', function () {
    $covered = rts_slice('const covered = (gap) => ({', 'const editor = new Editor({');
    check(
        preg_match('/get top\(\)\s*\{[^}]*toolbarEl\?\.getBoundingClientRect\(\)\.bottom/', $covered) === 1,
        'em cima, o que cobre o texto vai até a base da barra do editor'
    );
    check(
        preg_match('/get bottom\(\)\s*\{[^}]*saveBar\?\.getBoundingClientRect\(\)/', $covered) === 1,
        'embaixo, a partir do topo da barra de salvar'
    );

    $props = rts_slice('editorProps: {', 'content: sourceEl.value');
    check(str_contains($props, 'scrollThreshold: covered(0)'), 'o editor rola quando o cursor entra atrás de uma barra');
    check(str_contains($props, 'scrollMargin: covered(5)'), 'e o deixa a 5 px dela, como da borda da tela');
});

test('editor: a tela acompanha a imagem e o bloco inseridos', function () {
    // Inserir não rola a tela sozinho (o insertContent do TipTap não pede, e o
    // focus() do pacote vendorizado não rola): sem o pedido, o que entra com
    // o cursor fora da tela fica onde o autor não vê.
    $block = rts_slice('const insertBlock = (editor, attrs) => {', 'const cleanRichHtml');
    $cursor = strrpos($block, 'cursorAfterBlock(editor);');
    $scroll = strpos($block, 'editor.commands.scrollIntoView();');
    check($cursor !== false && $scroll !== false, 'o bloco novo pede a rolagem');
    check((int) $scroll > (int) $cursor, 'depois de o cursor ir para o lugar dele, logo após o cartão');

    $image = rts_slice("case 'image': {", "case 'block':");
    check(str_contains($image, 'editor.commands.scrollIntoView();'), 'a imagem nova pede a rolagem');
    check(
        preg_match("/addEventListener\('load', \(\) => editor\.commands\.scrollIntoView\(\), \{ once: true \}\)/", $image) === 1,
        'e pede de novo quando carrega: só então ela ganha altura e empurra o cursor'
    );
});
