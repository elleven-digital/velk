<?php
/**
 * @var string $configType   'item_type' or 'taxonomy'
 * @var string $configKey
 * @var string $defaultLanguage
 * @var string $defaultLabel
 * @var string $defaultSlug
 * @var list<\Velk\Models\Language> $languages
 * @var array<string, string> $slugTranslations
 * @var array<string, string> $errors
 * @var string $backUrl
 */
$typeLabel = $configType === 'item_type' ? __('items.edit_type') : __('terms.edit_taxonomy');
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= __('config_translations.title', ['label' => $defaultLabel]) ?></h1>
        <p class="page-header__subtitle"><?= __('config_translations.subtitle', ['type' => $typeLabel, 'key' => $configKey]) ?></p>
    </div>
    <div class="page-header__actions">
        <a class="button button--ghost" href="<?= e($backUrl) ?>"><?= __('config_translations.back') ?></a>
    </div>
</div>

<?php if (empty($languages)): ?>
    <div class="empty">
        <?= __('theme_strings.no_languages_prefix') ?> <a href="<?= e(admin_url('languages/new')) ?>"><?= __('theme_strings.no_languages_link') ?></a><?= __('config_translations.no_languages_suffix') ?>
    </div>
<?php else: ?>

<form method="post" class="form">
    <?= csrf_field() ?>

    <div class="form-section">
        <h2 class="form-section__title"><?= __('config_translations.slug_section_title') ?></h2>
        <p class="form-section__sub">
            <?= __('config_translations.slug_section_help', ['type' => strtolower($typeLabel)]) ?>
        </p>

        <div class="field">
            <label class="field__label"><?= __('config_translations.default_label', ['lang' => $defaultLanguage]) ?></label>
            <div class="field__control">
                <input class="input" type="text" value="<?= e($defaultSlug) ?>" readonly>
            </div>
            <p class="field__help"><?= __('config_translations.default_help') ?></p>
        </div>

        <?php foreach ($languages as $lang): ?>
            <div class="field">
                <label class="field__label" for="slug_<?= e($lang->code) ?>"><?= e($lang->label) ?> <span class="muted" style="font-weight:400;font-size:11px">(<?= e($lang->code) ?>)</span></label>
                <div class="field__control">
                    <input
                        class="input"
                        type="text"
                        name="slug[<?= e($lang->code) ?>]"
                        id="slug_<?= e($lang->code) ?>"
                        value="<?= e($slugTranslations[$lang->code] ?? '') ?>"
                        placeholder="<?= e($defaultSlug) ?>">
                </div>
                <?php if (!empty($errors['slug.' . $lang->code])): ?>
                    <p class="field__help" style="color:var(--danger)"><?= e($errors['slug.' . $lang->code]) ?></p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="form-actions">
        <button class="button" type="submit"><?= __('config_translations.save_button') ?></button>
        <a class="button button--ghost" href="<?= e($backUrl) ?>"><?= __('common.cancel') ?></a>
    </div>
</form>

<?php endif; ?>
