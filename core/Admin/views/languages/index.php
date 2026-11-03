<?php
/**
 * @var list<\Velk\Models\Language> $languages
 * @var string $defaultCode
 */
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= __('languages.title') ?></h1>
        <p class="page-header__subtitle"><?= __('languages.subtitle') ?></p>
    </div>
    <div class="page-header__actions">
        <a class="button button--ghost" href="<?= e(admin_url('translations')) ?>"><?= __('theme_strings.title') ?></a>
        <a class="button" href="<?= e(admin_url('languages/new')) ?>"><?= __('languages.new') ?></a>
    </div>
</div>

<div class="table-scroll"><table class="table">
    <thead>
        <tr>
            <th><?= __('languages.col_language') ?></th>
            <th><?= __('languages.col_code') ?></th>
            <th><?= __('languages.field_url_prefix') ?></th>
            <th><?= __('languages.col_order') ?></th>
            <th><?= __('items.status_label') ?></th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td class="table__title">
                <?= e(\Velk\App::instance()->i18n->labelFor($defaultCode)) ?>
                <span class="badge" style="margin-left:6px" title="<?= __('languages.default_title') ?>">site.json</span>
            </td>
            <td><code><?= e($defaultCode) ?></code></td>
            <td class="muted"><?= __('languages.default_no_prefix') ?></td>
            <td class="muted">—</td>
            <td><span class="badge badge--published"><?= __('languages.default_badge') ?></span></td>
            <td></td>
        </tr>

        <?php if (empty($languages)): ?>
            <tr>
                <td colspan="6" class="empty" style="padding:32px;text-align:center">
                    <?= __('languages.empty_prefix') ?> <a href="<?= e(admin_url('languages/new')) ?>"><?= __('languages.empty_create_link') ?></a>.
                </td>
            </tr>
        <?php else: ?>
            <?php foreach ($languages as $lang): ?>
                <tr>
                    <td class="table__title"><?= e($lang->label) ?></td>
                    <td><code><?= e($lang->code) ?></code></td>
                    <td class="muted"><code><?= e($lang->urlPrefix ?? '—') ?></code></td>
                    <td class="muted"><?= e((string) $lang->sortOrder) ?></td>
                    <td>
                        <?php if ($lang->enabled): ?>
                            <span class="badge badge--published"><?= __('languages.status_active') ?></span>
                        <?php else: ?>
                            <span class="badge"><?= __('languages.status_disabled') ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="table__actions">
                        <a class="button button--small button--ghost" href="<?= e(admin_url('languages/' . $lang->code)) ?>"><?= __('common.edit') ?></a>
                        <form method="post" action="<?= e(admin_url('languages/' . $lang->code . '/delete')) ?>" data-confirm="<?= __('languages.confirm_delete', ['code' => $lang->code]) ?>" style="display:inline">
                            <?= csrf_field() ?>
                            <button class="button button--small button--danger" type="submit"><?= __('common.delete') ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table></div>
