<?php
/**
 * O formulário de um bloco do tema, dentro da janela aberta pelo editor de
 * texto. Os campos são os do `site.json` (richtext_blocks.<chave>.fields), com o
 * prefixo `block`; salvar manda para `blocks/save`. Sem título na página: o
 * nome do bloco já está no cabeçalho da janela.
 *
 * @var string $type
 * @var string $label
 * @var array<int, mixed> $fieldDefs
 * @var array<string, mixed> $values
 */
?>
<form method="post" action="<?= e(admin_url('blocks/save')) ?>" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="type" value="<?= e($type) ?>">
    <?= \Velk\FieldRenderer::renderFields($fieldDefs, $values, 'block') ?>
    <div class="form-actions">
        <button class="button" type="submit"><?= __('blocks.save') ?></button>
    </div>
</form>
