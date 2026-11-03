<?php

declare(strict_types=1);

namespace Velk;

use Velk\Models\Item;
use Velk\Models\Language;
use Velk\Models\Page;
use Velk\Models\Term;
use Velk\TemplateResolver;

final class FrontRouter
{
    /** A resposta em montagem é a página de 404 (ver renderingNotFound()). */
    private static bool $notFound = false;

    /**
     * O tema está montando a página de 404? Os helpers de idioma
     * (the_hreflangs) não anunciam versões de um endereço que não existe.
     */
    public static function renderingNotFound(): bool
    {
        return self::$notFound;
    }

    public static function register(Router $router, Config $config): void
    {
        $router->fallback(function (Request $request) use ($config) {
            return self::resolve($request, $config);
        });
    }

    public static function resolve(Request $request, Config $config): Response
    {
        self::$notFound = false;
        // HEAD is GET without a body — see Router::dispatch() for the
        // matching table-lookup fix. This fallback runs outside that
        // table, so it needs its own HEAD allowance.
        if (!$request->isGet() && !$request->isHead()) {
            return Response::notFound();
        }

        // UTM attribution: persist any utm_* query params to the session
        // so the next form submission can be attributed to the campaign
        // that brought the visitor in. No-op when no utm_* are present.
        Params::captureFromRequest($request, App::instance()->session);

        $path = '/' . trim($request->path, '/');
        if ($path === '/') {
            $path = '';
        }

        // Built-in /sitemap.xml — covers pages, paged item types, and
        // term archives for taxonomies referenced by paged types. Checked
        // before page resolution so a page configured with url:/sitemap.xml
        // can't accidentally shadow it.
        if ($path === '/sitemap.xml') {
            return Response::xml(Sitemap::generate($config));
        }

        // i18n Phase 2: detect language from URL prefix. When a URL
        // starts with a registered prefix (e.g., /en, /es), we strip
        // it and set the active language for the rest of the request.
        // The default language has no prefix — those URLs flow through
        // unchanged. ?lang= query param (Phase 1 preview) is now
        // superseded by URL routing, but still works as fallback for
        // tooling that builds URLs without prefixes.
        $language = $config->defaultLanguage();
        if ($config->isI18nEnabled()) {
            $stripped = self::detectLanguagePrefix($path);
            if ($stripped !== null) {
                $language = $stripped['language'];
                $path = $stripped['path'];
                App::instance()->i18n->setCurrent($language);
            } else {
                // No prefix → default (or whatever ?lang= preview set).
                $language = App::instance()->i18n->current();
            }
        }

        $page = Page::resolveByUrl($path === '' ? '/' : $path, $config, $language);
        if ($page !== null) {
            return self::renderPage($page, $config) ?? self::renderNotFound($config);
        }

        $resolved = self::resolveContent($path, $config, $language);
        if ($resolved !== null) {
            return $resolved;
        }

        return self::renderNotFound($config);
    }

    /**
     * 404 do front. Quando o site.json aponta uma page via "not_found_page",
     * renderiza essa page (template + fields do tema) com status 404; sem a
     * chave — ou com key quebrada — cai no 404 genérico do engine. O typo é
     * acusado pelo schema:validate; aqui o fallback é silencioso de propósito
     * (o visitante nunca vê um erro de config).
     */
    private static function renderNotFound(Config $config): Response
    {
        $key = $config->notFoundPageKey();
        if ($key === null) {
            return Response::notFound();
        }
        $page = $config->page($key);
        if ($page === null) {
            return Response::notFound();
        }
        self::$notFound = true;
        try {
            return self::renderPage(['key' => $key, 'page' => $page], $config, 404) ?? Response::notFound();
        } finally {
            self::$notFound = false;
        }
    }

    /**
     * Try to match the leading segment of `$path` against any
     * registered language's url_prefix. Returns the language code +
     * remaining path when a match is found, or null when the path
     * has no language prefix (default-language URL).
     *
     * @return array{language: string, path: string}|null
     */
    private static function detectLanguagePrefix(string $path): ?array
    {
        if ($path === '' || $path === '/') return null;

        foreach (Language::all(onlyEnabled: true) as $lang) {
            if ($lang->urlPrefix === null || $lang->urlPrefix === '') continue;

            // Match `/en` exactly (homepage of the language) or
            // `/en/<anything>` (any deeper page).
            if ($path === $lang->urlPrefix) {
                return ['language' => $lang->code, 'path' => ''];
            }
            if (str_starts_with($path, $lang->urlPrefix . '/')) {
                return [
                    'language' => $lang->code,
                    'path' => substr($path, strlen($lang->urlPrefix)),
                ];
            }
        }

        return null;
    }

