<?php
/**
 * Os botões do topo de uma tela de conteúdo (item, página, termo) que levam
 * ao endereço público: "Ver no site" e copiar o link — só o ícone; o admin.js
 * (initCopyLink) copia o endereço completo e troca o ícone por um ✓ por um
 * instante. Sem endereço (tipo ou taxonomia sem página própria), nada.
 *
 * @var string $publicUrl
 */
if (($publicUrl ?? '') === '') return;
?>
<div class="page-header__actions">
    <a class="button button--ghost" href="<?= e($publicUrl) ?>" target="_blank" rel="noopener"><?= __('items.view_on_site') ?></a>
    <button type="button" class="button button--ghost button--icon copy-link" data-copy-link="<?= e($publicUrl) ?>"
            data-copied-label="<?= __('items.link_copied') ?>" title="<?= __('items.copy_link') ?>" aria-label="<?= __('items.copy_link') ?>">
        <svg class="copy-link__copy" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
        <svg class="copy-link__done" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>
    </button>
</div>
