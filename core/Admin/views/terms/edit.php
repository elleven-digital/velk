<?php
/**
 * @var string $taxonomy
 * @var array $taxonomyConfig
 * @var \Velk\Models\Term|null $term
 * @var array $fieldDefs
 * @var string $templateMode
 * @var array<string,string> $templateOptions
 * @var string $fieldsMode
 * @var string|null $selectedTemplate
 * @var array<int,string> $parentOptions  id => name (already excludes self+descendants)
 * @var bool $hierarchical
 * @var bool $i18nEnabled
 * @var string $defaultLanguage
 * @var list<\Velk\Models\Language> $languages
 */
$isNew = $term === null;
$hierarchical = $hierarchical ?? !empty($taxonomyConfig['hierarchical']);
$parentOptions = $parentOptions ?? [];
$i18nEnabled = $i18nEnabled ?? false;
$defaultLanguage = $defaultLanguage ?? 'pt-BR';
$languages = $languages ?? [];
$nameTrans = $term?->nameTranslations ?? [];
$slugTrans = $term?->slugTranslations ?? [];
$templateMode = $templateMode ?? 'single';
$templateOptions = $templateOptions ?? [];
$fieldsMode = $fieldsMode ?? 'global';
$needsTemplateFirst = ($templateMode === 'list' && $fieldsMode === 'per_template' && ($selectedTemplate ?? '') === '');
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= $isNew ? __('terms.new_title') : e($term->name) ?></h1>
        <p class="page-header__subtitle"><?= __('terms.edit_taxonomy') ?> <code><?= e($taxonomy) ?></code></p>
    </div>
    <?php
    // Term::url() é '' numa taxonomia sem página própria (has_page: false).
    $publicUrl = !$isNew ? $term->url() : '';
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
                <?php $hasContent = !empty($nameTrans[$lang->code]) || !empty($slugTrans[$lang->code]); ?>
                <button type="button" class="lang-tabs__btn" data-lang-tab="<?= e($lang->code) ?>">
                    <?= e($lang->label) ?>
                    <?php if ($hasContent): ?>
                        <span class="lang-tabs__dot" title="<?= __('items.has_translation') ?>"></span>
                    <?php endif; ?>
                </button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="lang-panel is-active" data-lang-panel="<?= e($defaultLanguage) ?>">
        <div class="form-row">
            <div class="field" style="flex:2">
                <label class="field__label" for="name"><?= __('terms.field_name') ?> <span class="field__required">*</span></label>
                <div class="field__control">
                    <input class="input" type="text" name="name" id="name" value="<?= e($term?->name ?? '') ?>" required data-slug-source>
                </div>
            </div>
            <div class="field" style="flex:1">
                <label class="field__label" for="slug"><?= __('items.field_slug') ?></label>
                <div class="field__control">
                    <input class="input" type="text" name="slug" id="slug" value="<?= e($term?->slug ?? '') ?>" data-slug-target>
                </div>
            </div>
        </div>

        <?php if ($templateMode === 'list'): ?>
            <div class="form-row">
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
            </div>
        <?php endif; ?>

        <?php if ($hierarchical): ?>
            <div class="field">
                <label class="field__label" for="parent_id"><?= __('terms.field_parent') ?></label>
                <div class="field__control">
                    <select class="input input--select" name="parent_id" id="parent_id">
                        <option value=""><?= __('terms.parent_none') ?></option>
                        <?php foreach ($parentOptions as $pid => $pname): ?>
                            <option value="<?= e((string)$pid) ?>" <?= $term && $term->parentId === $pid ? 'selected' : '' ?>><?= e($pname) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($needsTemplateFirst): ?>
            <p class="field__help"><?= __('items.choose_template_help') ?></p>
        <?php else: ?>
            <?php if (!empty($fieldDefs)): ?>
                <?= \Velk\FieldRenderer::renderFields($fieldDefs, $term?->fields ?? [], 'fields') ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if ($i18nEnabled && !empty($languages)): ?>
        <?php foreach ($languages as $lang): ?>
            <div class="lang-panel" data-lang-panel="<?= e($lang->code) ?>" hidden>
                <div class="lang-panel__note">
                    <?= __('terms.translating_note', ['lang' => $lang->label]) ?>
                </div>

                <div class="form-row">
                    <div class="field" style="flex:2">
                        <label class="field__label"><?= __('terms.field_name') ?></label>
                        <div class="field__control">
                            <input class="input" type="text" name="name_translations[<?= e($lang->code) ?>]" value="<?= e($nameTrans[$lang->code] ?? '') ?>" placeholder="<?= e($term?->name ?? '') ?>">
                        </div>
                        <p class="field__help"><?= __('items.default_value', ['value' => $term?->name ?? '—']) ?></p>
                    </div>
                    <div class="field" style="flex:1">
                        <label class="field__label"><?= __('items.field_slug') ?></label>
                        <div class="field__control">
                            <input class="input" type="text" name="slug_translations[<?= e($lang->code) ?>]" value="<?= e($slugTrans[$lang->code] ?? '') ?>" placeholder="<?= e($term?->slug ?? '') ?>">
                        </div>
                        <?php
                        // O endereço em que o termo responde no idioma: o
                        // slug traduzido ou, sem ele, o padrão.
                        $cfg = \Velk\App::instance()->config;
                        $urlHint = $term !== null && $cfg->isTaxonomyHierarchical($taxonomy)
                            ? \Velk\Models\Term::fullPath($term, $cfg, $lang->code)
                            : '/' . trim($cfg->taxonomySlugIn($taxonomy, $lang->code), '/') . '/' . ($term?->slugIn($lang->code) ?? '...');
                        ?>
                        <p class="field__help"><?= __('items.url_label') ?> <code><?= e(url($urlHint, $lang->code)) ?></code></p>
                    </div>
                </div>

                <?php if (!empty($fieldDefs)): ?>
                    <?= \Velk\FieldRenderer::renderFields($fieldDefs, $term?->fieldsTranslations[$lang->code] ?? [], "fields_translations[{$lang->code}]") ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="form-actions">
        <button class="button" type="submit"><?= $isNew ? __('common.create') : __('common.save') ?></button>
        <a class="button button--ghost" href="<?= e(admin_url('taxonomies/' . $taxonomy)) ?>"><?= __('common.cancel') ?></a>
    </div>
</form>

<?php if ($i18nEnabled && !empty($languages)): ?>
<style>
.lang-tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--line); margin-bottom: 24px; }
.lang-tabs__btn { background: transparent; border: 0; border-bottom: 2px solid transparent; padding: 10px 16px; cursor: pointer; font: inherit; color: var(--ink-3); display: flex; align-items: center; gap: 8px; transition: color 120ms, border-color 120ms; }
.lang-tabs__btn:hover { color: var(--ink-1); }
.lang-tabs__btn.is-active { color: var(--ink-1); border-bottom-color: var(--ink); font-weight: 500; }
.lang-tabs__badge { font-size: 10px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--ink-4); background: var(--surface-2, #f5f5f6); padding: 2px 6px; border-radius: 2px; }
.lang-tabs__dot { display: none; }
.lang-panel__note { background: rgba(214, 90, 38, 0.08); border-left: 3px solid #d65a26; padding: 12px 16px; margin-bottom: 24px; font-size: 13px; color: var(--ink-2); }
.lang-panel[hidden] { display: none; }
</style>
<?php endif; ?>
