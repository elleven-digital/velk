<?php

declare(strict_types=1);

namespace Velk\Admin\Controllers;

use Velk\Admin\SlugConflicts;
use Velk\FieldRenderer;
use Velk\Models\Term;
use Velk\Request;
use Velk\Response;
use Velk\Services\VariantService;
use Velk\TemplateResolver;

final class TermController extends Controller
{
    public function index(Request $request, array $params): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;

        $taxonomy = (string) ($params['taxonomy'] ?? '');
        $config = $this->app->config->taxonomy($taxonomy);
        if ($config === null) {
            return Response::notFound(__('terms.error_taxonomy_not_found', ['taxonomy' => $taxonomy]));
        }

        $total = Term::count($taxonomy);
        if (Term::manualOrder($taxonomy)) {
            Term::ensureOrderSeeded($taxonomy);
        }
        $pg = \Velk\Paginator::make($request->query['page'] ?? null, $total);
        $terms = Term::all($taxonomy, $pg['perPage'], $pg['offset']);
        return $this->render('terms/index', [
            'taxonomy' => $taxonomy,
            'taxonomyConfig' => $config,
            'terms' => $terms,
            'total' => $total,
            'pg' => $pg,
            'pgQuery' => array_diff_key($request->query, ['page' => '']),
            'ordemManual' => Term::manualOrder($taxonomy),
        ]);
    }

    public function create(Request $request, array $params): Response
    {
        return $this->editOrCreate($request, $params, null);
    }

    public function edit(Request $request, array $params): Response
    {
        return $this->editOrCreate($request, $params, (int) ($params['id'] ?? 0));
    }

    private function editOrCreate(Request $request, array $params, ?int $id): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;

        $taxonomy = (string) ($params['taxonomy'] ?? '');
        $config = $this->app->config->taxonomy($taxonomy);
        if ($config === null) {
            return Response::notFound(__('terms.error_taxonomy_not_found', ['taxonomy' => $taxonomy]));
        }
        $term = $id !== null ? Term::find($id) : null;
        if ($id !== null && $term === null) {
            return Response::notFound(__('terms.error_term_not_found'));
        }
        // O termo precisa pertencer à taxonomia da URL — mesma conferência
        // que o ItemController faz com `type`, e que delete()/bulkDelete()
        // daqui já fazem. Sem ela, /taxonomies/tag/42 abria um termo de
        // `categoria` sob a tela de `tag`: validateTermParent() e
        // ensureUniqueSlug() rodavam contra a taxonomia ERRADA, permitindo
        // pai de outra taxonomia e furando a unicidade de slug da real.
        if ($term !== null && $term->taxonomy !== $taxonomy) {
            return Response::notFound(__('terms.error_taxonomy_mismatch'));
        }

        $mode = TemplateResolver::mode($config);
        $fieldsMode = TemplateResolver::fieldsMode($config);
        // Selected template: comes from the form on POST; from the term (or null) on GET.
        $selectedTemplate = $term?->template;
        if ($request->isPost()) {
            $selectedTemplate = $mode === 'list' ? trim((string) ($request->post['template'] ?? '')) : null;
            if ($selectedTemplate === '') $selectedTemplate = null;
        }
        $fieldDefs = $this->app->config->resolveFields(TemplateResolver::fieldsFor($config, $selectedTemplate));

        if ($request->isPost()) {
            $csrf = $this->verifyCsrfOrFail($request);
            if ($csrf !== null) return $csrf;

            $name = trim((string) ($request->post['name'] ?? ''));
            $slug = trim((string) ($request->post['slug'] ?? ''));

            // List mode: template selection is mandatory and the key must
            // exist. This needs to stay the first validation in the block —
            // before ANY save, including the round-trip further below.
            if ($mode === 'list') {
                $options = TemplateResolver::options($config);
                if ($selectedTemplate === null || !isset($options[$selectedTemplate])) {
                    $this->app->session->flash('error', __('terms.error_choose_template'));
                    return Response::redirect($request->server['REQUEST_URI'] ?? admin_url("taxonomies/{$taxonomy}"));
                }
            }

            // Parent term (only when the taxonomy is hierarchical). Validates:
            // it exists, is of the same taxonomy, and is not the term itself
            // nor one of its descendants.
            $hierarchical = $this->app->config->isTaxonomyHierarchical($taxonomy);
            $parentId = null;
            if ($hierarchical) {
                $rawParent = trim((string) ($request->post['parent_id'] ?? ''));
                $parentId = $rawParent === '' ? null : (int) $rawParent;
                $parentErr = $this->validateTermParent($taxonomy, $parentId, $term?->id);
                if ($parentErr !== null) {
                    $this->app->session->flash('error', $parentErr);
                    return Response::redirect($request->server['REQUEST_URI'] ?? admin_url("taxonomies/{$taxonomy}"));
                }
            }

            if ($name === '') {
                $this->app->session->flash('error', __('terms.error_name_required'));
                return Response::redirect($request->server['REQUEST_URI'] ?? admin_url("taxonomies/{$taxonomy}"));
            }
            $slug = slugify($slug !== '' ? $slug : $name);
            $slug = $this->ensureUniqueSlug($taxonomy, $slug, $term?->id, $parentId);

            // Per-template fields: when SWITCHING the template of an existing
            // term, persist name/slug/template/parent_id — already
            // validated/prepared above — and reload the fields for the new
            // template. The per-template fields submitted in this request
            // belong to the OLD template, so they are neither collected nor
            // saved on this pass.
            if ($mode === 'list' && $fieldsMode === 'per_template' && $term !== null && $selectedTemplate !== $term->template) {
                $term->save([
                    'name' => $name,
                    'slug' => $slug,
                    'parent_id' => $parentId,
                    'template' => $selectedTemplate,
                ]);
                $this->app->session->flash('success', __('terms.flash_template_changed'));
                return Response::redirect(admin_url("taxonomies/{$taxonomy}/{$term->id}"));
            }

            $fieldValues = FieldRenderer::collect($fieldDefs, $request->post['fields'] ?? []);
            VariantService::ensureForFields($fieldDefs, $fieldValues);

            $payload = [
                'name' => $name,
                'slug' => $slug,
                'parent_id' => $parentId,
                'template' => $selectedTemplate,
                'fields' => $fieldValues,
            ];

            // i18n Phase 3 — collect name/slug translations.
            //
            // MERGE, not replace: only languages actually PRESENT in the
            // POST are touched — a language whose panel wasn't submitted
            // (disabled at render time, or a trimmed/tampered form) keeps
            // its existing stored translation. For a brand new term
            // ($term === null) the "existing" maps are empty, so this
            // degrades to the old from-scratch behavior.
            if ($this->app->config->isI18nEnabled()) {
                $defaultLang = $this->app->config->defaultLanguage();
                $nameUpdates = [];
                $slugUpdates = [];
                $fieldsUpdates = [];

                $postedNames  = (array) ($request->post['name_translations'] ?? []);
                $postedSlugs  = (array) ($request->post['slug_translations'] ?? []);
                $postedFields = (array) ($request->post['fields_translations'] ?? []);

                foreach (\Velk\Models\Language::all(onlyEnabled: true) as $lang) {
                    if ($lang->code === $defaultLang) continue;

                    if (array_key_exists($lang->code, $postedNames)) {
                        $n = trim((string) $postedNames[$lang->code]);
                        $nameUpdates[$lang->code] = $n !== '' ? $n : null;
                    }

                    if (array_key_exists($lang->code, $postedSlugs)) {
                        $s = trim((string) $postedSlugs[$lang->code]);
                        $slugUpdates[$lang->code] = $s !== '' ? slugify($s) : null;
                    }

                    if (array_key_exists($lang->code, $postedFields)) {
                        $f = FieldRenderer::collect($fieldDefs, (array) $postedFields[$lang->code]);
                        // Type-aware: only writes what the translator actually
                        // changed — empty booleans/images no longer pollute the
                        // fallback (see FieldRenderer::translationOverrides).
                        $f = FieldRenderer::translationOverrides($fieldDefs, $f, $fieldValues);
                        $fieldsUpdates[$lang->code] = $f !== [] ? $f : null;
                    }
                }

                $payload['name_translations']   = self::mergeTranslations($term?->nameTranslations ?? [], $nameUpdates);
                $payload['slug_translations']   = self::mergeTranslations($term?->slugTranslations ?? [], $slugUpdates);
                $payload['fields_translations'] = self::mergeTranslations($term?->fieldsTranslations ?? [], $fieldsUpdates);
            }

            if ($term === null) {
                $payload['taxonomy'] = $taxonomy;
                // Ver o comentário equivalente em ItemController: só no ramo
                // de criação, senão editar um termo existente o empurraria
                // pro fim a cada save.
                if (Term::manualOrder($taxonomy)) {
                    $payload['sort_order'] = Term::nextSortOrder($taxonomy);
                }
                $term = Term::create($payload);
            } else {
                $term->save($payload);
            }

            $this->app->session->flash('success', __('terms.flash_saved'));
            $this->flashSlugConflicts(SlugConflicts::forTerm(Term::find($term->id) ?? $term));
            return Response::redirect(admin_url("taxonomies/{$taxonomy}/{$term->id}"));
        }

        return $this->render('terms/edit', [
            'taxonomy' => $taxonomy,
            'taxonomyConfig' => $config,
            'term' => $term,
            'fieldDefs' => $fieldDefs,
            'templateMode' => $mode,
            'templateOptions' => TemplateResolver::options($config),
            'fieldsMode' => $fieldsMode,
            'selectedTemplate' => $selectedTemplate,
            'hierarchical' => $this->app->config->isTaxonomyHierarchical($taxonomy),
            'parentOptions' => $this->app->config->isTaxonomyHierarchical($taxonomy)
                ? $this->termParentOptions($taxonomy, $term?->id)
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

        $taxonomy = (string) ($params['taxonomy'] ?? '');
        $id = (int) ($params['id'] ?? 0);
        $term = Term::find($id);
        if ($term !== null && $term->taxonomy === $taxonomy) {
            $term->delete();
            $this->app->session->flash('success', __('terms.flash_deleted'));
        }
        return Response::redirect(admin_url("taxonomies/{$taxonomy}"));
    }

    /**
     * Exclusão em massa. Mesma sequência do delete() individual — auth,
     * csrf, checagem de escopo por id — mas em laço sobre uma lista de
     * ids. Deleta um a um via Term::delete() (não um DELETE...WHERE IN em
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

        $taxonomy = (string) ($params['taxonomy'] ?? '');
        $redirect = admin_url("taxonomies/{$taxonomy}") . ($request->query !== [] ? '?' . http_build_query($request->query) : '');

        $ids = self::parseBulkIds($request->post['ids'] ?? null);
        if ($ids === []) {
            $this->app->session->flash('error', __('terms.bulk_flash_none_selected'));
            return Response::redirect($redirect);
        }

        $deleted = 0;
        foreach ($ids as $id) {
            $term = Term::find($id);
            if ($term !== null && $term->taxonomy === $taxonomy) {
                $term->delete();
                $deleted++;
            }
        }

        $message = $deleted === 1
            ? __('terms.bulk_flash_deleted_one')
            : __('terms.bulk_flash_deleted_other', ['count' => $deleted]);
        $this->app->session->flash($deleted > 0 ? 'success' : 'error', $message);

        return Response::redirect($redirect);
    }

    /**
     * Endpoint do arrasto de termos. Ver ItemController::reorder.
     */
    public function reorder(Request $request, array $params): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;
        $csrf = $this->verifyCsrfOrFail($request);
        if ($csrf !== null) return $csrf;

        $taxonomy = (string) ($params['taxonomy'] ?? '');
        if ($this->app->config->taxonomy($taxonomy) === null || !Term::manualOrder($taxonomy)) {
            return Response::json(['error' => 'manual order is not enabled for this taxonomy'], 404);
        }

        $ids = $request->post['ids'] ?? [];
        if (!is_array($ids)) {
            return Response::json(['error' => 'ids must be a list'], 422);
        }

        Term::applyOrder($taxonomy, $ids);

        return Response::json(['ok' => true]);
    }

    /**
     * Validates the chosen parent term (hierarchical taxonomies only): it
     * must exist, be of the SAME taxonomy, and not be the term itself or
     * one of its descendants (anti-cycle). null = root, always valid.
     */
    private function validateTermParent(string $taxonomy, ?int $parentId, ?int $selfId): ?string
    {
        if ($parentId === null) return null;

        $parent = Term::find($parentId);
        if ($parent === null || $parent->taxonomy !== $taxonomy) {
            return __('terms.error_invalid_parent');
        }
        if ($selfId !== null) {
            if ($parentId === $selfId) {
                return __('terms.error_self_parent');
            }
            if (in_array($parentId, Term::descendantIds($selfId), true)) {
                return __('terms.error_parent_cycle');
            }
        }
        return null;
    }

    /**
     * Options for the "Parent term" dropdown: terms from the same taxonomy
     * as id => name, except the term itself and its descendants.
     *
     * @return array<int,string>
     */
    private function termParentOptions(string $taxonomy, ?int $selfId): array
    {
        $exclude = [];
        if ($selfId !== null) {
            $exclude[$selfId] = true;
            foreach (Term::descendantIds($selfId) as $d) {
                $exclude[$d] = true;
            }
        }
        $opts = [];
        foreach (Term::all($taxonomy) as $t) {
            if (isset($exclude[$t->id])) continue;
            $opts[$t->id] = $t->name;
        }
        return $opts;
    }

    /**
     * Guarantees a slug unique among SIBLINGS (same taxonomy + same parent),
     * generating a -2, -3… suffix on collision. For root terms (or a
     * non-hierarchical taxonomy), $parentId is NULL and uniqueness falls
     * back to all root terms of the taxonomy — same as the old behavior.
     */
    private function ensureUniqueSlug(string $taxonomy, string $slug, ?int $excludeId = null, ?int $parentId = null): string
    {
        $base = $slug;
        $counter = 1;
        while (true) {
            $parentCond = $parentId === null ? 'parent_id IS NULL' : 'parent_id = ?';
            $sql = "SELECT id FROM terms WHERE taxonomy = ? AND {$parentCond} AND slug = ?";
            $params = $parentId === null ? [$taxonomy, $slug] : [$taxonomy, $parentId, $slug];
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
