<?php
/**
 * @var string $type
 * @var array $typeConfig
 * @var list<\Velk\Models\Item> $items
 * @var string $search
 * @var string $statusFilter
 * @var bool $ordemManual
 */
$hasFilters = $search !== '' || $statusFilter !== '';
$singularLabel = (string) ($typeConfig['label_singular'] ?? $type);
// Exclusão em massa: a action do form externo (#bulk-delete-form, ver
// abaixo) carrega a MESMA query string da listagem atual (filtros +
// página) — mesma convenção de layout/pagination.php — pra o redirect
// pós-delete do controller voltar exatamente pro mesmo estado.
$bulkDeleteQuery = array_merge($pgQuery, ['page' => $pg['page']]);
$bulkDeleteAction = admin_url('items/' . $type . '/bulk-delete')
    . ($bulkDeleteQuery !== [] ? '?' . http_build_query($bulkDeleteQuery) : '');
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= e((string)($typeConfig['label'] ?? $type)) ?></h1>
        <p class="page-header__subtitle"><?= $total === 1 ? __('items.count_one') : __('items.count_other', ['count' => $total]) ?> · <?= __('items.list_type') ?> <code><?= e($type) ?></code></p>
        <?php if ($ordemManual): ?>
            <span class="muted" style="font-size:12px"><?= __('items.drag_hint') ?></span>
        <?php endif; ?>
    </div>
    <div class="page-header__actions">
        <?php if (\Velk\App::instance()->config->isI18nEnabled()): ?>
            <a class="button button--ghost" href="<?= e(admin_url('items/' . $type . '/translations')) ?>" title="<?= __('items.translations_title') ?>"><?= __('items.translations') ?></a>
        <?php endif; ?>
        <a class="button" href="<?= e(admin_url('items/' . $type . '/new')) ?>"><?= __('items.new', ['type' => $singularLabel]) ?></a>
    </div>
</div>

<form class="tbl-toolbar" method="get" action="<?= e(admin_url('items/' . $type)) ?>">
    <label class="tbl-search">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="11" cy="11" r="8"/>
            <path d="M21 21l-4.35-4.35"/>
        </svg>
        <input
            type="text"
            name="q"
            value="<?= e($search) ?>"
            placeholder="<?= __('items.search_placeholder') ?>"
            autocomplete="off">
    </label>

    <div class="tbl-filters">
        <?php if (!empty($items)): ?>
            <?php // `type="button"`: a barra é um <form method="get"> e sem isto
                  // o clique submeteria a busca em vez de ligar a seleção. ?>
            <button type="button" class="tbl-icon-btn" data-bulk-toggle
                    title="<?= __('items.bulk_delete_toggle') ?>"
                    aria-label="<?= __('items.bulk_delete_toggle') ?>">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                    <path d="M10 11v6M14 11v6"/>
                </svg>
            </button>
        <?php endif; ?>
        <label class="tbl-pill">
            <span class="tbl-pill__label"><?= __('items.status_label') ?></span>
            <select name="status" class="tbl-pill__select" data-autosubmit>
                <option value="" <?= $statusFilter === '' ? 'selected' : '' ?>><?= __('items.filter_all') ?></option>
                <option value="published" <?= $statusFilter === 'published' ? 'selected' : '' ?>><?= __('items.filter_published') ?></option>
                <option value="draft"     <?= $statusFilter === 'draft'     ? 'selected' : '' ?>><?= __('items.filter_draft') ?></option>
            </select>
        </label>

        <?php if ($hasFilters): ?>
            <a class="tbl-clear" href="<?= e(admin_url('items/' . $type)) ?>" title="<?= __('items.clear_filters') ?>">
                <?= __('items.clear') ?>
            </a>
        <?php endif; ?>
    </div>
</form>

