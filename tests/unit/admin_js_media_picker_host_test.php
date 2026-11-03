<?php

declare(strict_types=1);

/**
 * A biblioteca de mídia abre por cima de tudo, na página do painel — também
 * quando quem pede é a janela de um bloco (um iframe). Aberta dentro dela, a
 * biblioteca ficava presa ao tamanho da janela: uma janela dentro da outra.
 *
 * Quem abre é a janela de mais acima que roda o admin.js (`pickerHost()`), e
 * a escolha volta pelo `__velkPickerCallback` dela — é a ela que a página da
 * biblioteca responde, como `window.parent` — com o callback do campo que
 * pediu. Com as duas janelas abertas na mesma página, Esc fecha só a de cima,
 * e fechar a biblioteca não destrava a rolagem da página de trás.
 *
 * O JavaScript do painel não tem executor de testes; este arquivo lê o
 * admin.js. O comportamento foi conferido no navegador: imagem, galeria,
 * arquivo e imagem do editor de texto dentro da janela de um bloco.
 */

if (!function_exists('amp_js')) {
    function amp_js(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/core/Admin/assets/js/admin.js');
    }

    function amp_slice(string $from, string $to): string
    {
        $js = amp_js();
        $start = strpos($js, $from);
        $end = $start === false ? false : strpos($js, $to, $start);
        check($start !== false && $end !== false, "trecho não encontrado no admin.js: {$from}");
        return substr($js, (int) $start, (int) $end - (int) $start);
    }
}

test('biblioteca: a janela de cima a abre, e as de dentro a alcançam', function () {
    check(str_contains(amp_js(), 'window.__velkOpenModal = openModal;'), 'o openModal fica à vista das janelas de dentro');
    $host = amp_slice('const pickerHost = () => {', 'const openMediaPicker = ');
    check(
        preg_match('/while \(host\.parent !== host && typeof host\.parent\.__velkOpenModal === \'function\'\) host = host\.parent;/', $host) === 1,
        'sobe enquanto a janela de cima roda o admin.js'
    );
    check(str_contains($host, 'catch'), 'uma janela de outra origem por cima não quebra a abertura');
});

test('biblioteca: o callback fica na janela que a abre e sai ao fechar', function () {
    $open = amp_slice('const openMediaPicker = ', '// ── Image field');
    check(str_contains($open, 'host.__velkPickerCallback = onPick;'), 'o callback do campo vai para a janela que abre a biblioteca');
    check(str_contains($open, 'return host.__velkOpenModal({'), 'quem abre é o openModal da janela de cima');
    check(
        str_contains($open, 'if (host.__velkPickerCallback === onPick) delete host.__velkPickerCallback;'),
        'fechar desliga o callback, e só o próprio'
    );
});

test('biblioteca: todo campo a abre pelo openMediaPicker', function () {
    // Um campo que ainda gravasse `window.__velkPickerCallback` e chamasse o
    // openModal direto abriria a biblioteca dentro da janela do bloco.
    $js = amp_js();
    check(substr_count($js, 'openMediaPicker({') === 4, 'imagem, arquivo, galeria e imagem do editor');
    check(preg_match('/window\.__velkPickerCallback\s*=/', $js) === 0, 'ninguém mais grava o callback por fora');
    check(!str_contains($js, "openModal({\n      title: t('js_gallery_add_title'"), 'a galeria não abre mais por conta própria');
});

test('janelas empilhadas: Esc fecha só a de cima, e a rolagem segue travada', function () {
    $modal = amp_slice('const openModal = ', 'window.__velkOpenModal = openModal;');
    check(
        str_contains($modal, "if ([...document.querySelectorAll('body > .modal')].pop() !== modal) return;"),
        'Esc com duas janelas abertas fecharia as duas'
    );
    check(
        str_contains($modal, "if (!document.querySelector('body > .modal')) document.body.style.overflow = '';"),
        'fechar a biblioteca com a janela do bloco aberta destravaria a rolagem da página'
    );
    check(!preg_match("/modal\.remove\(\);\s*document\.body\.style\.overflow = '';/", $modal), 'sem destravar incondicional');
});

