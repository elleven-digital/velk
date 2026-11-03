<?php

declare(strict_types=1);

use Velk\App;
use Velk\Config;
use Velk\FieldRenderer;

/**
 * O campo de texto que aceita blocos do tema, como o painel o desenha: o
 * botão "Bloco" na barra, e o que o editor precisa para abrir a janela do
 * bloco — a lista dos blocos aceitos, o endereço e o token de CSRF.
 *
 * Um campo sem `blocks` não muda: nem botão, nem atributos.
 */

require_once dirname(__DIR__, 2) . '/core/helpers.php';

if (!function_exists('rbf_render')) {
    /** O HTML de um campo richtext com o `blocks` dado, no painel na língua dada. */
    function rbf_render(mixed $blocks, string $locale = 'pt'): string
    {
        $root = dirname(__DIR__, 2);
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, ['paths' => ['core' => $root . '/core'], 'admin' => ['prefix' => '/painel']]);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, ['richtext_blocks' => [
            'bignumbers' => ['label' => 'Big numbers', 'fields' => [['name' => 'a', 'type' => 'text']]],
            'video' => ['label' => 'Vídeo "curto"', 'fields' => [['name' => 'url', 'type' => 'url']]],
        ]]);
        $app = new App($config);
        $app->basePath = '';
        $_SESSION = [];
        $app->session = new \Velk\Session();
        $app->adminI18n = new \Velk\Admin\I18n($root . '/core/Admin/lang', $locale, 'en');

        $field = ['name' => 'content', 'type' => 'richtext', 'label' => 'Conteúdo'];
        if ($blocks !== null) {
            $field['blocks'] = $blocks;
        }
        return FieldRenderer::render($field, '<p>texto</p>');
    }

    /** O valor de um atributo do `<div class="richtext" …>`, já sem o escape de HTML; null se não existe. */
    function rbf_attr(string $html, string $name): ?string
    {
        if (preg_match('/<div class="richtext" data-richtext([^>]*)>/', $html, $tag) !== 1) {
            return null;
        }
        if (preg_match('/ ' . preg_quote($name, '/') . '="([^"]*)"/', $tag[1], $m) !== 1) {
            return null;
        }
        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

test('campo com blocos: a barra ganha o botão Bloco, na língua do painel', function () {
    foreach (['pt' => 'Blocos', 'en' => 'Blocks', 'es' => 'Bloques'] as $locale => $rotulo) {
        $html = rbf_render(['bignumbers'], $locale);
        check(
            str_contains($html, '<button type="button" data-cmd="block" title="' . $rotulo . '" aria-expanded="false">+ ' . $rotulo . '</button>'),
            "{$locale}: botão não encontrado"
        );
    }
});

test('campo com blocos: o editor recebe a lista, o endereço e o token', function () {
    $html = rbf_render(['video', 'bignumbers']);
    check_same(
        [['key' => 'video', 'label' => 'Vídeo "curto"'], ['key' => 'bignumbers', 'label' => 'Big numbers']],
        json_decode((string) rbf_attr($html, 'data-blocks'), true),
        'na ordem do campo, com o nome de cada bloco'
    );
    check_same('/painel/blocks', rbf_attr($html, 'data-blocks-url'));
    check_same(\Velk\App::instance()->session->csrfToken(), rbf_attr($html, 'data-blocks-csrf'));
    check(strlen((string) rbf_attr($html, 'data-blocks-csrf')) >= 32, 'token de CSRF vazio');
});

test('campo com blocos: só entra bloco declarado, uma vez cada', function () {
    $html = rbf_render(['bignumbers', 'nao_existe', 'bignumbers', 42, '../x']);
    check_same([['key' => 'bignumbers', 'label' => 'Big numbers']], json_decode((string) rbf_attr($html, 'data-blocks'), true));
});

test('campo sem blocos: nem botão, nem atributos — igual a antes', function () {
    foreach ([null, [], ['nao_existe'], 'bignumbers', ['a' => 'bignumbers']] as $blocks) {
        $html = rbf_render($blocks);
        $caso = var_export($blocks, true);
        check(!str_contains($html, 'data-cmd="block"'), "botão sobrando: {$caso}");
        check(str_contains($html, '<div class="richtext" data-richtext>'), "atributo sobrando: {$caso}");
        check(str_contains($html, 'data-cmd="blockquote"'), 'a barra de sempre continua lá');
    }
});

test('campo com blocos: o botão Bloco tem tratamento no admin.js', function () {
    // O servidor desenha o botão e o admin.js trata o clique pelo data-cmd:
    // sem o `case`, o botão não faria nada, e nada avisaria.
    check(str_contains(rbf_render(['bignumbers']), 'data-cmd="block"'), 'a barra deveria ter o comando block');

    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/core/Admin/assets/js/admin.js');
    $inicio = strpos($js, "toolbarEl.addEventListener('click'");
    $fim = strpos($js, 'updateToolbarState();', (int) $inicio);
    check($inicio !== false && $fim !== false, 'tratamento do clique da barra não encontrado no admin.js');
    check(str_contains(substr($js, (int) $inicio, (int) $fim - (int) $inicio), "case 'block':"), 'o botão Bloco não tem tratamento no admin.js');
});

test('cartão do bloco: o que acontece nos botões Editar e × não sobe para o editor', function () {
    // Os botões ficam dentro do editor. Sem o stopEvent da visão do cartão, o
    // editor trata as teclas dadas neles: Enter parte o parágrafo do cursor em
    // vez de abrir a janela, e Espaço troca o cartão que estiver selecionado
    // por um espaço (conferido no navegador).
    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/core/Admin/assets/js/admin.js');
    $inicio = strpos($js, 'const createVelkBlock = ');
    $fim = strpos($js, 'const stripDisallowedBlocks = ', (int) $inicio);
    check($inicio !== false && $fim !== false, 'o nó do bloco não foi encontrado no admin.js');
    $no = substr($js, (int) $inicio, (int) $fim - (int) $inicio);
    check(
        preg_match('/stopEvent:\s*\(event\)\s*=>\s*actions\.contains\(event\.target\)/', $no) === 1,
        'a visão do cartão não segura os eventos dos botões'
    );
});

test('cartão do bloco: alça de arrastar e × de remover, também no bloco indisponível', function () {
    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/core/Admin/assets/js/admin.js');
    $inicio = strpos($js, 'const createVelkBlock = ');
    $fim = strpos($js, 'const stripDisallowedBlocks = ', (int) $inicio);
    $no = substr($js, (int) $inicio, (int) $fim - (int) $inicio);
    check(str_contains($no, "'richtext-block__handle'"), 'sem a alça de arrastar');
    check(str_contains($no, "'richtext-block__remove'"), 'sem o botão de remover');
    // O × fica fora do `if (known && !unreadable)` do Editar: é por ele que se
    // tira do texto um bloco que o tema não tem mais.
    $editar = strpos($no, 'if (known && !unreadable) {');
    $fimEditar = strpos($no, "\n      }\n", (int) $editar);
    $remover = strpos($no, "const remove = document.createElement('button')");
    check($editar !== false && $fimEditar !== false && $remover !== false && $remover > $fimEditar, 'o × deveria valer para todo bloco');
    check(str_contains($no, 'deleteRange('), 'o × deveria tirar o nó do texto (e o Ctrl+Z, devolvê-lo)');
});

test('campo com blocos: os textos do cartão e da janela chegam ao admin.js nas três línguas', function () {
    $esperado = [
        'pt' => ['Editar', 'Bloco indisponível'],
        'en' => ['Edit', 'Unavailable block'],
        'es' => ['Editar', 'Bloque no disponible'],
    ];
    foreach ($esperado as $locale => $textos) {
        rbf_render(['bignumbers'], $locale); // sobe o App com o painel nessa língua
        ob_start();
        include dirname(__DIR__, 2) . '/core/Admin/views/layout/_js_i18n.php';
        $html = (string) ob_get_clean();
        check(preg_match('#<script type="application/json" id="velk-admin-i18n">(.*?)</script>#s', $html, $m) === 1, 'bloco de textos ausente');
        $i18n = json_decode($m[1], true);
        check_same($textos, [$i18n['js_block_edit'] ?? null, $i18n['js_block_unavailable'] ?? null], $locale);
    }
});
