<?php

declare(strict_types=1);

namespace Velk;

use Velk\Models\Item;
use Velk\Models\Language;
use Velk\Models\Page;
use Velk\Models\Term;

/**
 * Sitemap.xml generator.
 *
 * Produces a single urlset (sitemaps protocol 0.9) covering:
 *   - All configured pages (their resolved paths)
 *   - For every item type with `has_page: true`:
 *       - Each published item single (e.g. /blog/post-slug)
 *   - For every taxonomy referenced by a paged item type, every term page
 *     (e.g. /categoria/term-slug)
 *
 * URLs are emitted as canonical absolute URLs using `APP_URL` from `.env`
 * when set, falling back to the request scheme/host so sitemaps still work
 * in local dev. The canonical origin is intentionally NOT read from the
 * theme — the theme is project-neutral; deployment URL belongs to `.env`.
 * `APP_URL` is origin-only (never a subdirectory) — `APP_BASE_PATH` is
 * always appended on top, same convention as `absolute_url()` in
 * `core/helpers.php`.
 *
 * i18n: when i18n is enabled and additional languages are registered,
 * every URL emits `<xhtml:link rel="alternate" hreflang="...">` entries
 * for each language version (plus x-default pointing at the default).
 * Every language gets an alternate: the translated slug, or the default
 * one where the entity has no translated slug — the same URL the router
 * answers at.
 */
