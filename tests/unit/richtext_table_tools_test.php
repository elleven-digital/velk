<?php

declare(strict_types=1);

use Velk\FieldRenderer;

/**
 * As operações da tabela no editor richtext: numa faixa própria, embaixo da
 * barra, que só aparece com o cursor numa tabela.
 *
 * Antes eram cinco botões no meio da barra — +R, −R, +C, −C e × —, apagados
 * (desabilitados) fora de uma tabela, o que era quase sempre, e com nomes que
 * só a dica do mouse explicava. Na faixa própria cabem os nomes por extenso.
 */

require_once dirname(__DIR__, 2) . '/core/helpers.php';

if (!function_exists('rtt_html')) {
    /** O HTML de um campo richtext, com o painel na língua dada. */
    function rtt_html(string $locale): string
    {
        $root = dirname(__DIR__, 2);
        $app = new \Velk\App(new \Velk\Config($root));
        $app->adminI18n = new \Velk\Admin\I18n($root . '/core/Admin/lang', $locale, 'en');
        return FieldRenderer::render(['name' => 'content', 'type' => 'richtext', 'label' => 'Conteúdo'], '');
    }

    /** O trecho do admin.js entre dois marcos, sem o segundo. */
    function rtt_js_slice(string $from, string $to): string
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/core/Admin/assets/js/admin.js');
        $start = strpos($js, $from);
        $end = $start === false ? false : strpos($js, $to, $start);
        check($start !== false && $end !== false, "trecho não encontrado no admin.js: {$from}");
        return substr($js, (int) $start, (int) $end - (int) $start);
    }
}

test('tabela: as operações ficam numa faixa abaixo da barra, escondida de início', function () {
    $html = rtt_html('pt');
    check(
        preg_match('#<div class="richtext__toolbar-row richtext__toolbar-row--table" data-table-tools role="group" aria-label="Tabela" hidden>(.*?)</div>#s', $html, $m) === 1,
        'a faixa da tabela existe, e começa escondida'
    );
    $faixa = $m[1] ?? '';
    foreach (['addRowAfter' => 'Adicionar linha', 'deleteRow' => 'Remover linha', 'addColumnAfter' => 'Adicionar coluna', 'deleteColumn' => 'Remover coluna', 'deleteTable' => 'Remover tabela'] as $cmd => $nome) {
        check(str_contains($faixa, '<button type="button" data-cmd="' . $cmd . '">' . $nome . '</button>'), "{$cmd} com o nome por extenso");
    }
    // Sem rótulo à vista (os botões já dizem o que fazem); o nome da faixa
    // fica para o leitor de tela, no aria-label acima.
    check(!str_contains($faixa, 'richtext__toolbar-label'), 'sem rótulo no começo da faixa');
});

test('tabela: a barra de cima fica só com o botão que cria a tabela', function () {
    $html = rtt_html('pt');
    check(preg_match('#<div class="richtext__toolbar-row">(.*?)</div>#s', $html, $m) === 1, 'a faixa principal');
    $principal = $m[1] ?? '';
    check(str_contains($principal, 'data-cmd="insertTable"'), 'o botão Tabela segue na barra');
    foreach (['+R', '−R', '+C', '−C', 'data-table-op', 'data-cmd="deleteTable"'] as $velho) {
        check(!str_contains($principal, $velho), "sem {$velho} na barra de cima");
    }
});

test('tabela: os nomes da faixa vêm do idioma do painel', function () {
    $html = rtt_html('en');
    check(str_contains($html, '<button type="button" data-cmd="addRowAfter">Add row</button>'), 'faixa renderizada em inglês');
    check(!str_contains($html, '>Adicionar linha</button>'), 'sem português no painel em inglês');
});

test('tabela: o editor mostra a faixa só com o cursor numa tabela', function () {
    $estado = rtt_js_slice('const updateToolbarState = () => {', 'const opening = editor.state.selection;');
    check(str_contains($estado, "toolbarEl.querySelector('[data-table-tools]')"), 'o editor acha a faixa');
    check(str_contains($estado, "tableTools.hidden = !editor.isActive('table');"), 'e a esconde fora de uma tabela');

    $css = (string) file_get_contents(dirname(__DIR__, 2) . '/core/Admin/assets/css/admin.css');
    // `display: flex` venceria o `hidden` do navegador: sem esta regra a
    // faixa nunca sumiria.
    check(preg_match('/\.richtext__toolbar-row\[hidden\]\s*\{\s*display:\s*none;\s*\}/', $css) === 1, 'a faixa escondida não aparece');
});
