<?php
/**
 * @var \Velk\Models\Language|null $language
 * @var array{code:string,label:string,url_prefix:string,sort_order:string,enabled:bool} $values
 * @var array<string,string> $errors
 */
$isNew = $language === null;
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= $isNew ? __('languages.new_title') : e($language->label) ?></h1>
        <p class="page-header__subtitle">
            <?= $isNew ? __('languages.new_subtitle') : __('languages.edit_subtitle') ?>
        </p>
    </div>
</div>

<form method="post" class="form" id="language-form">
    <?= csrf_field() ?>

    <div class="form-row">
        <div class="field">
            <label class="field__label" for="code"><?= __('languages.field_code') ?> <span class="field__required">*</span></label>
            <div class="field__control">
                <input class="input" type="text" name="code" id="code" value="<?= e($values['code']) ?>" required <?= !$isNew ? 'readonly' : 'autofocus' ?>>
            </div>
            <p class="field__help"><?= __('languages.code_help') ?></p>
            <?php if (!empty($errors['code'])): ?>
                <p class="field__help" style="color:var(--danger)"><?= e($errors['code']) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label class="field__label" for="label"><?= __('forms.col_name') ?> <span class="field__required">*</span></label>
            <div class="field__control">
                <input class="input" type="text" name="label" id="label" value="<?= e($values['label']) ?>" placeholder="<?= __('languages.label_placeholder') ?>" required>
            </div>
            <p class="field__help"><?= __('languages.field_label_help') ?></p>
            <?php if (!empty($errors['label'])): ?>
                <p class="field__help" style="color:var(--danger)"><?= e($errors['label']) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="form-row">
        <div class="field">
            <label class="field__label" for="url_prefix"><?= __('languages.field_url_prefix') ?></label>
            <div class="field__control">
                <input class="input" type="text" name="url_prefix" id="url_prefix" value="<?= e($values['url_prefix']) ?>" placeholder="/en">
            </div>
            <p class="field__help"><?= __('languages.url_prefix_help') ?></p>
            <?php if (!empty($errors['url_prefix'])): ?>
                <p class="field__help" style="color:var(--danger)"><?= e($errors['url_prefix']) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label class="field__label" for="sort_order"><?= __('languages.field_sort_order') ?></label>
            <div class="field__control">
                <input class="input" type="number" name="sort_order" id="sort_order" value="<?= e($values['sort_order']) ?>">
            </div>
            <p class="field__help"><?= __('languages.sort_order_help') ?></p>
        </div>
    </div>

    <div class="field">
        <label class="boolean-field">
            <input type="checkbox" name="enabled" value="1" <?= !empty($values['enabled']) ? 'checked' : '' ?>>
            <span><?= __('languages.field_enabled') ?></span>
        </label>
    </div>

</form>

    <div class="form-actions">
        <button class="button" type="submit" form="language-form"><?= $isNew ? __('languages.create_button') : __('members.save_changes') ?></button>
        <a class="button button--ghost" href="<?= e(admin_url('languages')) ?>"><?= __('common.cancel') ?></a>
        <?php if (!$isNew): ?>
            <span class="spacer"></span>
            <form method="post" action="<?= e(admin_url('languages/' . $language->code . '/delete')) ?>" data-confirm="<?= __('languages.confirm_delete_self') ?>" style="margin:0">
                <?= csrf_field() ?>
                <button class="button button--small button--danger" type="submit"><?= __('common.delete') ?></button>
            </form>
        <?php endif; ?>
    </div>