final class Sitemap
{
    public static function generate(Config $config): string
    {
        $base = self::resolveBaseUrl();
        $entries = [];
        $seen = [];
        $i18nEnabled = $config->isI18nEnabled();
        $defaultLang = $config->defaultLanguage();

        // Pre-load enabled non-default languages once.
        $extraLanguages = $i18nEnabled ? Language::all(onlyEnabled: true) : [];

        $add = function (string $loc, ?string $lastmod, array $alternates = []) use (&$entries, &$seen): void {
            if (isset($seen[$loc])) return;
            $seen[$loc] = true;
            $entries[] = ['loc' => $loc, 'lastmod' => $lastmod, 'alternates' => $alternates];
        };

        // Language codes the sitemap covers, default first; the Language
        // rows give the URL prefix of the extra ones.
        $langs = [$defaultLang => null];
        foreach ($extraLanguages as $lang) {
            if ($lang->code !== $defaultLang) $langs[$lang->code] = $lang;
        }
        $withAlternates = $i18nEnabled && count($langs) > 1;

        // An entry per record: its default-language URL as <loc> and, per
        // language where it has a URL, an alternate. A record that lost a
        // slug conflict in a language (its path there opens another one)
        // gets no alternate in that language — and no entry at all when it
        // lost in the default language.
        $entry = function (callable $owns, callable $pathIn, ?string $lastmod) use ($langs, $defaultLang, $withAlternates, $base, $add): void {
            if (!$owns($defaultLang)) return;
            $loc = $base . self::normalizePathForUrl($pathIn($defaultLang));
            $alternates = [];
            if ($withAlternates) {
                $alternates[$defaultLang] = $loc;
                foreach ($langs as $code => $lang) {
                    if ($lang === null || !$owns($code)) continue;
                    $alternates[$code] = self::langUrl($base, $lang, $pathIn($code));
                }
            }
            $add($loc, $lastmod, $alternates);
        };

        // 1. Pages — the site.json ones (except the page set as the 404, which
        // has no public URL) and the admin-created ones whose template still
        // exists. All page rows are read once (Page::withAllRows): the paths
        // walk parent chains through findByKey().
        Page::withAllRows(function () use ($config, $langs, $entry): void {
            $notFoundKey = $config->notFoundPageKey();
            $pages = [];
            foreach ($config->pages() as $key => $pageDef) {
                $key = (string) $key;
                if ($key !== $notFoundKey) {
                    $pages[] = [$key, self::pageLastmod($key)];
                }
            }
            foreach (Page::allCustom() as $custom) {
                if ($config->pageTemplate((string) $custom->template) === null) continue;
                $pages[] = [$custom->key, $custom->updatedAt !== null ? self::formatDate($custom->updatedAt) : null];
            }
            $pagePaths = [];
            $pageTaken = [];
            foreach ($pages as [$key]) {
                foreach (array_keys($langs) as $code) {
                    $path = Page::fullPath($key, $config, $code);
                    $pagePaths[$code][$key] = $path;
                    $pageTaken[$code][$path] = ($pageTaken[$code][$path] ?? 0) + 1;
                }
            }
            foreach ($pages as [$key, $lastmod]) {
                $entry(
                    fn(string $code): bool => $pageTaken[$code][$pagePaths[$code][$key]] === 1
                        || Page::ownsUrlIn($key, $config, $code),
                    fn(string $code): string => $pagePaths[$code][$key],
                    $lastmod
                );
            }
        });

        // 2. Items.
        foreach ($config->itemTypes() as $type => $def) {
            if (($def['has_page'] ?? true) === false) continue;

            $type = (string) $type;
            $typeSlug = trim((string) ($def['slug'] ?? $type), '/');
            if ($typeSlug === '') continue;

            // Every item of the type, in light rows and any status: a draft
            // can be an ancestor, or sit on the same path as a published one.
            $nodes = [];
            $rows = App::instance()->db->fetchAll(
                'SELECT id, slug, parent_id, slug_translations FROM items WHERE type = ?',
                [$type]
            );
            foreach ($rows as $row) {
                $nodes[(int) $row['id']] = [
                    'slug' => (string) $row['slug'],
                    'parent' => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
                    'slugs' => is_string($row['slug_translations']) ? (array) (json_decode($row['slug_translations'], true) ?? []) : [],
                ];
            }
            $prefixes = [];
            foreach (array_keys($langs) as $code) {
                $prefixes[$code] = $config->itemTypeSlugIn($type, $code);
            }
            $paths = self::routePaths($nodes, $prefixes, $defaultLang, $config->isItemTypeHierarchical($type));

            foreach (Item::published($type, 5000) as $item) {
                if (!isset($paths[$defaultLang]['path'][$item->id])) continue;
                $entry(
                    fn(string $code): bool => self::owns($paths, $code, $item),
                    fn(string $code): string => $paths[$code]['path'][$item->id],
                    self::itemLastmod($item)
                );
            }
        }

        // 3. Taxonomies (only paged ones).
        //
        // Two filters here. The pagedTaxonomies() helper is a smart
        // default: it skips taxonomies that no `has_page: true` item
        // type references — those archives wouldn't have content to
        // list anyway. On top of that, the explicit has_page: false
        // on the taxonomy itself is respected — admin can mark a
        // taxonomy as embed-only even when referenced by paged types.
        $referenced = self::pagedTaxonomies($config);
        foreach ($referenced as $taxKey) {
            $taxDef = $config->taxonomy($taxKey);
            if ($taxDef === null) continue;
            if (($taxDef['has_page'] ?? true) === false) continue;

            $taxSlug = trim((string) ($taxDef['slug'] ?? $taxKey), '/');
            if ($taxSlug === '') continue;

            $terms = Term::all($taxKey);
            $nodes = [];
            foreach ($terms as $term) {
                $nodes[$term->id] = ['slug' => $term->slug, 'parent' => $term->parentId, 'slugs' => $term->slugTranslations];
            }
            $prefixes = [];
            foreach (array_keys($langs) as $code) {
                $prefixes[$code] = $config->taxonomySlugIn($taxKey, $code);
            }
            $paths = self::routePaths($nodes, $prefixes, $defaultLang, $config->isTaxonomyHierarchical($taxKey));

            foreach ($terms as $term) {
                $entry(
                    fn(string $code): bool => self::owns($paths, $code, $term),
                    fn(string $code): string => $paths[$code]['path'][$term->id],
                    null
                );
            }
        }

        return self::buildXml($entries, $i18nEnabled);
    }

    /**
     * Absolute URL of a route path in a language: base + the language's
     * URL prefix + the path. Home ('/') becomes the language root
     * (`/en/`). Pure — no DB access.
     */
    private static function langUrl(string $base, Language $lang, string $path): string
    {
        $prefix = $lang->urlPrefix !== null ? $lang->urlPrefix : '';
        return $base . $prefix . self::normalizePathForUrl($path);
    }

    private static function resolveBaseUrl(): string
    {
        // app_origin() (core/helpers.php) resolves APP_URL — origin ONLY,
        // falling back to the request's scheme+host — and App::instance()
        // ->basePath is appended unconditionally, so a configured APP_URL
        // and the request-derived fallback agree on the subdirectory
        // convention (APP_URL never includes it; see absolute_url() /
        // .env.example). Previously APP_URL, when set, was used as-is —
        // silently dropping APP_BASE_PATH from every <loc> on subdirectory
        // installs.
        return rtrim(app_origin() . App::instance()->basePath, '/');
    }

