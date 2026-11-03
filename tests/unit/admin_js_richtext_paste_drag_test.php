<?php

declare(strict_types=1);

/**
 * Guardas do editor de texto (admin.js) para mover um bloco: recortar e colar,
 * e arrastar o cartão dentro de uma linha de repetidor ou flexível.
 *
 * O JavaScript do painel não tem executor de testes; este arquivo lê o
 * admin.js, como os outros testes de guarda dele. O comportamento foi
 * conferido no navegador: sem isto, recortar e colar um bloco indisponível o
 * apagava, e o cartão não saía do lugar dentro de uma linha de repetidor.
 */

if (!function_exists('ajp_slice')) {
    /** O trecho do admin.js entre dois marcos, sem o segundo. */
    function ajp_slice(string $from, string $to): string
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/core/Admin/assets/js/admin.js');
        $start = strpos($js, $from);
        $end = $start === false ? false : strpos($js, $to, $start);
        check($start !== false && $end !== false, "trecho não encontrado no admin.js: {$from}");
        return substr($js, (int) $start, (int) $end - (int) $start);
    }
}

test('editor de texto: colar aceita também os blocos que já estavam no texto ao abrir', function () {
    // Um bloco indisponível (fora do site.json ou da lista do campo) é
    // movido por recortar e colar; o filtro de colagem não pode apagá-lo.
    $init = ajp_slice('const initRichText = ', 'const editor = new Editor({');
    check(preg_match('/const pasteable = new Set\(blockLabels\.keys\(\)\)/', $init) === 1, 'a lista do que pode ser colado parte dos blocos do campo');

    $editor = ajp_slice('const editor = new Editor({', 'onSelectionUpdate:');
    check(str_contains($editor, 'transformPastedHTML: (html) => stripDisallowedBlocks(html, pasteable)'), 'a colagem filtra pela lista que inclui os blocos do texto');

    $depois = ajp_slice('const editor = new Editor({', 'const updateToolbarState = ');
    check(
        preg_match("/editor\\.state\\.doc\\.descendants\\(\\(node\\) => \\{[^}]*velkBlock[^}]*pasteable\\.add\\(node\\.attrs\\.type\\)/s", $depois) === 1,
        'os blocos do texto ao abrir entram na lista do que pode ser colado'
    );
});

test('linha de repetidor ou flexível: arrastar o cartão de um bloco fica com o editor', function () {
    $drag = ajp_slice('const makeRowsSortable = ', "row.addEventListener('dragend'");
    $cartao = strpos($drag, ".closest('.ProseMirror [draggable=\"true\"]')) return;");
    check($cartao !== false, 'o arrasto que nasce num nó arrastável do editor é deixado para ele');
    check($cartao < (int) strpos($drag, 'e.preventDefault();'), 'antes de a linha cancelar o arrasto');
});
