<?php
/**
 * @var string $taxonomy
 * @var array $taxonomyConfig
 * @var list<\Velk\Models\Term> $terms
 * @var bool $ordemManual
 */
// Exclusão em massa: a action do form externo (#bulk-delete-form, ver
// abaixo) carrega a MESMA query string da listagem atual (paginação) —
// mesma convenção de layout/pagination.php — pra o redirect pós-delete
// do controller voltar exatamente pro mesmo estado.
$bulkDeleteQuery = array_merge($pgQuery, ['page' => $pg['page']]);
$bulkDeleteAction = admin_url('taxonomies/' . $taxonomy . '/bulk-delete')
    . ($bulkDeleteQuery !== [] ? '?' . http_build_query($bulkDeleteQuery) : '');
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= e((string)($taxonomyConfig['label'] ?? $taxonomy)) ?></h1>
        <p class="page-header__subtitle"><?= $total === 1 ? __('terms.count_one') : __('terms.count_other', ['count' => $total]) ?> · <?= __('terms.list_taxonomy') ?> <code><?= e($taxonomy) ?></code></p>
        <?php if ($ordemManual): ?>
            <span class="muted" style="font-size:12px"><?= __('terms.drag_hint') ?></span>
        <?php endif; ?>
    </div>
    <div class="page-header__actions">
        <?php if (\Velk\App::instance()->config->isI18nEnabled()): ?>
            <a class="button button--ghost" href="<?= e(admin_url('taxonomies/' . $taxonomy . '/translations')) ?>" title="<?= __('terms.translations_title') ?>"><?= __('items.translations') ?></a>
        <?php endif; ?>
        <?php if (!empty($terms)): ?>
            <?php // Termos não tem barra de filtros, então o botão fica no
                  // cabeçalho mesmo — mas como ícone, igual ao de itens. ?>
            <button type="button" class="tbl-icon-btn" data-bulk-toggle
                    title="<?= __('items.bulk_delete_toggle') ?>"
                    aria-label="<?= __('items.bulk_delete_toggle') ?>">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                    <path d="M10 11v6M14 11v6"/>
                </svg>
            </button>
        <?php endif; ?>
        <a class="button" href="<?= e(admin_url('taxonomies/' . $taxonomy . '/new')) ?>"><?= __('items.new', ['type' => (string)($taxonomyConfig['label_singular'] ?? __('terms.default_singular_label'))]) ?></a>
    </div>
</div>

<?php if (empty($terms)): ?>
    <div class="empty">
        <?= __('terms.empty_prefix') ?> <a href="<?= e(admin_url('taxonomies/' . $taxonomy . '/new')) ?>"><?= __('items.empty_create_link') ?></a>.
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
                    <th><?= __('terms.col_name') ?></th>
                    <th><?= __('items.field_slug') ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody<?php if ($ordemManual): ?>
                   data-sortable-table
                   data-sortable-endpoint="<?= e(admin_url('taxonomies/' . $taxonomy . '/reorder')) ?>"
                   data-csrf="<?= e(csrf_token()) ?>"<?php endif; ?>>
                <?php foreach ($terms as $term): ?>
                    <tr<?php if ($ordemManual): ?> draggable="true" data-sortable-id="<?= (int) $term->id ?>"<?php endif; ?>>
                        <?php if ($ordemManual): ?>
                        <td>
                            <span class="sortable-handle" aria-hidden="true">
                                <svg width="10" height="14" viewBox="0 0 10 14" fill="currentColor"><circle cx="2" cy="3" r="1"/><circle cx="2" cy="7" r="1"/><circle cx="2" cy="11" r="1"/><circle cx="8" cy="3" r="1"/><circle cx="8" cy="7" r="1"/><circle cx="8" cy="11" r="1"/></svg>
                            </span>
                        </td>
                        <?php endif; ?>
                        <td class="bulk-list__col"><input type="checkbox" name="ids[]" value="<?= e((string) $term->id) ?>" form="bulk-delete-form" data-bulk-checkbox></td>
                        <td class="table__title"><?= e($term->name) ?></td>
                        <td class="muted"><?= e($term->slug) ?></td>
                        <td class="table__actions">
                            <a class="button button--small button--ghost" href="<?= e(admin_url('taxonomies/' . $taxonomy . '/' . $term->id)) ?>"><?= __('common.edit') ?></a>
                            <form method="post" action="<?= e(admin_url('taxonomies/' . $taxonomy . '/' . $term->id . '/delete')) ?>" data-confirm="<?= __('terms.confirm_delete') ?>" style="display:inline">
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
    <form id="bulk-delete-form" method="post" action="<?= e($bulkDeleteAction) ?>" data-confirm="<?= __('terms.bulk_confirm_delete') ?>">
        <?= csrf_field() ?>
    </form>
<?php endif; ?>

<?php
$page = $pg['page'];
$totalPages = $pg['totalPages'];
$query = $pgQuery;
include __DIR__ . '/../layout/pagination.php';
?>