    private static function normalizePathForUrl(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }
        return '/' . trim($path, '/');
    }

    /**
     * Route path of every record of one item type or taxonomy in each
     * language, from records loaded once — the same rule as
     * Item/Term::fullPath (each level's translated slug, or the default one
     * where the level has none), without a query per record. `taken` counts
     * the records on each path: more than one is a slug conflict, decided by
     * owns().
     *
     * @param array<int, array{slug: string, parent: ?int, slugs: array}> $nodes by id
     * @param array<string, string> $prefixes language => type/taxonomy prefix
     * @return array<string, array{path: array<int, string>, taken: array<string, int>}>
     */
    private static function routePaths(array $nodes, array $prefixes, string $defaultLang, bool $hierarchical): array
    {
        $out = [];
        foreach ($prefixes as $lang => $prefix) {
            $out[$lang] = ['path' => [], 'taken' => []];
            $resolve = static function (string $id) use ($nodes, $lang, $defaultLang, $hierarchical): ?array {
                $node = $nodes[(int) $id] ?? null;
                if ($node === null) return null;
                $translated = $lang === $defaultLang ? '' : ($node['slugs'][$lang] ?? '');
                return [
                    'segment' => $translated !== '' && $translated !== null ? (string) $translated : $node['slug'],
                    'parent' => $hierarchical && $node['parent'] !== null ? (string) $node['parent'] : null,
                ];
            };
            $root = '/' . trim($prefix, '/');
            foreach (array_keys($nodes) as $id) {
                $path = $root . PathChain::build((string) $id, $resolve);
                $out[$lang]['path'][$id] = $path;
                $out[$lang]['taken'][$path] = ($out[$lang]['taken'][$path] ?? 0) + 1;
            }
        }
        return $out;
    }

    /**
     * The record owns its path in $lang: it's the only one there, or the
     * one the router opens there (a slug conflict, decided like the router —
     * the only case that queries the database).
     */
    private static function owns(array $paths, string $lang, Item|Term $record): bool
    {
        $path = $paths[$lang]['path'][$record->id] ?? null;
        if ($path === null) return false;
        return $paths[$lang]['taken'][$path] === 1 || $record->ownsUrlIn($lang);
    }

    private static function pageLastmod(string $key): ?string
    {
        $page = Page::findByKey($key);
        if ($page === null || empty($page->updatedAt)) {
            return null;
        }
        return self::formatDate($page->updatedAt);
    }

    private static function itemLastmod(Item $item): ?string
    {
        $stamp = $item->updatedAt !== '' ? $item->updatedAt : $item->publishedAt;
        return $stamp !== null && $stamp !== '' ? self::formatDate($stamp) : null;
    }

    private static function formatDate(string $stamp): ?string
    {
        $ts = strtotime($stamp);
        if ($ts === false) return null;
        return gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    private static function pagedTaxonomies(Config $config): array
    {
        $referenced = [];
        foreach ($config->itemTypes() as $def) {
            if (($def['has_page'] ?? true) === false) continue;
            foreach ((array) ($def['taxonomies'] ?? []) as $taxKey) {
                $referenced[(string) $taxKey] = true;
            }
        }
        return array_keys($referenced);
    }

    /**
     * Render the urlset XML. When alternates are present on any entry,
     * the xhtml namespace is added to the root so <xhtml:link> tags
     * are valid.
     */
    private static function buildXml(array $entries, bool $i18nEnabled): string
    {
        $hasAlternates = $i18nEnabled && self::anyAlternates($entries);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
        if ($hasAlternates) {
            $xml .= ' xmlns:xhtml="http://www.w3.org/1999/xhtml"';
        }
        $xml .= '>' . "\n";

        foreach ($entries as $entry) {
            $xml .= "  <url>\n";
            $xml .= "    <loc>" . htmlspecialchars($entry['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</loc>\n";
            if (!empty($entry['lastmod'])) {
                $xml .= "    <lastmod>" . $entry['lastmod'] . "</lastmod>\n";
            }

            if (!empty($entry['alternates'])) {
                foreach ($entry['alternates'] as $lang => $href) {
                    $xml .= "    <xhtml:link rel=\"alternate\" hreflang=\""
                          . htmlspecialchars((string) $lang, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                          . "\" href=\""
                          . htmlspecialchars((string) $href, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                          . "\"/>\n";
                }
                // x-default points at the original (default-language) URL —
                // Google's recommended catch-all for users whose language
                // doesn't match any explicit alternate.
                $xml .= "    <xhtml:link rel=\"alternate\" hreflang=\"x-default\" href=\""
                      . htmlspecialchars($entry['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . "\"/>\n";
            }

            $xml .= "  </url>\n";
        }
        $xml .= '</urlset>' . "\n";
        return $xml;
    }

    private static function anyAlternates(array $entries): bool
    {
        foreach ($entries as $entry) {
            if (!empty($entry['alternates'])) return true;
        }
        return false;
    }
}
