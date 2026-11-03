<?php

declare(strict_types=1);

/**
 * Boot dos testes da janela do bloco (BlockController): App real, Config por
 * reflexão com os blocos dados, SQLite em memória com um administrador,
 * sessão, Auth e o catálogo do painel em inglês — o molde do
 * `form_export_boot.php`.
 */

use Velk\App;
use Velk\Config;
use Velk\Database;
use Velk\Request;

if (!function_exists('blkb_boot')) {
    /**
     * @param array<string, mixed>  $blocks `richtext_blocks` do site.json
     * @param array<string, string> $paths  caminhos além do `core` (uploads, theme)
     */
    function blkb_boot(array $blocks, bool $logged = true, array $paths = []): App
    {
        $root = dirname(__DIR__, 2);
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, [
            'paths' => $paths + ['core' => $root . '/core'],
            'admin' => ['prefix' => '/admin'],
        ]);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, ['richtext_blocks' => $blocks]);

        $app = new App($config);
        $app->basePath = '';

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY, email TEXT, password_hash TEXT, name TEXT,
                role TEXT, two_factor_enabled INTEGER DEFAULT 0, locale TEXT, created_at TEXT
            )'
        );
        $pdo->exec(
            "INSERT INTO users (id, email, password_hash, name, role, two_factor_enabled, locale, created_at)
             VALUES (1, 'admin@teste.dev', '', 'Admin', 'admin', 0, NULL, '2026-01-01 00:00:00')"
        );
        $pdo->exec(
            'CREATE TABLE media (
                id INTEGER PRIMARY KEY, filename TEXT, original_name TEXT, mime TEXT, size INTEGER,
                width INTEGER NULL, height INTEGER NULL, alt TEXT NULL, alt_translations TEXT NULL,
                meta TEXT NULL, created_at TEXT
            )'
        );
        $db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Database::class, 'pdo'))->setValue($db, $pdo);
        $app->db = $db;

        $_SESSION = [];
        $app->session = new \Velk\Session();
        $app->auth = new \Velk\Auth($app->db, $app->session);
        if ($logged) {
            $app->session->put('user_id', 1);
        }
        $app->adminI18n = new \Velk\Admin\I18n($root . '/core/Admin/lang', 'en', 'en');

        return $app;
    }
}

if (!function_exists('blkb_post')) {
    /** Um POST da janela do bloco, com o token de CSRF da sessão (ou o dado). */
    function blkb_post(App $app, string $path, array $post, ?string $csrf = null): Request
    {
        return new Request('POST', $path, [], ['_csrf' => $csrf ?? $app->session->csrfToken()] + $post, [], [], []);
    }
}
