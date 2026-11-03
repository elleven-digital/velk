<?php
/**
 * O aviso do modo da política de conteúdo (CSP) do site público.
 *
 * No estrito — e no ensaio dele, o `report` — parte do que se cola nesta
 * tela deixa de rodar: código com `on…=` e `document.write`, e um formulário
 * que envia para outro site sem estar no `security.csp.allow.form` do
 * `site.json`. Quem cola o código precisa saber disso aqui, e não pelo
 * console do navegador.
 *
 * @var array{mode: string} $csp  PublicCsp::settings()
 */
if ($csp['mode'] !== 'strict' && $csp['mode'] !== 'report') {
    return;
}
?>
<div class="flash flash--warning">
    <div>
        <?= $csp['mode'] === 'strict' ? __('scripts.csp_strict') : __('scripts.csp_report') ?>
    </div>
</div>
