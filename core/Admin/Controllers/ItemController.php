<?php

declare(strict_types=1);

namespace Velk\Admin\Controllers;

use Velk\Admin\SlugConflicts;
use Velk\FieldRenderer;
use Velk\Models\Item;
use Velk\Models\Term;
use Velk\Request;
use Velk\Response;
use Velk\Services\VariantService;
use Velk\TemplateResolver;

final class ItemController extends Controller
{
    public function index(Request $request, array $params): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;

        $type = (string) ($params['type'] ?? '');
        $config = $this->app->config->itemType($type);
        if ($config === null) {
            return Response::notFound(__('items.error_type_not_found', ['type' => $type]));
        }

        // A primeira listagem depois de ligar a flag materializa a ordem que
        // já está na tela. Uma vez por tipo na vida: `ensureSeeded` sai fora
        // assim que os valores são distintos.
        if (Item::manualOrder($type)) {
            Item::ensureOrderSeeded($type);
        }

        // Admin filters — read from query string so URLs are shareable
        // and persist across edit/delete redirects (when we add that).
        $search = trim((string) ($request->query['q'] ?? ''));
        $statusFilter = (string) ($request->query['status'] ?? '');
        $allowedStatuses = ['', 'published', 'draft'];
        if (!in_array($statusFilter, $allowedStatuses, true)) {
            $statusFilter = '';
        }

        $total = Item::countAdmin(
            $type,
            $statusFilter !== '' ? $statusFilter : null,
            $search !== '' ? $search : null
        );
        $pg = \Velk\Paginator::make($request->query['page'] ?? null, $total);
        $items = Item::listAdmin(
            $type,
            $statusFilter !== '' ? $statusFilter : null,
            $search !== '' ? $search : null,
            $pg['perPage'],
            $pg['offset']
        );

