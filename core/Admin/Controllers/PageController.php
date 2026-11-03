<?php

declare(strict_types=1);

namespace Velk\Admin\Controllers;

use Velk\Admin\SlugConflicts;
use Velk\FieldRenderer;
use Velk\Models\Page;
use Velk\Request;
use Velk\Response;
use Velk\Services\VariantService;

final class PageController extends Controller
{
    public function index(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;

        $customTotal = Page::countCustom();
        $pg = \Velk\Paginator::make($request->query['page'] ?? null, $customTotal);

        // O link "Ver" de cada linha monta a cadeia de pais (Page::pageUrl):
        // as linhas de `pages` são lidas uma vez para a lista inteira.
        return Page::withAllRows(fn(): Response => $this->render('pages/index', [
            'pages' => $this->app->config->pages(),
            'customPages' => Page::allCustom($pg['perPage'], $pg['offset']),
            'pageTemplates' => $this->app->config->pageTemplates(),
            'customTotal' => $customTotal,
            'pg' => $pg,
            'pgQuery' => array_diff_key($request->query, ['page' => '']),
        ]));
    }

    public function edit(Request $request, array $params): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;

        $key = (string) ($params['key'] ?? '');

        // Figure out if this is a JSON-defined page or a custom page.
        // JSON keys take precedence — a JSON page always wins over a
        // DB row with the same key (which shouldn't happen but is
        // possible if a JSON page was added later that collides).
        $jsonConfig = $this->app->config->page($key);
        if ($jsonConfig !== null) {
            return $this->editJsonPage($request, $key, $jsonConfig);
        }

        $custom = Page::findByKey($key);
        if ($custom !== null && $custom->isCustom()) {
            return $this->editCustomPage($request, $custom);
        }