<?php if (empty($items)): ?>
    <div class="empty">
        <?php if ($hasFilters): ?>
            <?= __('items.empty_filtered_prefix') ?> <a href="<?= e(admin_url('items/' . $type)) ?>"><?= __('items.clear_filters') ?></a>.
        <?php else: ?>
            <?= __('items.empty_prefix') ?> <a href="<?= e(admin_url('items/' . $type . '/new')) ?>"><?= __('items.empty_create_link') ?></a>.
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="bulk-list" data-bulk-list>
        <div class="bulk-list__bar" data-bulk-bar>
            <span class="bulk-list__count" data-bulk-count><?= __('items.bulk_selected_other', ['count' => 0]) ?></span>
            <span class="spacer"></span>
            <button type="submit" form="bulk-delete-form" class="button button--small button--danger" data-bulk-submit disabled><?= __('items.bulk_delete_selected') ?></button>
            <button type="button" class="button button--small button--ghost" data-bulk-cancel><?= __('common.cancel') ?></button>
        </div>

        <div class="table-scroll"><table class="table">
            <thead>
                <tr>
                    <?php if ($ordemManual): ?><th></th><?php endif; ?>
                    <th class="bulk-list__col"><input type="checkbox" data-bulk-select-all aria-label="<?= __('items.bulk_select_all') ?>"></th>
                    <th><?= __('items.col_title') ?></th>
                    <th><?= __('items.status_label') ?></th>
                    <th><?= __('items.col_updated') ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody<?php if ($ordemManual): ?>
                   data-sortable-table
                   data-sortable-endpoint="<?= e(admin_url('items/' . $type . '/reorder')) ?>"
                   data-csrf="<?= e(csrf_token()) ?>"<?php endif; ?>>
                <?php foreach ($items as $item): ?>
                    <tr<?php if ($ordemManual): ?> draggable="true" data-sortable-id="<?= (int) $item->id ?>"<?php endif; ?>>
                        <?php if ($ordemManual): ?>
                        <td>
                            <span class="sortable-handle" aria-hidden="true">
                                <svg width="10" height="14" viewBox="0 0 10 14" fill="currentColor"><circle cx="2" cy="3" r="1"/><circle cx="2" cy="7" r="1"/><circle cx="2" cy="11" r="1"/><circle cx="8" cy="3" r="1"/><circle cx="8" cy="7" r="1"/><circle cx="8" cy="11" r="1"/></svg>
                            </span>
                        </td>
                        <?php endif; ?>
                        <td class="bulk-list__col"><input type="checkbox" name="ids[]" value="<?= e((string) $item->id) ?>" form="bulk-delete-form" data-bulk-checkbox></td>
                        <td>
                            <div class="table__title"><?= e($item->title) ?></div>
                            <?php if (($typeConfig['has_page'] ?? true) !== false): ?>
                                <div class="muted">/<?= e((string)($typeConfig['slug'] ?? $type)) ?>/<?= e($item->slug) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge--<?= e($item->status) ?>"><?= $item->status === 'published' ? __('items.status_published') : __('items.status_draft') ?></span>
                        </td>
                        <td class="muted"><?= e($item->updatedAt) ?></td>
                        <td class="table__actions">
                            <a class="button button--small button--ghost" href="<?= e(admin_url('items/' . $type . '/' . $item->id)) ?>"><?= __('common.edit') ?></a>
                            <form method="post" action="<?= e(admin_url('items/' . $type . '/' . $item->id . '/delete')) ?>" data-confirm="<?= __('items.confirm_delete') ?>" style="display:inline">
                                <?= csrf_field() ?>
                                <button class="button button--small button--danger" type="submit"><?= __('common.delete') ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>

    <!-- Form de exclusão em massa FORA da tabela (ver admin_no_nested_forms_test.php) —
         os checkboxes das linhas se associam por form="bulk-delete-form" (HTML5),
         sem precisar envolver a tabela e aninhar formulários. -->
    <form id="bulk-delete-form" method="post" action="<?= e($bulkDeleteAction) ?>" data-confirm="<?= __('items.bulk_confirm_delete') ?>">
        <?= csrf_field() ?>
    </form>
<?php endif; ?>

<?php
$page = $pg['page'];
$totalPages = $pg['totalPages'];
$query = $pgQuery;
include __DIR__ . '/../layout/pagination.php';
?>
