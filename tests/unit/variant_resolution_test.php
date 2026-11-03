<?php

declare(strict_types=1);

use Velk\App;
use Velk\Config;
use Velk\Database;
use Velk\Models\Media;

require_once dirname(__DIR__, 2) . '/core/helpers.php';

/**
 * Task 5 (image-variants): Media::url() variant resolution + fallback, and
 * image_url()'s pass-through of $size into it. PURE existence check — no
 * generation happens on this path (see ImageVariantService's docblock);
 * these tests only touch/remove files under a temp uploads dir to flip
 * is_file() true/false and assert the URL that comes back.
 *
 * App/Config built with the same minimal-reflection technique as
 * url_conv_app() in absolute_url_convention_test.php: Config's private
 * env/site injected directly via ReflectionProperty, no config/env.php or
 * theme/site.json touched.
 */

if (!function_exists('vr_app')) {
    /** Minimal App whose Config->path('uploads') is $uploadsPath and basePath is $basePath. */
    function vr_app(string $uploadsPath, string $basePath = ''): App
    {
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, [
            'paths' => ['uploads' => $uploadsPath],
        ]);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, []);

        $app = new App($config);
        $app->basePath = $basePath;
        return $app;
    }
}

if (!function_exists('vr_tmp_dir')) {
    function vr_tmp_dir(): string
    {
        $dir = sys_get_temp_dir() . '/velk-variant-res-' . uniqid();
        mkdir($dir, 0775, true);
        return $dir;
    }
}

if (!function_exists('vr_rrmdir')) {
    /** Mesma limpeza recursiva de tmp dir usada em image_variant_service_test.php. */
    function vr_rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? vr_rrmdir($path) : @unlink($path);
        }
        if (!@rmdir($dir)) {
            clearstatcache(true, $dir);
            @rmdir($dir);
        }
    }
}

if (!function_exists('vr_media')) {
    /** Media mínima, construída direto (sem DB) — só o que url() lê. */
    function vr_media(string $filename): Media
    {
        $m = new Media();
        $m->id = 1;
        $m->filename = $filename;
        $m->originalName = $filename;
        $m->mime = 'image/jpeg';
        $m->size = 123;
        $m->createdAt = '2026-01-01 00:00:00';
        return $m;
    }
}

// ─── Media::url() — 'full' e '' sempre devolvem o original ────────────

test("Media::url('full'): devolve a URL do original", function () {
    $dir = vr_tmp_dir();
    vr_app($dir, '');
    check_same('/uploads/photo.jpg', vr_media('photo.jpg')->url('full'));
    vr_rrmdir($dir);
});

test("Media::url(''): mesmo tratamento que full — URL do original", function () {
    $dir = vr_tmp_dir();
    vr_app($dir, '/velk');
    check_same('/velk/uploads/photo.jpg', vr_media('photo.jpg')->url(''));
    vr_rrmdir($dir);
});

// ─── Media::url() — variante existe no disco → URL da variante ────────

test("Media::url('card'): arquivo de variante existe → URL sob _variants/card", function () {
    $dir = vr_tmp_dir();
    vr_app($dir, '');
    mkdir($dir . '/_variants/card', 0775, true);
    touch($dir . '/_variants/card/photo.jpg');

    check_same('/uploads/_variants/card/photo.jpg', vr_media('photo.jpg')->url('card'));

    vr_rrmdir($dir);
});

test("Media::url('card'): respeita basePath (instalação em subdiretório)", function () {
    $dir = vr_tmp_dir();
    vr_app($dir, '/velk');
    mkdir($dir . '/_variants/card', 0775, true);
    touch($dir . '/_variants/card/photo.jpg');

    check_same('/velk/uploads/_variants/card/photo.jpg', vr_media('photo.jpg')->url('card'));

    vr_rrmdir($dir);
});

// ─── Media::url() — variante ausente → fallback pro original, NUNCA gera ──

test("Media::url('card'): arquivo de variante ausente → cai pro original (fallback), sem gerar nada", function () {
    $dir = vr_tmp_dir();
    vr_app($dir, '');

    check_same('/uploads/photo.jpg', vr_media('photo.jpg')->url('card'));
    check(!is_dir($dir . '/_variants'), 'url() é resolução pura — não deveria ter criado _variants/');

    vr_rrmdir($dir);
});

test("Media::url('card'): variante existia e foi removida → volta a cair no fallback", function () {
    $dir = vr_tmp_dir();
    vr_app($dir, '');
    $media = vr_media('photo.jpg');

    $variantFile = $dir . '/_variants/card/photo.jpg';
    mkdir(dirname($variantFile), 0775, true);
    touch($variantFile);
    check_same('/uploads/_variants/card/photo.jpg', $media->url('card'), 'sanity: existia');

    unlink($variantFile);
    check_same('/uploads/photo.jpg', $media->url('card'), 'depois de remover, cai no original');

    vr_rrmdir($dir);
});

// ─── Media::url('thumb') — mesma resolução, outro nome de variante ────

test("Media::url('thumb'): existe → URL da variante; ausente → original", function () {
    $dir = vr_tmp_dir();
    vr_app($dir, '');
    $media = vr_media('photo.jpg');

    check_same('/uploads/photo.jpg', $media->url('thumb'), 'sem arquivo → original');

    mkdir($dir . '/_variants/thumb', 0775, true);
    touch($dir . '/_variants/thumb/photo.jpg');
    check_same('/uploads/_variants/thumb/photo.jpg', $media->url('thumb'), 'com arquivo → variante');

    vr_rrmdir($dir);
});

// ─── image_url() — passa $size adiante pro Media::url() (branch int/dígito) ──

if (!function_exists('vr_app_with_media_row')) {
    /**
     * Mesmo App minimalista de vr_app(), com um Database de verdade por
     * baixo — SQLite em memória, injetado via reflection na propriedade
     * privada `pdo` de Velk\Database (zero-dependência, não toca
     * MySQL/.env) — contendo uma linha em `media`, pra exercitar
     * Media::find() de verdade através de image_url().
     */
    function vr_app_with_media_row(string $uploadsPath, string $basePath, int $id, string $filename): App
    {
        $app = vr_app($uploadsPath, $basePath);

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
        $stmt->execute([$id, $filename, $filename, 'image/jpeg', 123, 800, 600, null, '[]', '2026-01-01 00:00:00', null]);

        $db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Database::class, 'pdo'))->setValue($db, $pdo);
        $app->db = $db;

        return $app;
    }
}

test("image_url(id, 'card'): resolve via Media::find() até a mesma URL de variante", function () {
    $dir = vr_tmp_dir();
    vr_app_with_media_row($dir, '', 7, 'foto.png');

    mkdir($dir . '/_variants/card', 0775, true);
    touch($dir . '/_variants/card/foto.png');

    check_same('/uploads/_variants/card/foto.png', image_url(7, 'card'));

    vr_rrmdir($dir);
});

test("image_url(id, 'card'): variante ausente → mesmo fallback de Media::url()", function () {
    $dir = vr_tmp_dir();
    vr_app_with_media_row($dir, '', 7, 'foto.png');

    check_same('/uploads/foto.png', image_url(7, 'card'));

    vr_rrmdir($dir);
});

test("image_url(url, 'card'): URL http passa direto — variante não se aplica", function () {
    check_same('https://x/y.jpg', image_url('https://x/y.jpg', 'card'));
});
