<?php
/**
 * @var list<\Velk\Models\ThemeString> $strings
 * @var list<\Velk\Models\Language> $languages
 * @var string $filterLang
 * @var string $filterStatus
 * @var string $search
 */
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= __('theme_strings.title') ?></h1>
        <p class="page-header__subtitle"><?= __('theme_strings.subtitle') ?></p>
    </div>
    <div class="page-header__actions">
        <form method="post" action="<?= e(admin_url('translations/scan')) ?>" style="display:inline">
            <?= csrf_field() ?>
            <button class="button button--ghost" type="submit" title="<?= __('theme_strings.scan_title') ?>"><?= __('theme_strings.scan_button') ?></button>
        </form>
        <a class="button" href="<?= e(admin_url('translations/new')) ?>"><?= __('theme_strings.new') ?></a>
    </div>
</div>

<?php if (empty($languages)): ?>
    <div class="empty" style="max-width:680px">
        <?= __('theme_strings.no_languages_prefix') ?> <a href="<?= e(admin_url('languages/new')) ?>"><?= __('theme_strings.no_languages_link') ?></a><?= __('theme_strings.no_languages_suffix') ?>
    </div>
<?php else: ?>

<form class="tbl-toolbar" method="get" action="<?= e(admin_url('translations')) ?>">
    <input class="input" type="text" name="q" value="<?= e($search) ?>" placeholder="<?= __('theme_strings.search_placeholder') ?>">
    <select class="input input--select" name="lang">
        <option value=""><?= __('theme_strings.filter_all_languages') ?></option>
        <?php foreach ($languages as $lang): ?>
            <option value="<?= e($lang->code) ?>" <?= $filterLang === $lang->code ? 'selected' : '' ?>>
                <?= e($lang->label) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <select class="input input--select" name="status">
        <option value=""><?= __('theme_strings.filter_all_statuses') ?></option>
        <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>><?= __('theme_strings.filter_pending') ?></option>
        <option value="translated" <?= $filterStatus === 'translated' ? 'selected' : '' ?>><?= __('theme_strings.filter_translated') ?></option>
    </select>
    <button class="button button--small" type="submit"><?= __('forms.filter_submit') ?></button>
    <?php if ($filterLang !== '' || $filterStatus !== '' || $search !== ''): ?>
        <a class="tbl-clear" href="<?= e(admin_url('translations')) ?>"><?= __('items.clear') ?></a>
    <?php endif; ?>
</form>

<?php if (empty($strings)): ?>
    <div class="empty">
        <?= ($filterLang !== '' || $filterStatus !== '' || $search !== '') ? __('theme_strings.empty_filtered') : __('theme_strings.empty') ?>
        <?= __('theme_strings.empty_hint_prefix') ?> <a href="<?= e(admin_url('translations/new')) ?>"><?= __('theme_strings.empty_hint_link') ?></a>.
    </div>
<?php else: ?>

<div class="table-scroll"><table class="table translations-table" data-csrf="<?= e(csrf_token()) ?>" data-update-url="<?= e(admin_url('translations/__ID__/update')) ?>">
    <thead>
        <tr>
            <th><?= __('theme_strings.col_original') ?></th>
            <?php foreach ($languages as $lang): ?>
                <th><?= e($lang->label) ?> <span class="muted" style="font-weight:400;font-size:11px"><?= e($lang->code) ?></span></th>
            <?php endforeach; ?>
            <th></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($strings as $s): ?>
            <tr data-string-id="<?= e((string) $s->id) ?>">
                <td>
                    <div class="ts-original" title="<?= e($s->original) ?>"><?= e($s->original) ?></div>
                    <?php if ($s->context !== null): ?>
                        <div class="muted" style="font-size:11px"><?= __('theme_strings.context_prefix') ?> <?= e($s->context) ?></div>
                    <?php endif; ?>
                </td>
                <?php foreach ($languages as $lang): ?>
                    <?php $translation = $s->translations[$lang->code] ?? ''; ?>
                    <td class="ts-cell <?= $translation === '' ? 'ts-cell--pending' : '' ?>" data-lang="<?= e($lang->code) ?>">
                        <textarea class="ts-input" rows="1" data-original-value="<?= e($translation) ?>" placeholder="—"><?= e($translation) ?></textarea>
                        <span class="ts-saved" hidden><?= __('theme_strings.saved_badge') ?></span>
                    </td>
                <?php endforeach; ?>
                <td class="table__actions">
                    <form method="post" action="<?= e(admin_url('translations/' . $s->id . '/delete')) ?>" data-confirm="<?= __('theme_strings.confirm_delete') ?>" style="display:inline">
                        <?= csrf_field() ?>
                        <button class="button button--small button--ghost" type="submit" title="<?= __('common.delete') ?>">×</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table></div>

<?php
$page = $pg['page'];
$totalPages = $pg['totalPages'];
$query = $pgQuery;
include __DIR__ . '/../layout/pagination.php';
?>

<?php endif; ?>
<?php endif; ?>

<style>
.translations-table .ts-original { font-size: 13px; line-height: 1.4; max-width: 360px; }
.translations-table .ts-cell { position: relative; min-width: 180px; }
.translations-table .ts-cell--pending { background: rgba(220, 38, 38, 0.04); }
.translations-table .ts-input {
    width: 100%; border: 0; background: transparent; resize: vertical;
    padding: 6px 8px; font: inherit; font-size: 13px; min-height: 28px;
    border-radius: 2px;
}
.translations-table .ts-input:focus { outline: 1px solid var(--accent, #d65a26); background: #fff; }
.translations-table .ts-saved { position: absolute; right: 8px; top: 6px; font-size: 10px; color: #15803d; }
</style>
