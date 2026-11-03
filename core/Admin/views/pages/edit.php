<?php
/**
 * @var string $pageKey
 * @var array $pageConfig
 * @var \Velk\Models\Page $page
 * @var array $fieldDefs
 * @var array $seoFields
 * @var bool $isCustom
 * @var array{title:string,slug:string,template:string} $values  (only set when $isCustom)
 * @var array<string,string> $errors                              (only set when $isCustom)
 * @var array $pageTemplates                                       (only set when $isCustom)
 * @var bool $i18nEnabled
 * @var string $defaultLanguage
 * @var list<\Velk\Models\Language> $languages
 */
$isCustom = $isCustom ?? false;
$values   = $values   ?? [];
$errors   = $errors   ?? [];
$i18nEnabled = $i18nEnabled ?? false;
$defaultLanguage = $defaultLanguage ?? 'pt-BR';
$languages = $languages ?? [];
$parentOptions = $parentOptions ?? [];
$titleTrans = $page->titleTranslations ?? [];
$slugTrans  = $page->slugTranslations ?? [];
$fieldsTrans = $page->fieldsTranslations ?? [];
// Defaults for showing in non-default language tabs (so translator
// can see what they're translating). For JSON pages title = label from
// site.json; for custom pages, the user's saved title.
$defaultTitleDisplay = $isCustom ? ($values['title'] ?? '') : (string) ($pageConfig['label'] ?? $pageKey);
$defaultSlugDisplay  = $isCustom ? ($values['slug'] ?? '') : ltrim((string) ($pageConfig['url'] ?? '/' . $pageKey), '/');
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= e((string)($pageConfig['label'] ?? $pageKey)) ?></h1>
        <p class="page-header__subtitle">
            <?= __('items.field_template') ?> <code><?= e((string)($pageConfig['template'] ?? '')) ?></code>
            <?php if ($isCustom): ?>
                · <span class="badge badge--published"><?= __('pages.badge_created_by_panel') ?></span>
            <?php else: ?>
                · <span class="badge">site.json</span>
            <?php endif; ?>
        </p>
    </div>
    <?php $publicUrl = \Velk\Models\Page::pageUrl($pageKey, $pageConfig); ?>
    <?php include __DIR__ . '/../layout/_site_link_actions.php'; ?>
</div>