test('janelas empilhadas: a de baixo some enquanto a de cima está aberta', function () {
    // Duas sombras escureciam a página o dobro, e a janela de baixo aparecia
    // nas bordas quando a de cima era menor (conferido no navegador).
    $modal = amp_slice('const openModal = ', 'window.__velkOpenModal = openModal;');
    $abre = strpos($modal, "covered.forEach((m) => m.classList.add('modal--covered'));");
    $anexa = strpos($modal, 'document.body.appendChild(modal);');
    check($abre !== false && $anexa !== false && $abre < $anexa, 'as janelas abertas somem antes de a nova entrar');
    check(str_contains($modal, "modal.classList.add('modal--stacked')"), 'a nova sabe que está por cima');
    check(str_contains(amp_slice('const close = () => {', "modal.querySelectorAll('[data-modal-close]')"), "covered.forEach((m) => m.classList.remove('modal--covered'));"), 'ao fechar, a de baixo volta');

    $css = (string) file_get_contents(dirname(__DIR__, 2) . '/core/Admin/assets/css/admin.css');
    // visibility, não display: o iframe da janela de baixo guarda tamanho e
    // rolagem, e volta como estava.
    check(preg_match('/\.modal--covered\s*\{\s*visibility:\s*hidden;\s*\}/', $css) === 1, 'a janela de baixo fica invisível, sem sair do layout');
    check(preg_match('/\.modal--stacked \.modal__overlay\s*\{\s*animation:\s*none;\s*\}/', $css) === 1, 'a sombra de cima entra cheia, sem a página clarear no fade');
});

test('galeria: a seleção é a galeria; "Selecionar" a deixa igual, e o × cancela', function () {
    // Antes, cada clique adicionava a imagem na hora, o × fechava sem parecer
    // salvar, e havia duas marcas ("já na galeria" e a do clique). Agora a
    // biblioteca abre com a galeria selecionada, há uma marca só, e o
    // "Selecionar" deixa a galeria igual à seleção — o que saiu, sai.
    $open = amp_slice('const openMediaPicker = ', '// ── Image field');
    check(str_contains($open, 'const selection = multiple ? new host.Array() : null;'), 'a seleção nasce na janela que abre a biblioteca');
    check(str_contains($open, 'selected.forEach((data) => selection.push(host.JSON.parse(JSON.stringify(data))));'), 'e já com o que estava selecionado');
    check(str_contains($open, 'if (selection && host.__velkPickerSelection === selection) delete host.__velkPickerSelection;'), 'fechar desfaz a seleção — o × não muda nada');

    $galeria = amp_slice('const initGalleryField = ', '// ── Flexible content field');
    check(str_contains($galeria, 'multiple: true,') && str_contains($galeria, 'selected: current,'), 'a galeria abre a biblioteca com as imagens dela selecionadas');
    check(!str_contains($galeria, 'selected='), 'sem a marca do servidor');
    check(str_contains($galeria, "if (chosen.has(id)) present.add(id);\n          else el.remove();"), 'o que foi tirado sai da galeria');
    check(str_contains($galeria, 'if (present.has(id) || data.is_image === false) return;'), 'o que seguiu selecionado fica onde estava, repetidas inclusive — só as novas entram, no fim');
    check(str_contains($galeria, 'modal.close();'), 'e a biblioteca fecha');

    $grade = amp_slice('const bindGridItem = ', '// ── Media search');
    check(str_contains($grade, "if (isPicker && pickerSelection()) {\n      // Escolhendo várias: o clique seleciona ou tira; o \"Selecionar\" entrega.\n      togglePick(item);"), 'o clique só seleciona ou tira');
    check(!str_contains(amp_js(), 'is-in-gallery'), 'uma marca só');
    check(str_contains($grade, 'window.parent.__velkPickerCallback(selection.slice());'), 'o "Selecionar" entrega a lista inteira');
    check(str_contains($grade, 'disabled = count === 0 && !(selection.startCount > 0);'), 'tirar tudo de uma galeria que tinha imagens também se confirma');
});

test('galeria: a contagem é de itens, e inclui o que já estava', function () {
    $i18n = (string) file_get_contents(dirname(__DIR__, 2) . '/core/Admin/lang/pt.php');
    check(str_contains($i18n, "'fields.js_picker_many'          => '{n} itens selecionados',"), 'itens, não imagens');
    check(str_contains($i18n, "'fields.js_picker_none'          => 'Nenhum item selecionado',"), 'nenhum item');
    $grade = amp_slice('const refreshPickMarks = ', 'const togglePick = ');
    check(str_contains($grade, 'const count = selection.length;'), 'conta a seleção inteira');
});

