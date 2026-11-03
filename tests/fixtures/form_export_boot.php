<?php

declare(strict_types=1);

/**
 * Boot compartilhado dos testes da exportação em CSV que precisam de banco:
 * App real, Config por reflexão e SQLite em memória com a tabela
 * `form_submissions` — o que `FormSubmission::exportRows()` lê.
 *
 * Mora em `tests/fixtures/`, e não dentro de um `*_test.php`, pelo motivo do
 * `form_step_boot.php`: é o boot de todos os testes da exportação que tocam o
 * banco, e `run.php` só varre `tests/unit/*_test.php` — quem precisa faz
 * `require_once`, e a ordem do glob deixa de importar. Uma cópia do boot em
 * cada arquivo é o que este diretório existe para evitar.
 */

use Velk\App;
use Velk\Config;
use Velk\Database;

if (!function_exists('fexb_boot')) {
    /**
     * Um App com o formulário `contato` e as submissões dadas.
     *
     * Cada submissão é `[id, blocked_by, pending_step]`, e opcionalmente
     * `data` (JSON; padrão `{}`), `params` (JSON ou null) e `created_at`
     * (padrão `2026-01-01 00:00:00`), nessa ordem.
     *
     * @param array<string,mixed> $form `forms.contato` do site.json
     * @param list<list<mixed>>   $subs
     */
    function fexb_boot(array $form = [], array $subs = []): App
    {
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, ['paths' => ['core' => dirname(__DIR__, 2) . '/core']]);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, ['forms' => ['contato' => $form]]);

        $app = new App($config);
        $app->basePath = '';

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec(
            'CREATE TABLE form_submissions (
                id INTEGER PRIMARY KEY, form_id TEXT NOT NULL, blocked_by TEXT,
                pending_step INTEGER NULL, step_token TEXT NULL,
                data TEXT, params TEXT, ip TEXT, user_agent TEXT, referer TEXT,
                email_status TEXT, email_error TEXT, sent_to TEXT, created_at TEXT
            )'
        );
        $ins = $pdo->prepare(
            "INSERT INTO form_submissions (id, form_id, blocked_by, pending_step, data, params, created_at)
             VALUES (?, 'contato', ?, ?, ?, ?, ?)"
        );
        foreach ($subs as $s) {
            $ins->execute([$s[0], $s[1], $s[2], $s[3] ?? '{}', $s[4] ?? null, $s[5] ?? '2026-01-01 00:00:00']);
        }

        $db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Database::class, 'pdo'))->setValue($db, $pdo);
        $app->db = $db;

        return $app;
    }
}

if (!function_exists('fexb_admin')) {
    /**
     * O que só o painel precisa por cima de `fexb_boot()`: as tabelas que o
     * `FormController::show()` lê além das submissões, um administrador,
     * sessão, Auth e o catálogo do admin — o molde do
     * `form_panel_completeness_test.php`.
     *
     * Com `$logged` falso a sessão fica vazia: é o visitante sem login.
     */
    function fexb_admin(App $app, bool $logged = true): void
    {
        $pdo = $app->db->pdo();
        $pdo->exec('CREATE TABLE form_recipients (id INTEGER PRIMARY KEY, form_id TEXT, email TEXT, name TEXT NULL, created_at TEXT NULL)');
        $pdo->exec('CREATE TABLE form_settings (form_id TEXT PRIMARY KEY, user_data_fields TEXT NULL, conversion_snippet TEXT NULL, updated_at TEXT NULL)');
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

        $_SESSION = [];
        $app->session = new \Velk\Session();
        $app->auth = new \Velk\Auth($app->db, $app->session);
        if ($logged) {
            $app->session->put('user_id', 1);
        }
        $app->adminI18n = new \Velk\Admin\I18n(dirname(__DIR__, 2) . '/core/Admin/lang', 'en', 'en');
    }
}