<form method="post" class="form" id="page-form" data-i18n-form="<?= $i18nEnabled ? '1' : '0' ?>">
    <?= csrf_field() ?>

    <?php if ($i18nEnabled && !empty($languages)): ?>
        <div class="lang-tabs" data-lang-tabs>
            <button type="button" class="lang-tabs__btn is-active" data-lang-tab="<?= e($defaultLanguage) ?>">
                <?= e(\Velk\App::instance()->i18n->labelFor($defaultLanguage)) ?>
                <span class="lang-tabs__badge"><?= __('items.default_badge') ?></span>
            </button>
            <?php foreach ($languages as $lang): ?>
                <?php
                $hasContent = !empty($titleTrans[$lang->code]) || !empty($slugTrans[$lang->code]) || !empty($fieldsTrans[$lang->code]);
                ?>
                <button type="button" class="lang-tabs__btn" data-lang-tab="<?= e($lang->code) ?>">
                    <?= e($lang->label) ?>
                    <?php if ($hasContent): ?>
                        <span class="lang-tabs__dot" title="<?= __('items.has_translation') ?>"></span>
                    <?php endif; ?>
                </button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Default language panel -->
    <div class="lang-panel is-active" data-lang-panel="<?= e($defaultLanguage) ?>">
        <?php if ($isCustom): ?>
            <div class="form-row">
                <div class="field">
                    <label class="field__label" for="title"><?= __('items.field_title') ?> <span class="field__required">*</span></label>
                    <div class="field__control">
                        <input class="input" type="text" name="title" id="title" value="<?= e($values['title']) ?>" required>
                    </div>
                    <?php if (!empty($errors['title'])): ?>
                        <p class="field__help" style="color:var(--danger)"><?= e($errors['title']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label class="field__label" for="slug"><?= __('pages.field_slug') ?> <span class="field__required">*</span></label>
                    <div class="field__control">
                        <input class="input" type="text" name="slug" id="slug" value="<?= e($values['slug']) ?>" required>
                    </div>
                    <p class="field__help"><?= __('items.url_label') ?> <code><?= e($currentUrl ?? '/' . $values['slug']) ?></code>. <?= __('pages.rename_warning') ?></p>
                    <?php if (!empty($errors['slug'])): ?>
                        <p class="field__help" style="color:var(--danger)"><?= e($errors['slug']) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="field">
                <label class="field__label" for="template"><?= __('items.field_template') ?></label>
                <div class="field__control">
                    <select class="input input--select" name="template" id="template">
                        <?php foreach ($pageTemplates as $key => $tpl): ?>
                            <option value="<?= e((string) $key) ?>" <?= $values['template'] === (string) $key ? 'selected' : '' ?>>
                                <?= e((string)($tpl['label'] ?? $key)) ?> <span class="muted">(<?= e((string)($tpl['file'] ?? '')) ?>)</span>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <p class="field__help"><?= __('pages.template_help_edit') ?></p>
                <?php if (!empty($errors['template'])): ?>
                    <p class="field__help" style="color:var(--danger)"><?= e($errors['template']) ?></p>
                <?php endif; ?>
            </div>

            <div class="field">
                <label class="field__label" for="parent"><?= __('pages.field_parent') ?></label>
                <div class="field__control">
                    <select class="input input--select" name="parent" id="parent">
                        <option value=""><?= __('pages.parent_none') ?></option>
                        <?php foreach ($parentOptions as $pk => $plabel): ?>
                            <option value="<?= e((string) $pk) ?>" <?= ($values['parent'] ?? '') === (string) $pk ? 'selected' : '' ?>>
                                <?= e((string) $plabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <p class="field__help"><?= __('pages.parent_help_edit') ?></p>
                <?php if (!empty($errors['parent'])): ?>
                    <p class="field__help" style="color:var(--danger)"><?= e($errors['parent']) ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (empty($fieldDefs)): ?>
            <div class="empty">
                <?php if ($isCustom): ?>
                    <?= __('pages.no_custom_fields_template') ?>
                <?php else: ?>
                    <?= __('pages.no_custom_fields_json') ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?= \Velk\FieldRenderer::renderFields($fieldDefs, $page->fields ?? [], 'fields') ?>

        <?php if (!empty($seoFields)): ?>
            <div class="form-seo">
                <div class="form-seo__head">
                    <h2 class="form-seo__title"><?= __('items.seo_heading') ?></h2>
                    <p class="form-seo__sub"><?= __('pages.seo_help') ?></p>
                </div>
                <?= \Velk\FieldRenderer::renderFields($seoFields, $page->fields ?? [], 'fields') ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($i18nEnabled && !empty($languages)): ?>
        <?php foreach ($languages as $lang): ?>
            <div class="lang-panel" data-lang-panel="<?= e($lang->code) ?>" hidden>
                <div class="lang-panel__note">
                    <?= __('pages.translating_note', ['lang' => $lang->label]) ?>
                    <?php if (!$isCustom): ?>
                        <?= __('pages.translating_note_json_hint') ?>
                    <?php endif; ?>
                </div>

                <div class="form-row">
                    <div class="field">
                        <label class="field__label"><?= __('items.field_title') ?></label>
                        <div class="field__control">
                            <input class="input" type="text" name="title_translations[<?= e($lang->code) ?>]" value="<?= e($titleTrans[$lang->code] ?? '') ?>" placeholder="<?= e($defaultTitleDisplay) ?>">
                        </div>
                        <p class="field__help"><?= __('items.default_value', ['value' => $defaultTitleDisplay]) ?></p>
                    </div>
                    <div class="field">
                        <label class="field__label"><?= __('pages.field_slug') ?></label>
                        <div class="field__control">
                            <input class="input" type="text" name="slug_translations[<?= e($lang->code) ?>]" value="<?= e($slugTrans[$lang->code] ?? '') ?>" placeholder="<?= e($defaultSlugDisplay) ?>">
                        </div>
                        <p class="field__help"><?= __('items.url_label') ?> <code><?= e(url(\Velk\Models\Page::fullPath($pageKey, \Velk\App::instance()->config, $lang->code), $lang->code)) ?></code></p>
                    </div>
                </div>

                <?php
                $namePrefix = 'fields_translations[' . $lang->code . ']';
                $langValues = $fieldsTrans[$lang->code] ?? [];
                ?>
                <?php if (!empty($fieldDefs)): ?>
                    <?= \Velk\FieldRenderer::renderFields($fieldDefs, $langValues, $namePrefix) ?>
                <?php endif; ?>

                <?php if (!empty($seoFields)): ?>
                    <div class="form-seo">
                        <div class="form-seo__head">
                            <h2 class="form-seo__title"><?= __('items.seo_heading') ?> — <?= e($lang->label) ?></h2>
                        </div>
                        <?= \Velk\FieldRenderer::renderFields($seoFields, $langValues, $namePrefix) ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

</form>

    <div class="form-actions">
        <button class="button" type="submit" form="page-form"><?= __('common.save') ?></button>
        <span class="muted"><?= __('pages.updated_at', ['date' => $page->updatedAt ?? '—']) ?></span>
        <?php if ($isCustom): ?>
            <span class="spacer"></span>
            <form method="post" action="<?= e(admin_url('pages/' . $page->key . '/delete')) ?>" data-confirm="<?= __('pages.confirm_delete_self') ?>" style="margin:0">
                <?= csrf_field() ?>
                <button class="button button--small button--danger" type="submit"><?= __('pages.delete_button') ?></button>
            </form>
        <?php endif; ?>
    </div>

<?php if ($i18nEnabled && !empty($languages)): ?>
<style>
.lang-tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--line); margin-bottom: 24px; }
.lang-tabs__btn {
    background: transparent; border: 0; border-bottom: 2px solid transparent;
    padding: 10px 16px; cursor: pointer; font: inherit; color: var(--ink-3);
    display: flex; align-items: center; gap: 8px;
    transition: color 120ms, border-color 120ms;
}
.lang-tabs__btn:hover { color: var(--ink-1); }
.lang-tabs__btn.is-active { color: var(--ink-1); border-bottom-color: var(--ink); font-weight: 500; }
.lang-tabs__badge {
    font-size: 10px; letter-spacing: 0.08em; text-transform: uppercase;
    color: var(--ink-4); background: var(--surface-2, #f5f5f6);
    padding: 2px 6px; border-radius: 2px;
}
.lang-tabs__dot { display: none; }
.lang-panel__note {
    background: rgba(214, 90, 38, 0.08); border-left: 3px solid #d65a26;
    padding: 12px 16px; margin-bottom: 24px; font-size: 13px; color: var(--ink-2);
}
.lang-panel[hidden] { display: none; }
</style>
<?php endif; ?>
