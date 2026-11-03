<?php
/**
 * @var string $type
 * @var array $typeConfig
 * @var \Velk\Models\Item|null $item
 * @var array $fieldDefs
 * @var array $taxonomies
 * @var array $termOptions
 * @var array $itemTerms
 * @var string $templateMode
 * @var array<string,string> $templateOptions
 * @var string $fieldsMode
 * @var string|null $selectedTemplate
 * @var bool $i18nEnabled
 * @var string $defaultLanguage
 * @var list<\Velk\Models\Language> $languages
 */
$isNew = $item === null;
$title = $item?->title ?? '';
$slug = $item?->slug ?? '';
$status = $item?->status ?? 'draft';
$template = $item?->template ?? '';
$titleTrans = $item?->titleTranslations ?? [];
$slugTrans = $item?->slugTranslations ?? [];
$fieldsTrans = $item?->fieldsTranslations ?? [];
$templateMode = $templateMode ?? 'single';
$templateOptions = $templateOptions ?? [];
$fieldsMode = $fieldsMode ?? 'global';
$needsTemplateFirst = ($templateMode === 'list' && $fieldsMode === 'per_template' && ($selectedTemplate ?? '') === '');
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= $isNew ? __('items.new', ['type' => (string)($typeConfig['label_singular'] ?? $type)]) : e($title) ?></h1>
        <p class="page-header__subtitle"><?= __('items.edit_type') ?> <code><?= e($type) ?></code></p>
    </div>
    <?php
    // Item::url() returns '' for embed-only types (has_page: false) —
    // they have no public page of their own, so "View on site" would
    // link to the site root and confuse. No URL, no button.
    // Também no rascunho: o site mostra o rascunho a quem está logado no
    // painel (FrontRouter::renderItemResponse) — é a prévia antes de publicar.
    $publicUrl = !$isNew ? $item->url() : '';
    ?>
    <?php include __DIR__ . '/../layout/_site_link_actions.php'; ?>
</div>