        return $this->render('items/index', [
            'type' => $type,
            'typeConfig' => $config,
            'items' => $items,
            'search' => $search,
            'statusFilter' => $statusFilter,
            'total' => $total,
            'pg' => $pg,
            'pgQuery' => array_diff_key($request->query, ['page' => '']),
            'ordemManual' => Item::manualOrder($type),
        ]);
    }

    public function create(Request $request, array $params): Response
    {
        return $this->editOrCreate($request, $params, null);
    }

    public function edit(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        return $this->editOrCreate($request, $params, $id);
    }

    private function editOrCreate(Request $request, array $params, ?int $id): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;

        $type = (string) ($params['type'] ?? '');
        $config = $this->app->config->itemType($type);
        if ($config === null) {
            return Response::notFound(__('items.error_type_not_found', ['type' => $type]));
        }

        $item = $id !== null ? Item::find($id) : null;
        if ($id !== null && $item === null) {
            return Response::notFound(__('items.error_item_not_found'));
        }
        if ($item !== null && $item->type !== $type) {
            return Response::notFound(__('items.error_type_mismatch'));
        }

        $mode = TemplateResolver::mode($config);
        $fieldsMode = TemplateResolver::fieldsMode($config);
        // Selected template: comes from the form on POST; from the item (or null) on GET.
        $selectedTemplate = $item?->template;
        if ($request->isPost()) {
            $selectedTemplate = $mode === 'list' ? trim((string) ($request->post['template'] ?? '')) : null;
            if ($selectedTemplate === '') $selectedTemplate = null;
        }
        $fieldDefs = $this->app->config->resolveFields(TemplateResolver::fieldsFor($config, $selectedTemplate));
        $taxonomies = (array) ($config['taxonomies'] ?? []);
        // SEO is built-in only for item types that have a public page.
        // Embed-only types (has_page: false) don't need meta tags.
        $hasPage = ($config['has_page'] ?? true) !== false;
        $seoFields = $hasPage ? $this->app->config->seoFields() : [];

        // Sellable types get an auto-attached "price" field (same
        // pattern as SEO). Merged into $fieldDefs so it renders inline
        // and gets collected together with the other fields.
        if (($config['sellable'] ?? false) === true) {
            $fieldDefs = array_merge($fieldDefs, $this->app->config->saleFields());
        }

        if ($request->isPost()) {
            $csrf = $this->verifyCsrfOrFail($request);
            if ($csrf !== null) return $csrf;

            $title = trim((string) ($request->post['title'] ?? ''));
            $slug = trim((string) ($request->post['slug'] ?? ''));
            $status = ($request->post['status'] ?? 'draft') === 'published' ? 'published' : 'draft';

            // List mode: template selection is mandatory and the key must
            // exist. This needs to stay the first validation in the block —
            // before ANY save, including the round-trip further below.
            if ($mode === 'list') {
                $options = TemplateResolver::options($config);
                if ($selectedTemplate === null || !isset($options[$selectedTemplate])) {
                    $this->app->session->flash('error', __('items.error_choose_template'));
                    return Response::redirect($request->server['REQUEST_URI'] ?? admin_url("items/{$type}"));
                }
            }

            // Parent item (only when the type is hierarchical). Validates: it
            // exists, is of the same type, and is not the item itself nor one
            // of its descendants (anti-cycle). Must come before the slug below
            // — ensureUniqueSlug depends on $parentId to check uniqueness among
            // siblings.
            $hierarchical = $this->app->config->isItemTypeHierarchical($type);
            $parentId = null;
            if ($hierarchical) {
                $rawParent = trim((string) ($request->post['parent_id'] ?? ''));
                $parentId = $rawParent === '' ? null : (int) $rawParent;
                $parentErr = $this->validateItemParent($type, $parentId, $item?->id);
                if ($parentErr !== null) {
                    $this->app->session->flash('error', $parentErr);
                    return Response::redirect($request->server['REQUEST_URI'] ?? admin_url("items/{$type}"));
                }
            }

            if ($title === '') {
                $this->app->session->flash('error', __('items.error_title_required'));
                return Response::redirect($request->server['REQUEST_URI'] ?? admin_url("items/{$type}"));
            }
            if ($slug === '') {
                $slug = slugify($title);
            }
            $slug = slugify($slug);
            $slug = $this->ensureUniqueSlug($type, $slug, $item?->id, $parentId);

            $publishedAt = $item?->publishedAt;
            if ($status === 'published' && $publishedAt === null) {
                $publishedAt = date('Y-m-d H:i:s');
            }

            // Per-template fields: when SWITCHING the template of an existing
            // item, persist title/slug/template/status/published_at (and
            // parent_id when hierarchical) — already validated/prepared above
            // — and reload the fields for the new template. The per-template
            // fields submitted in this request belong to the OLD template, so
            // they are neither collected nor saved on this pass.
            if ($mode === 'list' && $fieldsMode === 'per_template' && $item !== null && $selectedTemplate !== $item->template) {
                $roundTripPayload = [
                    'title' => $title,
                    'slug' => $slug,
                    'template' => $selectedTemplate,
                    'status' => $status,
                    'published_at' => $publishedAt,
                ];
                if ($hierarchical) {
                    $roundTripPayload['parent_id'] = $parentId;
                }
                $item->save($roundTripPayload);
                $this->app->session->flash('success', __('items.flash_template_changed'));
                return Response::redirect(admin_url("items/{$type}/{$item->id}"));
            }

            // Merge SEO defs so its values are persisted alongside content.
            $allDefs = array_merge($fieldDefs, $seoFields);
            $values = FieldRenderer::collect($allDefs, $request->post['fields'] ?? []);
            VariantService::ensureForFields($allDefs, $values);

            $payload = [
                'title' => $title,
                'slug' => $slug,
                'template' => $selectedTemplate,
                'status' => $status,
                'fields' => $values,
                'published_at' => $publishedAt,
            ];
            if ($hierarchical) {
                $payload['parent_id'] = $parentId;
            }

            // i18n Phase 3 — collect per-language translation inputs
            // when the form posted them. Each language gets its own
            // (title, slug, fields) bundle keyed by language code.
            //
            // MERGE, not replace: only languages actually PRESENT in the
            // POST (array_key_exists, not just non-empty) are touched —
            // a language whose panel wasn't submitted (disabled at render
            // time, or a trimmed/tampered form) keeps its existing stored
            // translation instead of being silently wiped. For a brand
            // new item ($item === null) the "existing" maps are simply
            // empty, so this degrades to the old from-scratch behavior.
            if ($this->app->config->isI18nEnabled()) {
                $defaultLang = $this->app->config->defaultLanguage();
                $titleUpdates = [];
                $slugUpdates = [];
                $fieldsUpdates = [];

                $postedTitles = (array) ($request->post['title_translations'] ?? []);
                $postedSlugs  = (array) ($request->post['slug_translations'] ?? []);
                $postedFields = (array) ($request->post['fields_translations'] ?? []);

                foreach (\Velk\Models\Language::all(onlyEnabled: true) as $lang) {
                    if ($lang->code === $defaultLang) continue;

                    if (array_key_exists($lang->code, $postedTitles)) {
                        $t = trim((string) $postedTitles[$lang->code]);
                        $titleUpdates[$lang->code] = $t !== '' ? $t : null;
                    }

                    if (array_key_exists($lang->code, $postedSlugs)) {
                        $s = trim((string) $postedSlugs[$lang->code]);
                        $slugUpdates[$lang->code] = $s !== '' ? slugify($s) : null;
                    }

                    if (array_key_exists($lang->code, $postedFields)) {
                        $f = FieldRenderer::collect($allDefs, (array) $postedFields[$lang->code]);
                        // Keep only the fields the translator actually overrode.
                        // Type-aware (see FieldRenderer::translationOverrides): an
                        // unchecked boolean or an empty image ("0") no longer
                        // freezes into every language and shadows the default.
                        $f = FieldRenderer::translationOverrides($allDefs, $f, $values);
                        $fieldsUpdates[$lang->code] = $f !== [] ? $f : null;
                    }
                }

                $payload['title_translations']  = self::mergeTranslations($item?->titleTranslations ?? [], $titleUpdates);
                $payload['slug_translations']   = self::mergeTranslations($item?->slugTranslations ?? [], $slugUpdates);
                $payload['fields_translations'] = self::mergeTranslations($item?->fieldsTranslations ?? [], $fieldsUpdates);
            }

            if ($item === null) {
                $payload['type'] = $type;
                // Item novo entra no fim, para não bagunçar ordem curada. Sem
                // a flag a coluna fica em 0 e ninguém a lê. Só no ramo de
                // criação: reaplicar a cada save empurraria o item pro fim
                // toda vez que alguém só editasse o título.
                if (Item::manualOrder($type)) {
                    $payload['sort_order'] = Item::nextSortOrder($type);
                }
                $item = Item::create($payload);
            } else {
                $item->save($payload);
            }

            foreach ($taxonomies as $taxonomy) {
                $termIds = array_map('intval', (array) ($request->post['terms'][$taxonomy] ?? []));
                $item->setTerms((string) $taxonomy, $termIds);
            }

            $this->app->session->flash('success', __('items.flash_saved'));
            $this->flashSlugConflicts(SlugConflicts::forItem(Item::find($item->id) ?? $item));
            return Response::redirect(admin_url("items/{$type}/{$item->id}"));
        }

        $termOptions = [];
        foreach ($taxonomies as $taxonomy) {
            $termOptions[(string) $taxonomy] = Term::all((string) $taxonomy);
        }
        $itemTerms = [];
        if ($item !== null) {
            foreach ($taxonomies as $taxonomy) {
                $itemTerms[(string) $taxonomy] = array_column(
                    array_map(fn(Term $t) => ['id' => $t->id], $item->terms((string) $taxonomy)),
                    'id'
                );
            }
        }

        return $this->render('items/edit', [
            'type' => $type,
            'typeConfig' => $config,
            'item' => $item,
            'fieldDefs' => $fieldDefs,
            'seoFields' => $seoFields,
            'taxonomies' => $taxonomies,
            'termOptions' => $termOptions,
            'itemTerms' => $itemTerms,
            'templateMode' => $mode,
            'templateOptions' => TemplateResolver::options($config),
            'fieldsMode' => $fieldsMode,
            'selectedTemplate' => $selectedTemplate,
            'hierarchical' => $this->app->config->isItemTypeHierarchical($type),
            'parentOptions' => $this->app->config->isItemTypeHierarchical($type)
                ? $this->itemParentOptions($type, $item?->id)
                : [],
            'i18nEnabled' => $this->app->config->isI18nEnabled(),
            'defaultLanguage' => $this->app->config->defaultLanguage(),
            'languages' => $this->app->config->isI18nEnabled()
                ? \Velk\Models\Language::all(onlyEnabled: true)
                : [],
        ]);
    }

    public function delete(Request $request, array $params): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;
        $csrf = $this->verifyCsrfOrFail($request);
        if ($csrf !== null) return $csrf;

        $type = (string) ($params['type'] ?? '');
        $id = (int) ($params['id'] ?? 0);
        $item = Item::find($id);
        if ($item !== null && $item->type === $type) {
            $item->delete();
            $this->app->session->flash('success', __('items.flash_deleted'));
        }
        return Response::redirect(admin_url("items/{$type}"));
    }

    /**
     * Exclusão em massa. Mesma sequência do delete() individual — auth,
     * csrf, checagem de escopo por id — mas em laço sobre uma lista de
     * ids. Deleta um a um via Item::delete() (não um DELETE...WHERE IN em
     * lote): mantém a mesma semântica do delete individual e, se o model
     * um dia ganhar cascata, o bulk herda de graça.
     *
     * O redirect preserva a query string da requisição (filtros/paginação
     * da listagem) — a view monta a action do form com essa mesma query,
     * então ela chega aqui em $request->query independente do method ser
     * POST.
     */
    public function bulkDelete(Request $request, array $params): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;
        $csrf = $this->verifyCsrfOrFail($request);
        if ($csrf !== null) return $csrf;

        $type = (string) ($params['type'] ?? '');
        $redirect = admin_url("items/{$type}") . ($request->query !== [] ? '?' . http_build_query($request->query) : '');

        $ids = self::parseBulkIds($request->post['ids'] ?? null);
        if ($ids === []) {
            $this->app->session->flash('error', __('items.bulk_flash_none_selected'));
            return Response::redirect($redirect);
        }

        $deleted = 0;
        foreach ($ids as $id) {
            $item = Item::find($id);
            if ($item !== null && $item->type === $type) {
                $item->delete();
                $deleted++;
            }
        }

        $message = $deleted === 1
            ? __('items.bulk_flash_deleted_one')
            : __('items.bulk_flash_deleted_other', ['count' => $deleted]);
        $this->app->session->flash($deleted > 0 ? 'success' : 'error', $message);

        return Response::redirect($redirect);
    }

    /**
     * Endpoint do arrasto. Recebe `ids[]` na nova ordem visual da página que
     * está na tela e reordena essas linhas entre si.
     *
     * Exige `manual_order` no tipo: um endpoint que reordena tipo que não
     * declarou ordem manual gravaria posição que nada lê, e daria a impressão
     * de que a flag não é necessária.
     */
    public function reorder(Request $request, array $params): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;
        $csrf = $this->verifyCsrfOrFail($request);
        if ($csrf !== null) return $csrf;

        $type = (string) ($params['type'] ?? '');
        if ($this->app->config->itemType($type) === null || !Item::manualOrder($type)) {
            return Response::json(['error' => 'manual order is not enabled for this type'], 404);
        }

        $ids = $request->post['ids'] ?? [];
        if (!is_array($ids)) {
            return Response::json(['error' => 'ids must be a list'], 422);
        }

        Item::applyOrder($type, $ids);

        return Response::json(['ok' => true]);
    }

    /**
     * Validates the chosen parent item (only for hierarchical types): it
     * must exist, be of the SAME type, and not be the item itself or one
     * of its descendants (anti-cycle). null = root, always valid.
     */
    private function validateItemParent(string $type, ?int $parentId, ?int $selfId): ?string
    {
        if ($parentId === null) return null;

        $parent = Item::find($parentId);
        if ($parent === null || $parent->type !== $type) {
            return __('items.error_invalid_parent');
        }
        if ($selfId !== null) {
            if ($parentId === $selfId) {
                return __('items.error_self_parent');
            }
            if (in_array($parentId, Item::descendantIds($selfId), true)) {
                return __('items.error_parent_cycle');
            }
        }
        return null;
    }

    /**
     * Options for the "Parent item" dropdown: items of the same type as
     * id => title, except the item itself and its descendants.
     *
     * @return array<int,string>
     */
    private function itemParentOptions(string $type, ?int $selfId): array
    {
        $exclude = [];
        if ($selfId !== null) {
            $exclude[$selfId] = true;
            foreach (Item::descendantIds($selfId) as $d) {
                $exclude[$d] = true;
            }
        }
        $opts = [];
        foreach (Item::listAdmin($type, 'all') as $it) {
            if (isset($exclude[$it->id])) continue;
            $opts[$it->id] = $it->title;
        }
        return $opts;
    }

    /**
     * Guarantees a slug unique among SIBLINGS (same type + same parent),
     * generating a -2, -3… suffix on collision. For non-hierarchical types,
     * $parentId is NULL and uniqueness falls back to all root items of the
     * type — same as the old "unique per type" behavior.
     */
    private function ensureUniqueSlug(string $type, string $slug, ?int $excludeId = null, ?int $parentId = null): string
    {
        $base = $slug;
        $counter = 1;
        while (true) {
            $parentCond = $parentId === null ? 'parent_id IS NULL' : 'parent_id = ?';
            $sql = "SELECT id FROM items WHERE type = ? AND {$parentCond} AND slug = ?";
            $params = $parentId === null ? [$type, $slug] : [$type, $parentId, $slug];
            if ($excludeId !== null) {
                $sql .= ' AND id <> ?';
                $params[] = $excludeId;
            }
            $existing = $this->app->db->fetch($sql . ' LIMIT 1', $params);
            if ($existing === null) {
                return $slug;
            }
            $counter++;
            $slug = $base . '-' . $counter;
        }
    }
}
