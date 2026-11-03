<?php

declare(strict_types=1);

use Velk\Env;
use Velk\Session;

/**
 * Application configuration.
 *
 * This file does NOT hold secrets. Real values come from the environment:
 *   1. Server-set variables (Apache SetEnv, hosting panel, Vercel, etc.)
 *   2. The `.env` file at the project root (gitignored)
 *   3. Defaults below
 *
 * To configure locally, copy `.env.example` to `.env` and edit it.
 */

// Quanto tempo um login dura sem uso: o admin, 2 dias sem usar o painel; o
// membro do site, 30 dias sem usar o site — contados do último uso (Auth,
// MemberAuth). Os dois dividem a sessão, que dura o maior deles. Valor vazio
// ou que não é um número de segundos (`2d`) vale o padrão.
$adminLifetime = Session::lifetimeFrom(Env::get('ADMIN_SESSION_LIFETIME'), 60 * 60 * 24 * 2);
$memberLifetime = Session::lifetimeFrom(Env::get('MEMBER_SESSION_LIFETIME'), 60 * 60 * 24 * 30);

return [
    'app' => [
        'name'      => Env::get('APP_NAME', 'Velk'),
        'url'       => Env::get('APP_URL', ''),
        'debug'     => Env::get('APP_DEBUG', false),
        'timezone'  => Env::get('APP_TIMEZONE', 'UTC'),
        // URL prefix for subdirectory installs (e.g. "/velk"). Null = autodetect.
        'base_path' => Env::get('APP_BASE_PATH', null),
    ],

    'database' => [
        'driver'    => Env::get('DB_DRIVER', 'mysql'),
        'host'      => Env::get('DB_HOST', 'localhost'),
        'port'      => (int) Env::get('DB_PORT', 3306),
        'database'  => Env::get('DB_DATABASE', 'velk'),
        'username'  => Env::get('DB_USERNAME', ''),
        'password'  => Env::get('DB_PASSWORD', ''),
        'charset'   => Env::get('DB_CHARSET', 'utf8mb4'),
        'collation' => Env::get('DB_COLLATION', 'utf8mb4_unicode_ci'),
    ],

    'session' => [
        'name'            => Env::get('SESSION_NAME', 'velk_session'),
        'admin_lifetime'  => $adminLifetime,
        'member_lifetime' => $memberLifetime,
        // Cookie e dados da sessão: o maior dos dois, renovado a cada uso.
        'lifetime'        => max($adminLifetime, $memberLifetime),
        // Onde os dados da sessão ficam (handler de arquivos). Pasta do
        // próprio Velk, fora do alcance do cron de limpeza do host e dos
        // outros sites do servidor — ver Session::storageSettings(). Vazio
        // = a pasta padrão do php.ini.
        'path'            => (string) Env::get('SESSION_PATH', dirname(__DIR__) . '/storage/sessions'),
    ],

    'paths' => [
        'root'         => dirname(__DIR__),
        'core'         => dirname(__DIR__) . '/core',
        'theme'        => dirname(__DIR__) . '/theme',
        'config'       => dirname(__DIR__) . '/config',
        'storage'      => dirname(__DIR__) . '/storage',
        'uploads'      => dirname(__DIR__) . '/storage/uploads',
        // Form-file-upload fields (theme/site.json → forms.*.fields[].type
        // "file") are stored SEPARATE from the media library — never a
        // `Media` row, never listed in /admin/media. Private by construction:
        // storage/ is denied by .htaccess/nginx, only reachable via the
        // authenticated admin download route (FormController::downloadFile).
        'form_uploads' => dirname(__DIR__) . '/storage/form-uploads',
        'cache'        => dirname(__DIR__) . '/storage/cache',
        'migrations'   => dirname(__DIR__) . '/migrations',
    ],

    'admin' => [
        'prefix' => Env::get('ADMIN_PREFIX', '/admin'),
        'locale' => Env::get('ADMIN_LOCALE', 'en'),
    ],

    // Política de conteúdo (CSP) do site público. Ela mora no site.json
    // (security.csp) — ver Velk\PublicCsp; daqui vem só o coletor de relatos,
    // que muda por ambiente. O painel tem a política dele.
    'csp' => [
        'report_uri' => Env::get('CSP_REPORT_URI', ''),
    ],
];