test('galeria: as marcas sobrevivem à busca e à troca de página', function () {
    // A seleção mora na janela de cima, não na página da biblioteca: a busca
    // refaz a grade, e trocar de página recarrega o iframe.
    $busca = amp_slice('const initMediaSearch = ', '// ── ');
    check(str_contains($busca, 'refreshPickMarks();'), 'a busca refaz as marcas');
    check(str_contains(amp_js(), "bootEach('[data-media-grid]', initPicker);"), 'cada página da biblioteca monta o rodapé e as marcas');

    $upload = amp_slice('const initMediaUploader = ', 'const prependToGrid = ');
    check(str_contains($upload, 'if (tile) togglePick(tile);'), 'o arquivo enviado entra marcado, sem ser entregue');
});

test('galeria: o rodapé e as marcas têm estilo', function () {
    $css = (string) file_get_contents(dirname(__DIR__, 2) . '/core/Admin/assets/css/admin.css');
    check(preg_match('/\.media-grid__item\.is-picked::after\s*\{[^}]*background:\s*var\(--ink\) url\("data:image\/svg\+xml/', $css) === 1, 'o ✓ num círculo preto');
    check(preg_match('/\.media-grid__item\.is-picked \.media-grid__tile\s*\{\s*border-color:\s*var\(--ink\);\s*box-shadow:\s*0 0 0 1px var\(--ink\);\s*\}/', $css) === 1, 'a borda preta de 2px: a do tile e mais 1px por fora');
    check(preg_match('/\.picker-footer\s*\{[^}]*position:\s*sticky;[^}]*bottom:\s*0;/', $css) === 1, 'o rodapé preso no pé da janela');
    check(preg_match('/\.picker-footer \.button:disabled\s*\{[^}]*opacity:/', $css) === 1, '"Selecionar" apagado sem nada marcado');
});

test('seletor de uma: a mídia atual do campo aparece marcada', function () {
    // Trocar a capa abria a biblioteca sem dizer qual era a atual.
    $open = amp_slice('const openMediaPicker = ', '// ── Image field');
    check(str_contains($open, 'if (currentId) host.__velkPickerCurrent = currentId;'), 'a atual vai para a janela que abre a biblioteca');
    check(str_contains($open, 'if (currentId && host.__velkPickerCurrent === currentId) delete host.__velkPickerCurrent;'), 'e sai ao fechar');
    $imagem = amp_slice('const initImageField = ', '// ── File field');
    $arquivo = amp_slice('const initFileField = ', '// ── Item field');
    check(str_contains($imagem, 'current: input.value,'), 'o campo de imagem passa a atual');
    check(str_contains($arquivo, 'current: input.value,'), 'o campo de arquivo passa a atual');
    $marcas = amp_slice('const refreshPickMarks = ', 'const togglePick = ');
    check(str_contains($marcas, 'new Set([current])'), 'a página da biblioteca a marca, com a marca da galeria');
});

test('revisão 3.10: upload na galeria nunca entrega um item sozinho', function () {
    // A galeria lê o que recebe como a seleção inteira: um item sozinho
    // tiraria todas as outras imagens dela.
    $upload = amp_slice('const initMediaUploader = ', 'const prependToGrid = ');
    check(str_contains($upload, "if (fits && pickerSelection()) {\n          // Nunca entrega um item sozinho aqui"), 'escolhendo várias, o upload só marca');
    check(str_contains($upload, '          if (tile) togglePick(tile);'), 'e só se o tile existe');
});

test('revisão 3.10: Esc com o foco na biblioteca fecha a janela', function () {
    $picker = amp_slice('const initPicker = ', '// ── Media search');
    check(str_contains($picker, "window.parent.document.dispatchEvent(new window.parent.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));"), 'o Esc vai para a página que abriu a janela');
    check(str_contains($picker, "e.target.type === 'search' && e.target.value !== ''"), 'na busca com texto, o Esc só limpa o campo');
});

test('revisão 3.10: o ouvinte do Esc sai junto com a janela', function () {
    $close = amp_slice('const close = () => {', "modal.querySelectorAll('[data-modal-close]')");
    check(str_contains($close, "document.removeEventListener('keydown', onKey);"), 'fechar pelo × ou por uma escolha também tira o ouvinte');
    check(!str_contains(amp_js(), 'modal--expanded'), 'sem a expansão de janela aninhada, que ninguém mais usa');
});
