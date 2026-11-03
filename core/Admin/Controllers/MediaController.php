<?php

declare(strict_types=1);

namespace Velk\Admin\Controllers;

use Velk\Models\Media;
use Velk\Request;
use Velk\Response;
use Velk\Services\MediaService;

final class MediaController extends Controller
{
    public function index(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;

        $picker = !empty($request->query['picker']);
        $rawAccept = $request->query['accept'] ?? null;
        $accept = self::parseAcceptCategories(is_string($rawAccept) ? $rawAccept : null);
        $rawSearch = $request->query['q'] ?? null;
        $search = self::parseSearch(is_string($rawSearch) ? $rawSearch : null);
        $total = Media::countFiltered($accept, $search);
        $pg = \Velk\Paginator::make($request->query['page'] ?? null, $total, 60);
        $media = Media::listFiltered($accept, $search, $pg['perPage'], $pg['offset']);
        // Com busca, o subtítulo diz "N de M": M é o que a biblioteca (ou o
        // filtro de categoria do seletor) teria sem ela.
        $libraryTotal = $search === '' ? $total : Media::countFiltered($accept, '');

        $pgQuery = array_diff_key($request->query, ['page' => '']);
        if ($search === '') {
            unset($pgQuery['q']);
        }

        return $this->render('media/index', [
            'media' => $media,
            'total' => $total,
            'libraryTotal' => $libraryTotal,
            'search' => $search,
            'pg' => $pg,
            'pgQuery' => $pgQuery,
            'picker' => $picker,
            'accept' => $accept,
            'uploadLimits' => self::uploadLimits(),
            'i18nEnabled' => $this->app->config->isI18nEnabled(),
            'languages' => $this->app->config->isI18nEnabled()
                ? \Velk\Models\Language::all(onlyEnabled: true)
                : [],
        ], $picker ? 'layout/embed' : 'layout/main');
    }

    /**
     * The `q` query param of the library search: trimmed, inner whitespace
     * collapsed, capped at 100 characters (by character, not byte — a cut
     * in the middle of a multibyte character would make the LIKE fail).
     * Pure — unit-testable.
     */
    public static function parseSearch(?string $raw): string
    {
        $search = trim(preg_replace('/\s+/u', ' ', (string) $raw) ?? '');
        return mb_substr($search, 0, 100);
    }

    /**
     * When the POST exceeds post_max_size, PHP discards the ENTIRE body
     * (file + _csrf) — the request arrives with empty POST/FILES despite a
     * large Content-Length, and the failure would look like a cryptic "CSRF
     * mismatch". Pure — unit-testable.
     */
    public static function postWasDiscarded(int $contentLength, array $post, array $files): bool
    {
        return $contentLength > 0 && $post === [] && $files === [];
    }

    /**
     * Parses the `accept` query param (CSV of categories) — only known
     * categories, deduped, order preserved. Pure — unit-testable.
     */
    public static function parseAcceptCategories(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $valid = ['image', 'video', 'audio', 'document', 'archive'];
        $out = [];
        foreach (explode(',', $raw) as $part) {
            $cat = trim($part);
            if (in_array($cat, $valid, true) && !in_array($cat, $out, true)) {
                $out[] = $cat;
            }
        }
        return $out;
    }

    /**
     * Limits for the uploader: per category (app) + effective server ceiling
     * (smaller of upload_max_filesize and post_max_size; 0 = no known
     * ceiling/unlimited).
     */
    private static function uploadLimits(): array
    {
        $upload = ini_bytes((string) ini_get('upload_max_filesize'));
        $post = ini_bytes((string) ini_get('post_max_size'));
        $server = min($upload > 0 ? $upload : PHP_INT_MAX, $post > 0 ? $post : PHP_INT_MAX);
        return [
            'server' => $server === PHP_INT_MAX ? 0 : $server,
            'categories' => \Velk\Services\MediaService::maxSizeMap(),
        ];
    }

    public function upload(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;

        // Upload larger than PHP's ceiling: explain the real cause (see
        // postWasDiscarded) instead of letting it look like a CSRF error.
        if (self::postWasDiscarded((int) ($_SERVER['CONTENT_LENGTH'] ?? 0), $request->post, $_FILES)) {
            return Response::json(['error' => __('media.error_exceeds_server_limit', [
                'limit' => (string) ini_get('post_max_size'),
            ])], 413);
        }

        $csrf = $this->verifyCsrfOrFail($request);
        if ($csrf !== null) return $csrf;

        $file = $request->file('file');
        if ($file === null) {
            return Response::json(['error' => __('media.error_no_file')], 400);
        }

        try {
            $media = (new MediaService())->upload($file);
            return Response::json($this->mediaPayload($media));
        } catch (\Throwable $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    public function update(Request $request, array $params): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;
        $csrf = $this->verifyCsrfOrFail($request);
        if ($csrf !== null) return $csrf;

        $id = (int) ($params['id'] ?? 0);
        $media = Media::find($id);
        if ($media === null) {
            return Response::json(['error' => __('media.error_not_found')], 404);
        }

        $alt = trim((string) ($request->post['alt'] ?? ''));
        $this->app->db->update('media', ['alt' => $alt === '' ? null : $alt], ['id' => $media->id]);

        // i18n: alt_translations posted as `alt_translations[<lang>]`
        // bundle. We only persist non-empty entries; empty inputs
        // clear that language from the JSON map so fallback works.
        if ($this->app->config->isI18nEnabled()) {
            $defaultLang = $this->app->config->defaultLanguage();
            $postedTranslations = (array) ($request->post['alt_translations'] ?? []);
            $clean = [];
            foreach (\Velk\Models\Language::all(onlyEnabled: true) as $lang) {
                if ($lang->code === $defaultLang) continue;
                $value = trim((string) ($postedTranslations[$lang->code] ?? ''));
                if ($value !== '') $clean[$lang->code] = $value;
            }
            $media->setAltTranslations($clean);
        }

        return Response::json(['ok' => true]);
    }

    public function delete(Request $request, array $params): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;
        $csrf = $this->verifyCsrfOrFail($request);
        if ($csrf !== null) return $csrf;

        $id = (int) ($params['id'] ?? 0);
        $media = Media::find($id);
        if ($media === null) {
            if ($request->isAjax()) {
                return Response::json(['error' => __('media.error_not_found')], 404);
            }
            return Response::redirect(admin_url('media'));
        }

        (new MediaService())->delete($media);

        if ($request->isAjax()) {
            return Response::json(['ok' => true]);
        }

        $this->app->session->flash('success', __('media.flash_deleted'));
        return Response::redirect(admin_url('media'));
    }

    public function show(Request $request, array $params): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;

        $id = (int) ($params['id'] ?? 0);
        $media = Media::find($id);
        if ($media === null) {
            return Response::json(['error' => __('media.error_not_found')], 404);
        }
        return Response::json($this->mediaPayload($media));
    }

    private function mediaPayload(Media $media): array
    {
        return [
            'id' => $media->id,
            'url' => $media->url('full'),
            'thumb_url' => $media->isImage() ? $media->url('thumb') : null,
            'filename' => $media->filename,
            'original_name' => $media->originalName,
            'mime' => $media->mime,
            'width' => $media->width,
            'height' => $media->height,
            'size' => $media->size,
            'human_size' => $media->humanSize(),
            'alt' => $media->alt,
            'alt_translations' => $media->altTranslations,
            'is_image' => $media->isImage(),
            'category' => $media->category(),
            'created_at' => $media->createdAt,
        ];
    }
}