    /** Null quando o tema não tem o template da página nem o page.php — 404. */
    private static function renderPage(array $resolved, Config $config, int $status = 200): ?Response
    {
        $page = $resolved['page'];
        $key = $resolved['key'];
        $template = (string) ($page['template'] ?? 'page.php');

        $view = new View($config->path('theme') . '/templates');
        $view->share('site', $config->site('site'));
        $view->share('config', $config);

        $context = new TemplateContext(
            type: 'page',
            key: $key,
            data: $page,
        );
        $context->record = Page::findByKey($key);

        $html = self::renderWithFallback($view, $template, ['page' => $context], 'page.php');
        return $html === null ? null : Response::html($html, $status);
    }

    private static function resolveContent(string $path, Config $config, string $language): ?Response
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/')), fn($s) => $s !== ''));
        if (count($segments) === 0) {
            return null;
        }

        // i18n: match against the LOCALIZED slug for the current language.
        // When language == default, itemTypeByLocalizedSlug just returns
        // the site.json `slug` (free, no extra DB hit). For non-default
        // languages, config_translations is checked first.
        $matchedType = $config->itemTypeByLocalizedSlug($segments[0], $language);
        if ($matchedType !== null) {
            $def = $config->itemType($matchedType);
            if ($def !== null && ($def['has_page'] ?? true) !== false) {
                // Não-hierárquico: exatamente /tipo/slug (2 segmentos).
                // Hierárquico: /tipo/<cadeia de slugs> (>= 2); o último é o
                // slug-alvo, a cadeia inteira é conferida por fullPath.
                if (!$config->isItemTypeHierarchical($matchedType)) {
                    if (count($segments) === 2) {
                        return self::renderSingle($matchedType, $def, $segments[1], $config, $language);
                    }
                } elseif (count($segments) >= 2) {
                    return self::resolveItemChain($matchedType, $def, array_slice($segments, 1), $config, $language);
                }
            }
        }

        $matchedTax = $config->taxonomyByLocalizedSlug($segments[0], $language);
        if ($matchedTax !== null) {
            $def = $config->taxonomy($matchedTax);
            // has_page: false marks the taxonomy as embed-only — its
            // terms exist in the admin and can be assigned to items,
            // but the public archive URL 404s cleanly. Mirrors the
            // same flag on item_types.
            if ($def !== null && ($def['has_page'] ?? true) !== false) {
                // Flat: exatamente /taxonomia/slug (2 segmentos).
                // Hierárquica: /taxonomia/<cadeia de slugs> (>= 2); o último
                // é o termo-alvo, a cadeia inteira é descida por findChild.
                if (!$config->isTaxonomyHierarchical($matchedTax)) {
                    if (count($segments) === 2) {
                        return self::renderTaxonomy($matchedTax, $def, $segments[1], $config, $language);
                    }
                } elseif (count($segments) >= 2) {
                    return self::resolveTaxonomyChain($matchedTax, $def, array_slice($segments, 1), $config, $language);
                }
            }
        }

