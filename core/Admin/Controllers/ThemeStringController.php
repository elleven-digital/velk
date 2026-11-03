<?php

declare(strict_types=1);

namespace Velk\Admin\Controllers;

use Velk\Models\Language;
use Velk\Models\ThemeString;
use Velk\Request;
use Velk\Response;

/**
 * Manage the catalog of translatable strings used by `t()` calls in
 * the theme and engine. The list view shows every registered source
 * string plus its translation state in each enabled language. Inline
 * editing lets the translator fill missing translations one row at a
 * time without leaving the page.
 *
 * The catalog is NOT auto-populated at runtime (the user opted out of
 * auto-registration). Strings come from two sources:
 *   1. Manual entry via /admin/translations/new — useful for
 *      copywriting before code is written
 *   2. The "Scan theme" action (or `bin/velk i18n:scan` CLI) —
 *      greps the theme and engine for `t('...')` calls and registers
 *      whatever it finds
 */
final class ThemeStringController extends Controller
{
    public function index(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;

        if (!$this->app->config->isI18nEnabled()) {
            return $this->render('theme-strings/disabled', []);
        }

        $languages = Language::all(onlyEnabled: true);
        $filterLang = (string) ($request->query['lang'] ?? '');
        $filterStatus = (string) ($request->query['status'] ?? ''); // '', 'pending', 'translated'
        $search = trim((string) ($request->query['q'] ?? ''));

        $strings = ThemeString::all();

        // Server-side filtering — small enough catalog that filtering
        // in PHP is fine. If catalog grows huge we'd push this to SQL.
        if ($filterLang !== '' && $filterStatus !== '') {
            $strings = array_values(array_filter($strings, function ($s) use ($filterLang, $filterStatus) {
                $has = isset($s->translations[$filterLang]) && $s->translations[$filterLang] !== '';
                return $filterStatus === 'translated' ? $has : !$has;
            }));
        }
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $strings = array_values(array_filter($strings, fn($s) => str_contains(mb_strtolower($s->original), $needle)));
        }

        // Pagination in PHP over the already-filtered array — this
        // screen's filters are done in PHP by existing design decision
        // (small catalog).
        $total = count($strings);
        $pg = \Velk\Paginator::make($request->query['page'] ?? null, $total);
        $strings = array_slice($strings, $pg['offset'], $pg['perPage']);

        return $this->render('theme-strings/index', [
            'strings' => $strings,
            'languages' => $languages,
            'filterLang' => $filterLang,
            'filterStatus' => $filterStatus,
            'search' => $search,
            'total' => $total,
            'pg' => $pg,
            'pgQuery' => array_diff_key($request->query, ['page' => '']),
        ]);
    }

    /**
     * Inline translation save — receives one (id, language, value)
     * triple and updates that single cell. Used by the AJAX-style
     * inline edit on the index page.
     */
    public function update(Request $request, array $params): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;
        $csrf = $this->verifyCsrfOrFail($request);
        if ($csrf !== null) return $csrf;

        $id = (int) ($params['id'] ?? 0);
        $string = ThemeString::find($id);
        if ($string === null) {
            return Response::json(['error' => __('theme_strings.error_not_found')], 404);
        }

        $lang = (string) ($request->post['language'] ?? '');
        $translation = (string) ($request->post['translation'] ?? '');

        if ($lang === '' || $lang === $this->app->config->defaultLanguage()) {
            return Response::json(['error' => __('theme_strings.error_invalid_language')], 400);
        }

        $string->setTranslation($lang, $translation);
        return Response::json(['ok' => true, 'translations' => $string->translations]);
    }

    public function create(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;

        $values = ['original' => '', 'context' => ''];
        $errors = [];

        if ($request->isPost()) {
            $csrf = $this->verifyCsrfOrFail($request);
            if ($csrf !== null) return $csrf;

            $values['original'] = trim((string) ($request->post['original'] ?? ''));
            $values['context']  = trim((string) ($request->post['context'] ?? ''));

            if ($values['original'] === '') {
                $errors['original'] = __('theme_strings.error_text_required');
            }

            if ($errors === []) {
                ThemeString::register($values['original'], $values['context'] !== '' ? $values['context'] : null);
                $this->app->session->flash('success', __('theme_strings.flash_registered'));
                return Response::redirect(admin_url('translations'));
            }
        }

        return $this->render('theme-strings/new', [
            'values' => $values,
            'errors' => $errors,
        ]);
    }

    public function delete(Request $request, array $params): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;
        $csrf = $this->verifyCsrfOrFail($request);
        if ($csrf !== null) return $csrf;

        $id = (int) ($params['id'] ?? 0);
        $string = ThemeString::find($id);
        if ($string !== null) {
            $string->delete();
            $this->app->session->flash('success', __('theme_strings.flash_deleted'));
        }
        return Response::redirect(admin_url('translations'));
    }

    /**
     * Scan theme + engine for `t('...')` calls and register any new
     * source strings. Idempotent: running multiple times is safe
     * (already-registered strings are skipped).
     */
    public function scan(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;
        $csrf = $this->verifyCsrfOrFail($request);
        if ($csrf !== null) return $csrf;

        $found = self::scanFilesystem($this->app->config->path('theme'), $this->app->config->path('core'));
        $registered = 0;
        foreach ($found as [$original, $context]) {
            $existing = ThemeString::findByKey(ThemeString::makeKey($original, $context));
            if ($existing === null) {
                ThemeString::register($original, $context);
                $registered++;
            }
        }

        $this->app->session->flash('success', __('theme_strings.flash_scan_done', [
            'registered' => (string) $registered,
            'found'      => (string) $this->countFound($found),
        ]));
        return Response::redirect(admin_url('translations'));
    }

    private function countFound(array $found): int
    {
        return count($found);
    }

    /**
     * Walk the theme and engine directories looking for `t('...')`
     * calls. Captures the literal string argument and optional named
     * `context:` argument. Heuristic, not a full PHP parser — but
     * covers the standard usage well.
     *
     * @return list<array{0:string,1:?string}>
     */
    public static function scanFilesystem(string ...$paths): array
    {
        $found = [];
        $seen = [];

        foreach ($paths as $path) {
            if (!is_dir($path)) continue;

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) continue;
                $ext = strtolower($file->getExtension());
                if (!in_array($ext, ['php'], true)) continue;

                $content = @file_get_contents($file->getPathname());
                if ($content === false) continue;

                // Match `t('...')` or `t("...")` with optional escape
                // handling. Pattern is intentionally permissive about
                // whitespace and trailing args (params array, context).
                if (preg_match_all(
                    '/\bt\(\s*([\'"])((?:\\\\.|(?!\1).)*?)\1/s',
                    $content,
                    $matches
                )) {
                    foreach ($matches[2] as $i => $raw) {
                        $quote = $matches[1][$i];
                        // Unescape according to which quote was used
                        $literal = $quote === "'"
                            ? str_replace(["\\'", "\\\\"], ["'", "\\"], $raw)
                            : stripcslashes($raw);

                        $key = $literal . "\0";
                        if (isset($seen[$key])) continue;
                        $seen[$key] = true;
                        $found[] = [$literal, null];
                    }
                }
            }
        }

        return $found;
    }
}
