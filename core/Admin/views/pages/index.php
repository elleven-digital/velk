<?php
/**
 * @var array $pages           JSON-defined pages from site.json
 * @var list<\Velk\Models\Page> $customPages  Admin-created pages
 * @var array $pageTemplates   Available page_templates for the "Create" button
 */
$canCreate = !empty($pageTemplates);
// The Template column only makes sense when there are custom pages (that's
// where the user picked a page_template). For fixed site.json pages the
// template is just the theme file, irrelevant in the listing.
$hasCustom = !empty($customPages);
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= __('pages.title') ?></h1>
        <p class="page-header__subtitle"><?= __('pages.subtitle') ?></p>
    </div>
    <div class="page-header__actions">
        <?php if ($canCreate): ?>
            <a class="button" href="<?= e(admin_url('pages/new')) ?>"><?= __('pages.new') ?></a>
        <?php endif; ?>
    </div>
</div>

<?php if (empty($pages) && empty($customPages)): ?>
    <div class="empty"><?= __('pages.empty') ?></div>
<?php else: ?>
    <div class="table-scroll"><table class="table">
        <thead>
            <tr>
                <th><?= __('pages.col_page') ?></th>
                <th><?= __('pages.col_url') ?></th>
                <?php if ($hasCustom): ?><th><?= __('items.field_template') ?></th><?php endif; ?>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($pages as $key => $pageDef): ?>
                <?php $publicUrl = \Velk\Models\Page::pageUrl((string) $key, $pageDef); ?>
                <tr>
                    <td class="table__title">
                        <?= e((string)($pageDef['label'] ?? $key)) ?>
                    </td>
                    <td class="muted"><?= e($publicUrl) ?></td>
                    <?php if ($hasCustom): ?><td></td><?php endif; ?>
                    <td class="table__actions">
                        <a class="button button--small button--ghost" href="<?= e($publicUrl) ?>" target="_blank" rel="noopener"><?= __('pages.view') ?></a>
                        <a class="button button--small button--ghost" href="<?= e(admin_url('pages/' . $key)) ?>"><?= __('common.edit') ?></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php foreach ($customPages as $custom): ?>
                <?php
                $tplKey = (string) $custom->template;
                $tpl = $pageTemplates[$tplKey] ?? null;
                $tplLabel = $tpl !== null ? (string) ($tpl['label'] ?? $tplKey) : $tplKey . ' ' . __('pages.template_missing_suffix');
                $publicUrl = \Velk\Models\Page::pageUrl($custom->key, ['url' => '/' . $custom->slug]);
                ?>
                <tr>
                    <td class="table__title">
                        <?= e($custom->title) ?>
                        <span class="badge badge--published" style="margin-left:6px" title="<?= __('pages.created_by_panel_title') ?>"><?= __('pages.panel_badge') ?></span>
                    </td>
                    <td class="muted"><?= e($publicUrl) ?></td>
                    <td class="muted">
                        <?= e($tplLabel) ?>
                        <?php if ($tpl === null): ?>
                            <span class="muted" style="color:var(--danger);font-size:11px;display:block"><?= __('pages.template_missing_notice', ['key' => $tplKey]) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="table__actions">
                        <a class="button button--small button--ghost" href="<?= e($publicUrl) ?>" target="_blank" rel="noopener"><?= __('pages.view') ?></a>
                        <a class="button button--small button--ghost" href="<?= e(admin_url('pages/' . $custom->key)) ?>"><?= __('common.edit') ?></a>
                        <form method="post" action="<?= e(admin_url('pages/' . $custom->key . '/delete')) ?>" data-confirm="<?= __('pages.confirm_delete', ['title' => $custom->title]) ?>" style="display:inline">
                            <?= csrf_field() ?>
                            <button class="button button--small button--danger" type="submit"><?= __('common.delete') ?></button>
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