        return null;
    }

    private static function renderSingle(string $type, array $def, string $slug, Config $config, ?string $language = null): ?Response
    {
        // For non-default languages, match the translated slug — or the
        // default slug of an item with no translation in that language
        // (Item::findBySlugIn). Default language: the `slug` column.
        $defaultLang = $config->defaultLanguage();
        if ($language !== null && $language !== $defaultLang) {
            $item = Item::findBySlugIn($type, $slug, $language, $defaultLang);
        } else {
            $item = Item::findBySlug($type, $slug);
        }
        if ($item === null) {
            return null;
        }
        return self::renderItemResponse($type, $def, $item, $config);
    }

    /**
     * Resolve uma cadeia de segmentos (após o slug do tipo) descendo a
     * hierarquia: cada segmento precisa ser o slug de um filho do item
     * anterior. Como slugs só são únicos entre irmãos, casar (pai, slug)
     * por nível é o que distingue ramos com o mesmo slug de folha
     * (/docs/guia-a/intro vs /docs/guia-b/intro).
     */
    private static function resolveItemChain(string $type, array $def, array $segments, Config $config, ?string $language = null): ?Response
    {
        $defaultLang = $config->defaultLanguage();
        $item = Item::findByPath($type, $segments, $language ?? $defaultLang, $defaultLang);
        if ($item === null) {
            return null;
        }
        return self::renderItemResponse($type, $def, $item, $config);
    }

    private static function renderItemResponse(string $type, array $def, Item $item, Config $config): ?Response
    {
        // Drafts are private. Anyone visiting the public URL of a draft gets
        // a 404 — same as if it didn't exist — UNLESS they're authenticated
        // in the admin, in which case the draft renders normally so editors
        // can preview their work before publishing.
        if ($item->status !== 'published' && !App::instance()->auth->check()) {
            return null;
        }

        $view = new View($config->path('theme') . '/templates');

        // I8.2: fileFor() already falls custom key → the type's own
        // `template` when the saved key doesn't resolve (entry removed/
        // renamed in site.json). If NEITHER resolves, fall back one more
        // level to the theme's single.php — same chain AGENTS.md documents
        // — so an item never 404s just because a template entry vanished.
        // A real 404 only happens when single.php isn't in the theme either.
        $template = TemplateResolver::fileFor($def, $item->template);
        if ($template === null) {
            if (!is_file($view->basePath() . '/single.php')) {
                return null;
            }
            $template = 'single.php';
        }

        $view->share('site', $config->site('site'));
        $view->share('config', $config);

        $context = new TemplateContext(type: 'single', key: $type, data: $def);
        $context->record = $item;

        $html = self::renderWithFallback($view, $template, ['item' => $context], 'single.php');
        return $html === null ? null : Response::html($html);
    }

    private static function renderTaxonomy(string $taxonomy, array $def, string $slug, Config $config, ?string $language = null): ?Response
    {
        $defaultLang = $config->defaultLanguage();
        if ($language !== null && $language !== $defaultLang) {
            $term = Term::findBySlugIn($taxonomy, $slug, $language, $defaultLang);
        } else {
            $term = Term::findBySlug($taxonomy, $slug);
        }
        if ($term === null) {
            return null;
        }
        return self::renderTermResponse($taxonomy, $def, $term, $config);
    }

    /**
     * Resolve uma cadeia de segmentos (após o slug da taxonomia) descendo a
     * hierarquia: cada segmento precisa ser o slug de um filho do termo
     * anterior. Como slugs só são únicos entre irmãos, casar (pai, slug) por
     * nível distingue ramos com o mesmo slug de folha.
     */
    private static function resolveTaxonomyChain(string $taxonomy, array $def, array $segments, Config $config, ?string $language = null): ?Response
    {
        $defaultLang = $config->defaultLanguage();
        $term = Term::findByPath($taxonomy, $segments, $language ?? $defaultLang, $defaultLang);
        if ($term === null) {
            return null;
        }
        return self::renderTermResponse($taxonomy, $def, $term, $config);
    }

    private static function renderTermResponse(string $taxonomy, array $def, Term $term, Config $config): ?Response
    {
        $view = new View($config->path('theme') . '/templates');

        // I8.2: same fallback chain as renderItemResponse() — fileFor()
        // already falls custom key → the taxonomy's own `template` when the
        // saved key doesn't resolve (entry removed/renamed in site.json).
        // If NEITHER resolves, fall back one more level to the theme's
        // taxonomy.php (the same file renderWithFallback() below already
        // uses when a RESOLVED filename doesn't exist on disk — this just
        // reaches that same final rung for the missing-key case too), so a
        // term never 404s just because a template entry vanished. A real
        // 404 only happens when taxonomy.php isn't in the theme either.
        $template = TemplateResolver::fileFor($def, $term->template);
        if ($template === null) {
            if (!is_file($view->basePath() . '/taxonomy.php')) {
                return null;
            }
            $template = 'taxonomy.php';
        }

        $view->share('site', $config->site('site'));
        $view->share('config', $config);

        $context = new TemplateContext(type: 'taxonomy', key: $taxonomy, data: $def);
        $context->record = $term;
        $context->records = Item::byTerm($term->id);

        $html = self::renderWithFallback($view, $template, ['term' => $context], 'taxonomy.php');
        return $html === null ? null : Response::html($html);
    }

    /**
     * O template declarado ou, se o arquivo não existe, o de reserva do tema
     * (page.php, single.php, taxonomy.php). Null quando nenhum dos dois
     * existe: quem chama responde 404 — um template que falta no tema não é
     * erro 500 para o visitante (o schema:validate aponta o arquivo).
     */
    private static function renderWithFallback(View $view, string $template, array $data, string $fallback): ?string
    {
        $primary = $view->basePath() . '/' . ltrim($template, '/');
        if (!str_ends_with($primary, '.php')) {
            $primary .= '.php';
        }
        if (is_file($primary)) {
            return $view->renderFile($primary, $data);
        }
        if (!is_file($view->basePath() . '/' . $fallback)) {
            return null;
        }
        return $view->render($fallback, $data);
    }
}
