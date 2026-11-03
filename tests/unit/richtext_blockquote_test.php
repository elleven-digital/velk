<?php

declare(strict_types=1);

use Velk\FieldRenderer;

/**
 * A citação (blockquote) no editor richtext do painel.
 *
 * O editor sempre aceitou citação — o nó faz parte do pacote do TipTap, e o
 * painel até tem o estilo dela —, mas só por atalho (`> ` no começo da linha,
 * Ctrl/Cmd+Shift+B) ou colando. Sem botão na barra, quem escreve o post não
 * tinha como descobrir.
 */

require_once dirname(__DIR__, 2) . '/core/helpers.php';

if (!function_exists('rbq_toolbar')) {
    /** A barra do editor de um campo richtext, com o painel na língua dada. */
    function rbq_toolbar(string $locale): string
    {
        $root = dirname(__DIR__, 2);
        $app = new \Velk\App(new \Velk\Config($root));
        $app->adminI18n = new \Velk\Admin\I18n($root . '/core/Admin/lang', $locale, 'en');

        $html = FieldRenderer::render(['name' => 'content', 'type' => 'richtext', 'label' => 'Conteúdo'], '');
        check(preg_match('#<div class="richtext__toolbar" data-richtext-toolbar>(.*?)</div>\s*<div class="richtext__editor"#s', $html, $m) === 1, 'barra do editor não encontrada');
        return $m[1] ?? '';
    }
}

test('richtext: a barra tem o botão de citação, com o rótulo na língua do painel', function () {
    foreach (['pt' => 'Citação', 'en' => 'Quote', 'es' => 'Cita'] as $locale => $rotulo) {
        $toolbar = rbq_toolbar($locale);
        check(
            str_contains($toolbar, '<button type="button" data-cmd="blockquote" title="' . $rotulo . '">' . $rotulo . '</button>'),
            "{$locale}: {$toolbar}"
        );
    }
});

test('richtext: todo botão da barra tem tratamento no admin.js — nenhum botão morto', function () {
    // O servidor desenha a barra e o admin.js trata o clique pelo data-cmd.
    // Um botão sem `case` no admin.js não faz nada, e nada avisa.
    preg_match_all('/data-cmd="([A-Za-z0-9]+)"/', rbq_toolbar('en'), $m);
    $comandos = array_values(array_unique($m[1]));
    check(in_array('blockquote', $comandos, true), 'a barra deveria ter o comando blockquote');
    check(count($comandos) >= 19, 'barra com menos comandos do que o esperado: ' . implode(', ', $comandos));

    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/core/Admin/assets/js/admin.js');
    $inicio = strpos($js, "toolbarEl.addEventListener('click'");
    $fim = strpos($js, 'updateToolbarState();', (int) $inicio);
    check($inicio !== false && $fim !== false, 'tratamento do clique da barra não encontrado no admin.js');
    $clique = substr($js, (int) $inicio, (int) $fim - (int) $inicio);

    $mortos = array_values(array_filter($comandos, fn(string $cmd): bool => !str_contains($clique, "case '{$cmd}':")));
    check_same([], $mortos);
});
