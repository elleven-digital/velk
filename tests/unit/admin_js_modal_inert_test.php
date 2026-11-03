<?php

declare(strict_types=1);

/**
 * Guarda do openModal (admin.js): enquanto uma janela do painel está aberta,
 * o que está atrás dela fica inerte — sem foco, sem Tab, e o que for digitado
 * não chega lá — e, ao fechar, o foco volta para onde estava.
 *
 * O JavaScript do painel não tem executor de testes; este arquivo lê o
 * admin.js, como o teste do boot(). O comportamento em si foi conferido no
 * navegador: sem o fundo inerte, uma tecla digitada com a janela do bloco
 * aberta caía no editor de texto de trás e, com um cartão selecionado, o
 * trocava; e, com o fundo inerte mas sem devolver o foco, fechar a biblioteca
 * de mídia deixava o foco no <body>, não no botão que a abriu.
 */

if (!function_exists('ami_js')) {
    function ami_js(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/core/Admin/assets/js/admin.js');
    }

    /** O trecho do admin.js entre dois marcos, sem o segundo. */
    function ami_slice(string $from, string $to): string
    {
        $js = ami_js();
        $start = strpos($js, $from);
        $end = $start === false ? false : strpos($js, $to, $start);
        check($start !== false && $end !== false, "trecho não encontrado no admin.js: {$from}");
        return substr($js, (int) $start, (int) $end - (int) $start);
    }

    /** O corpo do openModal, do `const openModal` até o `return` dele. */
    function ami_open_modal(): string
    {
        return ami_slice('const openModal = ', 'return { close');
    }

    /** O corpo do close() do openModal. */
    function ami_close(): string
    {
        return ami_slice('const close = () => {', "modal.querySelectorAll('[data-modal-close]')");
    }

    /** Posição de `$needle` em `$haystack`; falha o teste se não houver. */
    function ami_pos(string $haystack, string $needle, string $message): int
    {
        $pos = strpos($haystack, $needle);
        check($pos !== false, $message);
        return (int) $pos;
    }
}

test('janelas do painel: o que está atrás fica inerte enquanto a janela está aberta', function () {
    $body = ami_open_modal();
    check(
        preg_match('/document\.body\.children\b[^;]*el !== modal/', $body) === 1,
        'o fundo é tudo o que está no <body>, menos a janela'
    );
    check(str_contains($body, 'el.inert = true'), 'ao abrir, o fundo fica inerte');
});

test('janelas do painel: ao fechar, o fundo volta a responder', function () {
    $close = ami_close();
    $volta = ami_pos($close, 'el.inert = false', 'o close() devolve o fundo');
    // Antes do onClose: é ali que quem abriu a janela devolve o foco ao editor
    // de texto, e num elemento inerte o focus() não faz nada.
    check($volta < ami_pos($close, 'onClose()', 'o close() chama o onClose'), 'o fundo volta antes do onClose');
});

test('janelas do painel: quem tinha o foco o perde na hora de abrir', function () {
    // O navegador só tira o foco de um elemento que ficou inerte no quadro
    // seguinte (e nem todo navegador foi conferido fazendo isso): o openModal
    // não espera por ele.
    $body = ami_open_modal();
    ami_pos($body, 'opener.blur', 'ao abrir, o openModal tira o foco de quem o tinha');
});

test('janelas do painel: ao fechar, o foco volta para quem o tinha', function () {
    $body = ami_open_modal();
    // Lido antes do blur(): depois dele quem tem o foco é o <body>, e não
    // haveria a quem devolver.
    $opener = ami_pos($body, 'const opener = document.activeElement;', 'o openModal guarda quem tinha o foco');
    check($opener < ami_pos($body, 'opener.blur', 'ao abrir, o openModal tira o foco de quem o tinha'), 'guardado antes de tirar o foco');

    $close = ami_close();
    $foco = ami_pos($close, 'opener.focus', 'o close() devolve o foco');
    check(ami_pos($close, 'el.inert = false', 'o close() devolve o fundo') < $foco, 'o foco volta depois de o fundo voltar');
    check($foco < ami_pos($close, 'onClose()', 'o close() chama o onClose'), 'o foco volta antes do onClose, que pode levá-lo ao editor');
});

test('janelas do editor de texto: ao fechar, o foco volta para o editor', function () {
    // As duas janelas que o editor abre: a do bloco e a biblioteca de mídia do
    // botão de imagem. O clique na barra pedia o foco do editor para o quadro
    // seguinte; com o editor inerte, esse pedido não tem efeito.
    $janelas = [
        'a janela do bloco' => ami_slice('const openBlockWindow = ', 'const editBlock = '),
        'a biblioteca de mídia do botão de imagem' => ami_slice("case 'image': {", "case 'block':"),
    ];
    foreach ($janelas as $nome => $trecho) {
        $onClose = ami_pos($trecho, 'onClose: () =>', "{$nome} tem onClose");
        check(str_contains(substr($trecho, $onClose), 'editor.view.focus()'), "{$nome} devolve o foco ao editor ao fechar");
    }
});

test('janela de um cartão: ao fechar, o cursor sai de cima do cartão', function () {
    // Os dois cliques que abrem a janela deixam o cartão selecionado (o botão
    // Editar não). Com o foco de volta no editor, a tecla seguinte o trocaria
    // pelo que fosse digitado — salvando ou cancelando.
    $trecho = ami_slice('const openBlockWindow = ', 'const editBlock = ');
    $onClose = ami_pos($trecho, 'onClose: () => {', 'a janela do bloco tem onClose');
    check(str_contains(substr($trecho, $onClose), 'cursorAfterBlock(editor)'), 'o onClose da janela tira o cursor de cima do cartão');
});
