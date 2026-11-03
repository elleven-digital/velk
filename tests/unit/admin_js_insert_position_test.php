<?php

declare(strict_types=1);

/**
 * Guarda dos botões Imagem e Tabela do editor de texto (admin.js): a imagem ou
 * a tabela nova nunca troca o que está selecionado nem a imagem em cuja
 * legenda está o cursor — entra depois.
 *
 * O JavaScript do painel não tem executor de testes; este arquivo lê o
 * admin.js, como o teste das janelas do painel. O comportamento em si foi
 * conferido no navegador: escolher uma segunda imagem logo depois da primeira
 * (o cursor fica na legenda vazia dela) trocava a primeira pela segunda; com
 * uma imagem ou um cartão de bloco selecionado, Imagem e Tabela o trocavam
 * pelo nó novo; e, com o cursor no meio de uma legenda com texto, a imagem
 * duplicava em volta dele.
 */

if (!function_exists('aip_slice')) {
    /** O trecho do admin.js entre dois marcos, sem o segundo. */
    function aip_slice(string $from, string $to): string
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/core/Admin/assets/js/admin.js');
        $start = strpos($js, $from);
        $end = $start === false ? false : strpos($js, $to, $start);
        check($start !== false && $end !== false, "trecho não encontrado no admin.js: {$from}");
        return substr($js, (int) $start, (int) $end - (int) $start);
    }

    /** A rotina que escolhe onde um nó novo entra. */
    function aip_position(): string
    {
        return aip_slice('const insertPosition = ', 'const gapCursorAt = ');
    }

    /** O tratamento do clique nos botões da barra do editor. */
    function aip_toolbar(): string
    {
        return aip_slice("toolbarEl.addEventListener('click'", 'updateToolbarState();');
    }

    /** Posição de `$needle` em `$haystack`; falha o teste se não houver. */
    function aip_pos(string $haystack, string $needle, string $message): int
    {
        $pos = strpos($haystack, $needle);
        check($pos !== false, $message);
        return (int) $pos;
    }
}

test('editor de texto: o botão Imagem escolhe onde a imagem entra antes de inserir', function () {
    $imagem = aip_slice("case 'image': {", "case 'block':");
    check(
        preg_match("/insertChain\\(editor, 'figure'\\)\\s*\\.insertContent\\(/", $imagem) === 1,
        'a imagem escolhida entra pelo insertChain'
    );
    // Direto no cursor, o insertContent troca o nó selecionado e a imagem de
    // legenda vazia pela imagem nova.
    check(
        preg_match('/editor\.chain\(\)\.focus\(\)\s*\.insertContent\(/', $imagem) === 0,
        'a imagem não é inserida direto no cursor'
    );
});

test('editor de texto: o botão Tabela escolhe onde a tabela entra antes de inserir', function () {
    $barra = aip_toolbar();
    check(
        preg_match("/case 'insertTable':\\s*insertChain\\(editor, 'table'\\)\\.insertTable\\(/", $barra) === 1,
        'a tabela entra pelo insertChain'
    );
    // Direto no cursor, o insertTable troca o nó selecionado pela tabela.
    check(preg_match('/\bchain\.insertTable\(/', $barra) === 0, 'a tabela não é inserida direto no cursor');
});

test('editor de texto: a imagem e a tabela entram numa linha nova quando o cursor não serve', function () {
    $chain = aip_slice('const insertChain = ', 'const cleanRichHtml = ');
    // O tipo do nó novo vai junto: a imagem e a tabela cabem num item de lista
    // e numa célula, o bloco do tema não.
    check(
        str_contains($chain, 'insertPosition(editor.state.selection, editor.schema.nodes[name])'),
        'o insertChain pergunta a posição pelo tipo do nó novo'
    );
    check(
        str_contains($chain, "return at === null ? chain : chain.insertContentAt(at, { type: 'paragraph' });"),
        'com uma posição, o insertChain abre uma linha vazia nela; sem, deixa o cursor onde está'
    );
});

test('editor de texto: o nó novo entra depois do nó selecionado e da imagem da legenda', function () {
    $rotina = aip_position();
    // O nó mais fundo em que o novo cabe: é o que mantém a imagem e a tabela
    // dentro do item de lista ou da célula em que o cursor está.
    check(str_contains($rotina, 'canReplaceWith(index, index, type)) break;'), 'a rotina procura onde o nó novo cabe');

    $legenda = aip_pos($rotina, 'for (let depth = $from.depth; depth > host; depth -= 1) {', 'a rotina procura a imagem (ou a tabela) em volta do cursor');
    check(str_contains($rotina, 'if (isolating) return after(isolating);'), 'dentro de uma imagem com legenda, o nó novo entra depois dela');

    $selecionado = aip_pos($rotina, 'if (selection.node) {', 'a rotina trata o nó selecionado, para qualquer tipo de nó novo');
    check(
        str_contains(substr($rotina, $selecionado), 'return !fits && last && $from.depth > 0 ? after($from.depth) : selection.to;'),
        'com um nó selecionado, o novo entra depois dele'
    );
    check($legenda < $selecionado, 'a legenda vem antes: uma imagem selecionada dentro de uma tabela leva o bloco para depois da tabela');
});

test('editor de texto: só o bloco do tema contorna as pontas de um parágrafo', function () {
    // A imagem e a tabela continuam entrando no cursor como sempre entraram:
    // com a regra das pontas, sairiam da lista em que o cursor está.
    $rotina = aip_position();
    check(str_contains($rotina, '{ edges = false } = {}'), 'a regra das pontas vem desligada');
    check(
        str_contains($rotina, 'if (edges && selection.empty && $from.depth > 0 && $from.parent.content.size > 0) {'),
        'a regra das pontas só vale a pedido'
    );
    $bloco = aip_slice('const insertBlock = ', 'const insertChain = ');
    check(
        str_contains($bloco, 'insertPosition(editor.state.selection, editor.schema.nodes.velkBlock, { edges: true })'),
        'o bloco do tema pede a regra das pontas'
    );
});
