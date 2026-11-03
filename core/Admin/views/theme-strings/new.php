<?php
/**
 * @var array{original:string,context:string} $values
 * @var array<string,string> $errors
 */
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= __('theme_strings.new_title') ?></h1>
        <p class="page-header__subtitle"><?= __('theme_strings.new_subtitle') ?></p>
    </div>
</div>

<form method="post" class="form">
    <?= csrf_field() ?>

    <div class="field">
        <label class="field__label" for="original"><?= __('theme_strings.field_original') ?> <span class="field__required">*</span></label>
        <div class="field__control">
            <textarea class="input input--textarea" name="original" id="original" rows="3" required autofocus><?= e($values['original']) ?></textarea>
        </div>
        <p class="field__help"><?= __('theme_strings.original_help') ?></p>
        <?php if (!empty($errors['original'])): ?>
            <p class="field__help" style="color:var(--danger)"><?= e($errors['original']) ?></p>
        <?php endif; ?>
    </div>

    <div class="field">
        <label class="field__label" for="context"><?= __('theme_strings.field_context') ?></label>
        <div class="field__control">
            <input class="input" type="text" name="context" id="context" value="<?= e($values['context']) ?>">
        </div>
        <p class="field__help"><?= __('theme_strings.context_help') ?></p>
    </div>

    <div class="form-actions">
        <button class="button" type="submit"><?= __('theme_strings.register_button') ?></button>
        <a class="button button--ghost" href="<?= e(admin_url('translations')) ?>"><?= __('common.cancel') ?></a>
    </div>
</form>
