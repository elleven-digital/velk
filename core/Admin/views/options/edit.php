<?php
/**
 * @var string $key
 * @var array $pageDef
 * @var array $fieldDefs
 * @var array $values
 * @var array $translations
 * @var bool $i18nEnabled
 * @var string $defaultLanguage
 * @var list<\Velk\Models\Language> $languages
 */
$label = (string) ($pageDef['label'] ?? $key);
$description = (string) ($pageDef['description'] ?? '');
$i18nEnabled = $i18nEnabled ?? false;
$defaultLanguage = $defaultLanguage ?? 'pt-BR';
$languages = $languages ?? [];
$translations = $translations ?? [];
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= e($label) ?></h1>
        <p class="page-header__subtitle">
            <?php if ($description !== ''): ?>
                <?= e($description) ?>
            <?php else: ?>
                <?= __('options.subtitle_fallback', ['key' => $key]) ?>
            <?php endif; ?>
        </p>
    </div>
</div>

<form method="post" class="form" data-i18n-form="<?= $i18nEnabled ? '1' : '0' ?>">
    <?= csrf_field() ?>

    <?php if (empty($fieldDefs)): ?>
        <div class="empty"><?= __('options.no_fields') ?></div>
    <?php else: ?>

    <?php if ($i18nEnabled && !empty($languages)): ?>
        <div class="lang-tabs" data-lang-tabs>
            <button type="button" class="lang-tabs__btn is-active" data-lang-tab="<?= e($defaultLanguage) ?>">
                <?= e(\Velk\App::instance()->i18n->labelFor($defaultLanguage)) ?>
                <span class="lang-tabs__badge">default</span>
            </button>
            <?php foreach ($languages as $lang): ?>
                <?php $hasContent = !empty($translations[$lang->code]); ?>
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
        <?= \Velk\FieldRenderer::renderFields($fieldDefs, $values, 'fields') ?>
    </div>

    <?php if ($i18nEnabled && !empty($languages)): ?>
        <?php foreach ($languages as $lang): ?>
            <div class="lang-panel" data-lang-panel="<?= e($lang->code) ?>" hidden>
                <div class="lang-panel__note">
                    <?= __('options.translating_note', ['lang' => $lang->label]) ?>
                </div>

                <?= \Velk\FieldRenderer::renderFields($fieldDefs, $translations[$lang->code] ?? [], 'fields_translations[' . $lang->code . ']') ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="form-actions">
        <button class="button" type="submit"><?= __('options.save_button') ?></button>
    </div>
    <?php endif; ?>
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
