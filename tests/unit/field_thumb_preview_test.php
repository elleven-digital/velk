<?php

declare(strict_types=1);

use Velk\App;
use Velk\Config;
use Velk\Database;
use Velk\FieldRenderer;

require_once dirname(__DIR__, 2) . '/core/helpers.php';

/**
 * Task 6 (image-variants): admin field previews (image / file / gallery)
 * resolve their small <img> preview through Media::url('thumb') instead of
 * the full original. Media::url()'s own existence-check/fallback logic is
 * already covered end-to-end by variant_resolution_test.php — this file is
 * a markup guard one level up: it proves FieldRenderer actually asks for
 * the 'thumb' variant at these specific preview call sites, so a future
 * refactor that quietly drops back to bare url()/'full' gets caught here
 * instead of only showing up as "previews look a bit big" in manual QA.
 *
 * App wiring composes two idioms already used elsewhere in this suite:
 *  - variant_resolution_test.php's minimal reflection Config (isolated temp
 *    uploads dir, nothing real touched) + an in-memory SQLite `media` row so
 *    Media::find() resolves for real;
 *  - field_file_test.php's real adminI18n catalog, since FieldRenderer's
 *    chrome (button labels, etc.) goes through __().
 */

if (!function_exists('ftp_tmp_dir')) {
    function ftp_tmp_dir(): string
    {
        $dir = sys_get_temp_dir() . '/velk-field-thumb-' . uniqid();
        mkdir($dir, 0775, true);
        return $dir;
    }
}

if (!function_exists('ftp_rrmdir')) {
    /** Mesma limpeza recursiva de tmp dir usada em variant_resolution_test.php. */
    function ftp_rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? ftp_rrmdir($path) : @unlink($path);
        }
        if (!@rmdir($dir)) {
            clearstatcache(true, $dir);
            @rmdir($dir);
        }
    }
}

if (!function_exists('ftp_app')) {
    /**
     * App with an isolated temp uploads dir (reflection Config, same
     * technique as variant_resolution_test.php's vr_app()), a real
     * adminI18n catalog (same technique as field_file_test.php), and an
     * in-memory SQLite `media` table holding one image row (id 7,
     * foto.jpg) so Media::find(7) — which FieldRenderer's image/file/
     * gallery previews call internally — resolves for real.
     */
    function ftp_app(string $uploadsPath): App
    {
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, [
            'paths' => ['uploads' => $uploadsPath],
        ]);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, []);

        $app = new App($config);
        $app->basePath = '';
        $app->adminI18n = new \Velk\Admin\I18n(dirname(__DIR__, 2) . '/core/Admin/lang', 'en', 'en');

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec(
            'CREATE TABLE media (
                id INTEGER PRIMARY KEY, filename TEXT, original_name TEXT,
                mime TEXT, size INTEGER, width INTEGER, height INTEGER,
                alt TEXT, meta TEXT, created_at TEXT, alt_translations TEXT
            )'
        );
        $stmt = $pdo->prepare(
            'INSERT INTO media
                (id, filename, original_name, mime, size, width, height, alt, meta, created_at, alt_translations)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([7, 'foto.jpg', 'foto.jpg', 'image/jpeg', 123, 800, 600, null, '[]', '2026-01-01 00:00:00', null]);

        $db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Database::class, 'pdo'))->setValue($db, $pdo);
        $app->db = $db;

        return $app;
    }
}

// ─── image field ────────────────────────────────────────────────────────

test('image field preview: thumb no disco → <img> aponta pra _variants/thumb', function () {
    $dir = ftp_tmp_dir();
    ftp_app($dir);
    mkdir($dir . '/_variants/thumb', 0775, true);
    touch($dir . '/_variants/thumb/foto.jpg');

    $html = FieldRenderer::render(['type' => 'image', 'name' => 'capa', 'label' => 'Capa'], 7, 'fields');

    check(str_contains($html, 'src="/uploads/_variants/thumb/foto.jpg"'), 'preview do campo image deveria usar a variante thumb');
    check(!str_contains($html, 'src="/uploads/foto.jpg"'), 'não deveria cair no original quando a thumb existe');

    ftp_rrmdir($dir);
});

test('image field preview: sem thumb gerada ainda → cai pro original (fallback do Task 5, imagem pré-existente)', function () {
    $dir = ftp_tmp_dir();
    ftp_app($dir);

    $html = FieldRenderer::render(['type' => 'image', 'name' => 'capa', 'label' => 'Capa'], 7, 'fields');

    check(str_contains($html, 'src="/uploads/foto.jpg"'), 'sem variante no disco, preview deveria cair pro original (não quebrar)');

    ftp_rrmdir($dir);
});

// ─── file field (categoria imagem) ─────────────────────────────────────

test('file field preview (imagem): thumb no disco → <img> aponta pra _variants/thumb', function () {
    $dir = ftp_tmp_dir();
    ftp_app($dir);
    mkdir($dir . '/_variants/thumb', 0775, true);
    touch($dir . '/_variants/thumb/foto.jpg');

    $html = FieldRenderer::render(['type' => 'file', 'name' => 'anexo', 'label' => 'Anexo'], 7, 'fields');

    check(str_contains($html, 'file-field__thumb'), 'sanity: deveria ter renderizado o preview de imagem');
    check(str_contains($html, 'src="/uploads/_variants/thumb/foto.jpg"'), 'preview do campo file deveria usar a variante thumb');

    ftp_rrmdir($dir);
});

// ─── gallery field ──────────────────────────────────────────────────────

test('gallery field: item com thumb no disco → <img> aponta pra _variants/thumb', function () {
    $dir = ftp_tmp_dir();
    ftp_app($dir);
    mkdir($dir . '/_variants/thumb', 0775, true);
    touch($dir . '/_variants/thumb/foto.jpg');

    $html = FieldRenderer::render(['type' => 'gallery', 'name' => 'fotos', 'label' => 'Fotos'], [7], 'fields');

    check(str_contains($html, 'gallery-field__thumb'), 'sanity: deveria ter renderizado o item da galeria');
    check(str_contains($html, 'src="/uploads/_variants/thumb/foto.jpg"'), 'thumb do item da galeria deveria usar a variante thumb');

    ftp_rrmdir($dir);
});
