<?php
/**
 * @var array $pageTemplates  page_templates from site.json
 * @var array{title:string,slug:string,template:string} $values
 * @var array<string,string> $errors
 */
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= __('pages.create_page') ?></h1>
        <p class="page-header__subtitle"><?= __('pages.new_subtitle') ?></p>
    </div>
</div>

<form method="post" class="form">
    <?= csrf_field() ?>

    <div class="form-row">
        <div class="field">
            <label class="field__label" for="title"><?= __('items.field_title') ?> <span class="field__required">*</span></label>
            <div class="field__control">
                <input class="input" type="text" name="title" id="title" value="<?= e($values['title']) ?>" required autofocus data-slug-source>
            </div>
            <?php if (!empty($errors['title'])): ?>
                <p class="field__help" style="color:var(--danger)"><?= e($errors['title']) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label class="field__label" for="slug"><?= __('pages.field_slug') ?> <span class="field__required">*</span></label>
            <div class="field__control">
                <input class="input" type="text" name="slug" id="slug" value="<?= e($values['slug']) ?>" placeholder="<?= __('pages.slug_example_placeholder') ?>" data-slug-target>
            </div>
            <p class="field__help"><?= __('pages.new_slug_help', ['slug' => $values['slug'] !== '' ? $values['slug'] : __('pages.slug_placeholder_fallback')]) ?> <?= __('pages.slug_chars_help') ?></p>
            <?php if (!empty($errors['slug'])): ?>
                <p class="field__help" style="color:var(--danger)"><?= e($errors['slug']) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="field">
        <label class="field__label" for="template"><?= __('items.field_template') ?> <span class="field__required">*</span></label>
        <div class="field__control">
            <select class="input input--select" name="template" id="template" required>
                <?php foreach ($pageTemplates as $key => $tpl): ?>
                    <option value="<?= e((string) $key) ?>" <?= $values['template'] === (string) $key ? 'selected' : '' ?>>
                        <?= e((string)($tpl['label'] ?? $key)) ?> <span class="muted">(<?= e((string)($tpl['file'] ?? '')) ?>)</span>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <p class="field__help"><?= __('pages.template_help_new') ?></p>
        <?php if (!empty($errors['template'])): ?>
            <p class="field__help" style="color:var(--danger)"><?= e($errors['template']) ?></p>
        <?php endif; ?>
    </div>

    <div class="field">
        <label class="field__label" for="parent"><?= __('pages.field_parent') ?></label>
        <div class="field__control">
            <select class="input input--select" name="parent" id="parent">
                <option value=""><?= __('pages.parent_none') ?></option>
                <?php foreach (($parentOptions ?? []) as $pk => $plabel): ?>
                    <option value="<?= e((string) $pk) ?>" <?= ($values['parent'] ?? '') === (string) $pk ? 'selected' : '' ?>>
                        <?= e((string) $plabel) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <p class="field__help"><?= __('pages.parent_help_new') ?></p>
        <?php if (!empty($errors['parent'])): ?>
            <p class="field__help" style="color:var(--danger)"><?= e($errors['parent']) ?></p>
        <?php endif; ?>
    </div>

    <div class="form-actions">
        <button class="button" type="submit"><?= __('pages.create_page') ?></button>
        <a class="button button--ghost" href="<?= e(admin_url('pages')) ?>"><?= __('common.cancel') ?></a>
    </div>
</form>