        return Response::notFound(__('pages.error_not_found', ['key' => $key]));
    }

    private function editJsonPage(Request $request, string $key, array $config): Response
    {
        $page = Page::findByKey($key);
        // Conflict guard: if a custom page exists with the same key as a
        // JSON page (only possible if site.json was edited after the
        // custom page was created), refuse to treat it as a JSON page —
        // it has its own template + slug + field schema. Tell the user
        // how to resolve it.
        if ($page !== null && $page->isCustom()) {
            $this->app->session->flash('error', __('pages.error_conflict_json', ['key' => $key]));
            return Response::redirect(admin_url('pages'));
        }
        if ($page === null) {
            $page = Page::upsert($key, (string) ($config['label'] ?? $key), []);
        }

        $fieldDefs = $this->app->config->resolveFields($config['fields'] ?? []);
        $seoFields = $this->app->config->seoFields();

        if ($request->isPost()) {
            $csrf = $this->verifyCsrfOrFail($request);
            if ($csrf !== null) return $csrf;

            $allDefs = array_merge($fieldDefs, $seoFields);
            $values = FieldRenderer::collect($allDefs, $request->post['fields'] ?? []);
            VariantService::ensureForFields($allDefs, $values);
            Page::upsert($key, (string) ($config['label'] ?? $key), $values);

            // i18n Phase 3 — JSON pages have title/url from site.json,
            // but translations live in DB (per user design choice
            // — all translations admin-managed, never in JSON). Re-
            // fetch the row after upsert to get its DB id, then push
            // the translation columns separately.
            if ($this->app->config->isI18nEnabled()) {
                $row = Page::findByKey($key);
                if ($row !== null) {
                    [$titleTrans, $slugTrans, $fieldsTrans] = $this->collectPageTranslations(
                        $request,
                        $allDefs,
                        $values,
                        $row->titleTranslations,
                        $row->slugTranslations,
                        $row->fieldsTranslations
                    );
                    $row->saveTranslations($titleTrans, $slugTrans, $fieldsTrans);
                }
            }

            $this->app->session->flash('success', __('pages.flash_updated'));
            $this->flashSlugConflicts(SlugConflicts::forPage($key, $this->app->config));
            return Response::redirect(admin_url('pages/' . $key));
        }

        return $this->render('pages/edit', [
            'pageKey' => $key,
            'pageConfig' => $config,
            'page' => $page,
            'fieldDefs' => $fieldDefs,
            'seoFields' => $seoFields,
            'isCustom' => false,
            'i18nEnabled' => $this->app->config->isI18nEnabled(),
            'defaultLanguage' => $this->app->config->defaultLanguage(),
            'languages' => $this->app->config->isI18nEnabled()
                ? \Velk\Models\Language::all(onlyEnabled: true)
                : [],
        ]);
    }

    private function editCustomPage(Request $request, Page $page): Response
    {
        $templateKey = (string) $page->template;
        $tpl = $this->app->config->pageTemplate($templateKey);

        // If the page references a template that's no longer in
        // site.json, fall back to an empty field schema so the admin
        // can still see the record and either delete it or fix the
        // template reference by re-saving with a different template.
        $fieldDefs = $this->app->config->resolveFields((array) ($tpl['fields'] ?? []));
        $seoFields = $this->app->config->seoFields();

        $values = [
            'title' => $page->title,
            'slug' => $page->slug ?? $page->key,
            'template' => $templateKey,
            'parent' => $page->parentKey ?? '',
        ];
        $errors = [];

        if ($request->isPost()) {
            $csrf = $this->verifyCsrfOrFail($request);
            if ($csrf !== null) return $csrf;

            $values['title'] = trim((string) ($request->post['title'] ?? ''));
            $values['slug'] = self::sanitizeSlug((string) ($request->post['slug'] ?? ''));
            $values['template'] = (string) ($request->post['template'] ?? $templateKey);
            $values['parent'] = trim((string) ($request->post['parent'] ?? ''));

            // Validate the chosen template still exists. Allow switching
            // templates — fields just get re-collected against the new
            // schema. Values keyed to fields that no longer exist are
            // silently dropped (FieldRenderer::collect only sees defs).
            $newTpl = $this->app->config->pageTemplate($values['template']);
            if ($newTpl === null) {
                $errors['template'] = __('pages.error_invalid_template');
            }

            if ($values['title'] === '') {
                $errors['title'] = __('pages.error_title_required');
            }
            if ($values['slug'] === '') {
                $errors['slug'] = __('pages.error_slug_required');
            } else {
                $err = $this->validateSlug($values['slug'], $page->id, $values['parent']);
                if ($err !== null) $errors['slug'] = $err;
            }
            $parentErr = $this->validateParent($values['parent'], $page->key);
            if ($parentErr !== null) $errors['parent'] = $parentErr;

            if ($errors === []) {
                $effectiveDefs = $this->app->config->resolveFields((array) ($newTpl['fields'] ?? []));
                $allDefs = array_merge($effectiveDefs, $seoFields);
                $fieldValues = FieldRenderer::collect($allDefs, $request->post['fields'] ?? []);
                VariantService::ensureForFields($allDefs, $fieldValues);

                $extra = [];
                if ($this->app->config->isI18nEnabled()) {
                    [$titleTrans, $slugTrans, $fieldsTrans] = $this->collectPageTranslations(
                        $request,
                        $allDefs,
                        $fieldValues,
                        $page->titleTranslations,
                        $page->slugTranslations,
                        $page->fieldsTranslations
                    );
                    $extra = [
                        'title_translations'  => $titleTrans,
                        'slug_translations'   => $slugTrans,
                        'fields_translations' => $fieldsTrans,
                    ];
                }

                $page->saveCustom(
                    $values['title'],
                    $values['slug'],
                    $values['template'],
                    $fieldValues,
                    $values['parent'] === '' ? null : $values['parent'],
                    $extra
                );

                $this->app->session->flash('success', __('pages.flash_updated'));
                $this->flashSlugConflicts(SlugConflicts::forPage($page->key, $this->app->config));
                // page_key is stable (decoupled from the slug), so the admin
                // edit URL doesn't change when the slug is renamed.
                return Response::redirect(admin_url('pages/' . $page->key));
            }
        }

        // Synthesize a config dict matching the JSON page format so
        // the edit view can render a consistent header (URL, etc.).
        // The URL uses fullPath (chain of parents) for pages with a parent.
        $currentUrl = Page::fullPath($page->key, $this->app->config);
        $synthConfig = [
            'label' => $values['title'],
            'template' => (string) ($tpl['file'] ?? '—'),
            'url' => $currentUrl,
        ];

        return $this->render('pages/edit', [
            'pageKey' => $page->key,
            'pageConfig' => $synthConfig,
            'page' => $page,
            'fieldDefs' => $fieldDefs,
            'seoFields' => $seoFields,
            'isCustom' => true,
            'values' => $values,
            'errors' => $errors,
            'parentOptions' => $this->parentOptions($page->key),
            'currentUrl' => $currentUrl,
            'pageTemplates' => $this->app->config->pageTemplates(),
            'i18nEnabled' => $this->app->config->isI18nEnabled(),
            'defaultLanguage' => $this->app->config->defaultLanguage(),
            'languages' => $this->app->config->isI18nEnabled()
                ? \Velk\Models\Language::all(onlyEnabled: true)
                : [],
        ]);
    }

    /**
     * Collect title/slug/fields translation bundles from the POST, MERGED
     * into the existing stored translations. Shared by editJsonPage and
     * editCustomPage so the translation-input handling is in one place.
     * Returns [titleT, slugT, fieldsT] — each the FULL, ready-to-save map.
     *
     * MERGE, not replace: only languages actually PRESENT in the POST
     * (array_key_exists on each *_translations[lang] input, not just
     * non-empty) are touched — a language whose panel wasn't submitted
     * (disabled at render time, or a trimmed/tampered form) keeps its
     * existing stored translation instead of being silently wiped.
     *
     * $defaultValues is the default-language collect() output — needed so
     * translationOverrides can drop no-op booleans (and empty media) that
     * would otherwise shadow the default on read. $existingTitle/
     * $existingSlug/$existingFields are the page's CURRENT stored maps
     * (e.g. $row->titleTranslations) — the merge base.
     *
     * @param array<string,mixed> $defaultValues
     * @param array<string,string> $existingTitle
     * @param array<string,string> $existingSlug
     * @param array<string,array<string,mixed>> $existingFields
     * @return array{0: array<string,string>, 1: array<string,string>, 2: array<string,array<string,mixed>>}
     */
    private function collectPageTranslations(
        Request $request,
        array $fieldDefs,
        array $defaultValues,
        array $existingTitle,
        array $existingSlug,
        array $existingFields
    ): array {
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
                $slugUpdates[$lang->code] = $s !== '' ? self::sanitizeSlug($s) : null;
            }

            if (array_key_exists($lang->code, $postedFields)) {
                $f = FieldRenderer::collect($fieldDefs, (array) $postedFields[$lang->code]);
                // Type-aware: only writes what was actually translated —
                // empty booleans/images no longer pollute the fallback (see
                // translationOverrides).
                $f = FieldRenderer::translationOverrides($fieldDefs, $f, $defaultValues);
                $fieldsUpdates[$lang->code] = $f !== [] ? $f : null;
            }
        }

        return [
            self::mergeTranslations($existingTitle, $titleUpdates),
            self::mergeTranslations($existingSlug, $slugUpdates),
            self::mergeTranslations($existingFields, $fieldsUpdates),
        ];
    }

    public function create(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;

        $pageTemplates = $this->app->config->pageTemplates();
        if ($pageTemplates === []) {
            $this->app->session->flash('error', __('pages.error_no_templates'));
            return Response::redirect(admin_url('pages'));
        }

        $values = [
            'title' => '',
            'slug' => '',
            // Default to the first declared template, matching the order
            // the user will see in the dropdown.
            'template' => (string) array_key_first($pageTemplates),
            'parent' => '',
        ];
        $errors = [];

        if ($request->isPost()) {
            $csrf = $this->verifyCsrfOrFail($request);
            if ($csrf !== null) return $csrf;

            $values['title'] = trim((string) ($request->post['title'] ?? ''));
            $values['slug'] = self::sanitizeSlug((string) ($request->post['slug'] ?? ''));
            $values['template'] = (string) ($request->post['template'] ?? '');
            $values['parent'] = trim((string) ($request->post['parent'] ?? ''));

            if ($values['title'] === '') {
                $errors['title'] = __('pages.error_title_required');
            }
            if ($values['slug'] === '') {
                // Empty after sanitization → try to derive from title.
                $values['slug'] = self::sanitizeSlug($values['title']);
                if ($values['slug'] === '') {
                    $errors['slug'] = __('pages.error_slug_required_chars');
                }
            }
            if ($values['slug'] !== '' && !isset($errors['slug'])) {
                $err = $this->validateSlug($values['slug'], null, $values['parent']);
                if ($err !== null) $errors['slug'] = $err;
            }
            if (!isset($pageTemplates[$values['template']])) {
                $errors['template'] = __('pages.error_choose_valid_template');
            }
            $parentErr = $this->validateParent($values['parent'], null);
            if ($parentErr !== null) $errors['parent'] = $parentErr;

            if ($errors === []) {
                $page = Page::createCustom(
                    $values['slug'],
                    $values['title'],
                    $values['template'],
                    [],
                    $values['parent'] === '' ? null : $values['parent']
                );
                $this->app->session->flash('success', __('pages.flash_created'));
                $this->flashSlugConflicts(SlugConflicts::forPage($page->key, $this->app->config));
                // The admin identifier is the page_key (may have a suffix on
                // collision), not the slug — which is now unique only among
                // siblings.
                return Response::redirect(admin_url('pages/' . $page->key));
            }
        }

        return $this->render('pages/new', [
            'pageTemplates' => $pageTemplates,
            'values' => $values,
            'errors' => $errors,
            'parentOptions' => $this->parentOptions(null),
        ]);
    }

    public function delete(Request $request, array $params): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;
        $csrf = $this->verifyCsrfOrFail($request);
        if ($csrf !== null) return $csrf;

        $key = (string) ($params['key'] ?? '');
        $page = Page::findByKey($key);

        if ($page === null || !$page->isCustom()) {
            $this->app->session->flash('error', __('pages.error_only_custom_deletable'));
            return Response::redirect(admin_url('pages'));
        }

        $page->delete();
        $this->app->session->flash('success', __('pages.flash_deleted'));
        return Response::redirect(admin_url('pages'));
    }

    /**
     * Slug validator shared by create() and editCustomPage(). Returns
     * null on success, or an error message string. `$excludeId` skips
     * the page's own row when validating against existing slugs (so
     * "save without changing slug" doesn't false-positive).
     */
    private function validateSlug(string $slug, ?int $excludeId, string $parentKey): ?string
    {
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            return __('pages.error_slug_invalid_chars');
        }

        // 1. Collision between custom SIBLINGS (same parent): the URL would
        //    be identical. The slug is now unique only among siblings, not
        //    across the whole site.
        foreach (Page::siblingsWithSlug($parentKey, $slug) as $sibling) {
            if ($sibling->id !== $excludeId) {
                return __('pages.error_slug_sibling_exists', ['slug' => $slug]);
            }
        }

        // 2. Collision of the FULL URL with a site.json page.
        $parentPath = $parentKey === '' ? '' : rtrim(Page::fullPath($parentKey, $this->app->config), '/');
        $newPath = $parentPath . '/' . $slug;
        foreach ($this->app->config->pages() as $jsonKey => $jsonPage) {
            if (Page::fullPath((string) $jsonKey, $this->app->config) === $newPath) {
                return __('pages.error_url_used_by_json', ['url' => $newPath]);
            }
        }

        // 3. At the root level, the slug can't collide with the prefix of a
        //    content type or taxonomy (they share the URL's root namespace).
        if ($parentKey === '') {
            foreach ($this->app->config->itemTypes() as $type => $def) {
                if (trim((string) ($def['slug'] ?? $type), '/') === $slug) {
                    return __('pages.error_slug_used_by_type', ['slug' => $slug, 'type' => $type]);
                }
            }
            foreach ($this->app->config->taxonomies() as $tax => $def) {
                if (trim((string) ($def['slug'] ?? $tax), '/') === $slug) {
                    return __('pages.error_slug_used_by_taxonomy', ['slug' => $slug, 'tax' => $tax]);
                }
            }
        }

        return null;
    }

    /**
     * Validates the chosen parent page: it must exist (JSON or custom) and
     * cannot be the page itself or one of its descendants (anti-cycle).
     * '' = root (no parent), always valid. Returns an error or null.
     */
    private function validateParent(string $parentKey, ?string $selfKey): ?string
    {
        if ($parentKey === '') return null;

        $isJson = $this->app->config->page($parentKey) !== null;
        if (!$isJson) {
            $parent = Page::findByKey($parentKey);
            if ($parent === null || !$parent->isCustom()) {
                return __('pages.error_invalid_parent');
            }
        }

        if ($selfKey !== null) {
            if ($parentKey === $selfKey) {
                return __('pages.error_self_parent');
            }
            if (in_array($parentKey, Page::descendantKeys($selfKey, $this->app->config), true)) {
                return __('pages.error_parent_cycle');
            }
        }
        return null;
    }

    /**
     * Options for the "Parent page" dropdown: all pages (JSON + custom) as
     * page_key => label, except the 404 page, the page itself, and its
     * descendants (when $selfKey is given, i.e. while editing).
     *
     * @return array<string,string>
     */
    private function parentOptions(?string $selfKey): array
    {
        $exclude = [];
        if ($selfKey !== null) {
            $exclude[$selfKey] = true;
            foreach (Page::descendantKeys($selfKey, $this->app->config) as $d) {
                $exclude[$d] = true;
            }
        }
        $notFound = $this->app->config->notFoundPageKey();

        $opts = [];
        foreach ($this->app->config->pages() as $k => $def) {
            $k = (string) $k;
            if ($k === $notFound || isset($exclude[$k])) continue;
            $opts[$k] = (string) ($def['label'] ?? $k);
        }
        foreach (Page::allCustom() as $cp) {
            if (isset($exclude[$cp->key])) continue;
            $opts[$cp->key] = $cp->title;
        }
        return $opts;
    }

    /**
     * Convert a user string into a URL-safe slug. Mirrors the
     * Content.php slugify approach: transliterate accents to ASCII
     * when iconv is available, lowercase, collapse non-alphanumerics
     * into single hyphens, trim hyphens from the edges.
     */
    private static function sanitizeSlug(string $s): string
    {
        $s = trim($s);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if (is_string($converted) && $converted !== '') {
                $s = $converted;
            }
        }
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $s = trim($s, '-');
        return $s;
    }
}