<form method="post" class="form" data-i18n-form="<?= $i18nEnabled ? '1' : '0' ?>">
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

    <!-- Default language panel — uses the existing input names (title, slug, fields[...]) -->
    <div class="lang-panel is-active" data-lang-panel="<?= e($defaultLanguage) ?>">
        <div class="form-meta">
            <?php // O título na linha toda; embaixo, o slug com o status (e o template). ?>
            <div class="field">
                <label class="field__label" for="title"><?= __('items.field_title') ?> <span class="field__required">*</span></label>
                <div class="field__control">
                    <input class="input" type="text" name="title" id="title" value="<?= e($title) ?>" required data-slug-source>
                </div>
            </div>
            <div class="form-row">
                <div class="field">
                    <label class="field__label" for="slug"><?= __('items.field_slug') ?></label>
                    <div class="field__control">
                        <input class="input" type="text" name="slug" id="slug" value="<?= e($slug) ?>" data-slug-target placeholder="<?= __('items.slug_placeholder') ?>">
                    </div>
                </div>
                <?php if (!$isNew): ?>
                <div class="field">
                    <label class="field__label" for="status"><?= __('items.status_label') ?></label>
                    <div class="field__control">
                        <select class="input input--select" name="status" id="status">
                            <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>><?= __('items.status_draft') ?></option>
                            <option value="published" <?= $status === 'published' ? 'selected' : '' ?>><?= __('items.status_published') ?></option>
                        </select>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($templateMode === 'list'): ?>
                    <div class="field">
                        <label class="field__label" for="template"><?= __('items.field_template') ?> <span class="field__required">*</span></label>
                        <div class="field__control">
                            <select class="input input--select" name="template" id="template" required>
                                <option value="" disabled <?= ($selectedTemplate ?? '') === '' ? 'selected' : '' ?>><?= __('items.choose_placeholder') ?></option>
                                <?php foreach ($templateOptions as $key => $label): ?>
                                    <option value="<?= e((string) $key) ?>" <?= ($selectedTemplate ?? '') === $key ? 'selected' : '' ?>><?= e((string) $label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($hierarchical ?? false): ?>
                <div class="form-row">
                    <div class="field" style="flex:1">
                        <label class="field__label" for="parent_id"><?= __('items.field_parent') ?></label>
                        <div class="field__control">
                            <select class="input input--select" name="parent_id" id="parent_id">
                                <option value=""><?= __('items.parent_none') ?></option>
                                <?php foreach (($parentOptions ?? []) as $pid => $ptitle): ?>
                                    <option value="<?= e((string) $pid) ?>" <?= ($item?->parentId ?? null) === (int) $pid ? 'selected' : '' ?>>
                                        <?= e((string) $ptitle) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <p class="field__help"><?= __('items.parent_help') ?></p>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($needsTemplateFirst): ?>
            <p class="field__help"><?= __('items.choose_template_help') ?></p>
        <?php else: ?>
            <?php if (!empty($fieldDefs)): ?>
                <?= \Velk\FieldRenderer::renderFields($fieldDefs, $item?->fields ?? [], 'fields') ?>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!empty($taxonomies)): ?>
            <?php foreach ($taxonomies as $taxonomy): ?>
                <?php $taxConfig = $config->taxonomy((string) $taxonomy); ?>
                <div class="section-header">
                    <h2 class="section-header__title"><?= e((string)($taxConfig['label'] ?? $taxonomy)) ?></h2>
                </div>
                <div class="field">
                    <div class="field__control">
                        <?php if (empty($termOptions[$taxonomy])): ?>
                            <p class="muted"><?= __('items.no_terms_prefix') ?> <a href="<?= e(admin_url('taxonomies/' . $taxonomy . '/new')) ?>"><?= __('items.no_terms_link') ?></a>.</p>
                        <?php else: ?>
                            <div style="display:flex;flex-wrap:wrap;gap:8px">
                                <?php foreach ($termOptions[$taxonomy] as $term): ?>
                                    <?php $checked = in_array($term->id, $itemTerms[$taxonomy] ?? [], true); ?>
                                    <label class="boolean-field" style="border:1px solid var(--color-border);padding:6px 10px">
                                        <input type="checkbox" name="terms[<?= e((string)$taxonomy) ?>][]" value="<?= e((string)$term->id) ?>" <?= $checked ? 'checked' : '' ?>>
                                        <span><?= e($term->name) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!empty($seoFields)): ?>
            <div class="form-seo">
                <div class="form-seo__head">
                    <h2 class="form-seo__title"><?= __('items.seo_heading') ?></h2>
                    <p class="form-seo__sub"><?= __('items.seo_help') ?></p>
                </div>
                <?= \Velk\FieldRenderer::renderFields($seoFields, $item?->fields ?? [], 'fields') ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($i18nEnabled && !empty($languages)): ?>
        <?php foreach ($languages as $lang): ?>
            <div class="lang-panel" data-lang-panel="<?= e($lang->code) ?>" hidden>
                <div class="lang-panel__note">
                    <?= __('items.translating_note', ['lang' => $lang->label]) ?>
                </div>

                <div class="form-meta">
                    <div class="field">
                        <label class="field__label"><?= __('items.field_title') ?></label>
                        <div class="field__control">
                            <input class="input" type="text" name="title_translations[<?= e($lang->code) ?>]" value="<?= e($titleTrans[$lang->code] ?? '') ?>" placeholder="<?= e($title) ?>">
                        </div>
                        <p class="field__help"><?= __('items.default_value', ['value' => $title]) ?></p>
                    </div>
                    <div class="form-row">
                        <div class="field">
                            <label class="field__label"><?= __('items.field_slug') ?></label>
                            <div class="field__control">
                                <input class="input" type="text" name="slug_translations[<?= e($lang->code) ?>]" value="<?= e($slugTrans[$lang->code] ?? '') ?>" placeholder="<?= e($slug) ?>">
                            </div>
                            <?php
                            // O endereço em que o item responde no idioma: o
                            // slug traduzido ou, sem ele, o padrão — com a
                            // cadeia de pais num tipo hierárquico.
                            $cfg = \Velk\App::instance()->config;
                            $urlHint = $item !== null && $cfg->isItemTypeHierarchical($type)
                                ? \Velk\Models\Item::fullPath($item, $cfg, $lang->code)
                                : '/' . trim($cfg->itemTypeSlugIn($type, $lang->code), '/') . '/' . ($item?->slugIn($lang->code) ?? '...');
                            ?>
                            <p class="field__help"><?= __('items.url_label') ?> <code><?= e(url($urlHint, $lang->code)) ?></code></p>
                        </div>
                    </div>
                </div>

                <?php
                // Render with the translation namePrefix so input names
                // become fields_translations[en][title]. Group/tabs
                // organization carries over to the translation panels
                // automatically (renderFields handles the containers).
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

    <div class="form-actions">
        <?php if ($isNew): ?>
            <button class="button" type="submit" name="status" value="published"><?= __('common.create') ?></button>
            <button class="button button--ghost" type="submit" name="status" value="draft"><?= __('items.save_draft') ?></button>
        <?php else: ?>
            <button class="button" type="submit"><?= __('common.save') ?></button>
        <?php endif; ?>
        <a class="button button--ghost" href="<?= e(admin_url('items/' . $type)) ?>"><?= __('common.cancel') ?></a>
        <span class="spacer"></span>
        <?php if (!$isNew): ?>
            <span class="muted"><?= __('items.updated_at', ['date' => $item->updatedAt]) ?></span>
        <?php endif; ?>
    </div>
</form>

<?php if ($i18nEnabled && !empty($languages)): ?>
<style>
.lang-tabs {
    display: flex;
    gap: 4px;
    border-bottom: 1px solid var(--line);
    margin-bottom: 24px;
}
.lang-tabs__btn {
    background: transparent;
    border: 0;
    border-bottom: 2px solid transparent;
    padding: 10px 16px;
    cursor: pointer;
    font: inherit;
    color: var(--ink-3);
    display: flex;
    align-items: center;
    gap: 8px;
    transition: color 120ms, border-color 120ms;
}
.lang-tabs__btn:hover { color: var(--ink-1); }
.lang-tabs__btn.is-active { color: var(--ink-1); border-bottom-color: var(--ink); font-weight: 500; }
.lang-tabs__badge {
    font-size: 10px;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--ink-4);
    background: var(--surface-2, #f5f5f6);
    padding: 2px 6px;
    border-radius: 2px;
}
.lang-tabs__dot { display: none; }
.lang-panel__note {
    background: rgba(214, 90, 38, 0.08);
    border-left: 3px solid #d65a26;
    padding: 12px 16px;
    margin-bottom: 24px;
    font-size: 13px;
    color: var(--ink-2);
}
.lang-panel[hidden] { display: none; }
</style>
<?php endif; ?>
