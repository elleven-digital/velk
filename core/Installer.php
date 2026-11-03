<?php

declare(strict_types=1);

namespace Velk;

use Velk\Models\Page;
use Velk\Models\User;
use PDO;
use PDOException;

final class Installer
{
    /**
     * Connect to MySQL without selecting a database and run
     * `CREATE DATABASE IF NOT EXISTS`. Idempotent.
     */
    public static function ensureDatabase(array $dbConfig): void
    {
        $name = (string) ($dbConfig['database'] ?? '');
        if ($name === '' || preg_match('/[^a-zA-Z0-9_]/', $name)) {
            throw new \RuntimeException("Invalid database name: {$name}");
        }

        $dsn = sprintf(
            '%s:host=%s;port=%d;charset=%s',
            $dbConfig['driver'] ?? 'mysql',
            $dbConfig['host'] ?? 'localhost',
            (int) ($dbConfig['port'] ?? 3306),
            $dbConfig['charset'] ?? 'utf8mb4',
        );

        $pdo = new PDO(
            $dsn,
            (string) ($dbConfig['username'] ?? ''),
            (string) ($dbConfig['password'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $charset = (string) ($dbConfig['charset'] ?? 'utf8mb4');
        $collation = (string) ($dbConfig['collation'] ?? 'utf8mb4_unicode_ci');
        $pdo->exec(sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET %s COLLATE %s',
            $name,
            $charset,
            $collation
        ));
    }

    /**
     * "Installed" = migrations table exists AND has at least one user.
     */
    public static function isInstalled(Database $db): bool
    {
        return self::isMigrated($db) && self::hasUsers($db);
    }

    public static function isMigrated(Database $db): bool
    {
        return $db->tableExists('migrations')
            && (int) $db->fetchColumn('SELECT COUNT(*) FROM migrations') > 0;
    }

    public static function hasUsers(Database $db): bool
    {
        return $db->tableExists('users')
            && (int) $db->fetchColumn('SELECT COUNT(*) FROM users') > 0;
    }

    /**
     * Detect what's missing.
     *
     * @return array{theme:bool, database:bool, tables:bool, users:bool}
     */
    public static function diagnose(array $dbConfig, ?string $rootPath = null): array
    {
        $themePath = $rootPath !== null ? $rootPath . '/theme' : (App::instance()->config->path('theme'));
        $state = [
            'theme'    => is_file($themePath . '/site.json'),
            'database' => false,
            'tables'   => false,
            'users'    => false,
        ];

        $dsnFull = sprintf(
            '%s:host=%s;port=%d;dbname=%s;charset=%s',
            $dbConfig['driver'] ?? 'mysql',
            $dbConfig['host'] ?? 'localhost',
            (int) ($dbConfig['port'] ?? 3306),
            $dbConfig['database'],
            $dbConfig['charset'] ?? 'utf8mb4',
        );

        try {
            $pdo = new PDO(
                $dsnFull,
                (string) ($dbConfig['username'] ?? ''),
                (string) ($dbConfig['password'] ?? ''),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            $state['database'] = true;

            $tables = $pdo->query(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users'"
            )->fetchColumn();
            $state['tables'] = ((int) $tables) > 0;

            if ($state['tables']) {
                $users = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
                $state['users'] = ((int) $users) > 0;
            }
        } catch (PDOException $e) {
            // Database missing, connection refused, bad credentials, etc.
        }

        return $state;
    }

    /**
     * Run a complete installation: migrations + page sync + initial user.
     * Idempotent — safe to run multiple times.
     *
     * @param array{email?:string, password?:string, name?:string} $userOverride
     *   Optional: override INITIAL_USER_* env vars.
     *
     * @return array{
     *   migrations: list<string>,
     *   pages_synced: int,
     *   user_created: ?string,
     *   user_skipped_reason: ?string
     * }
     */
    public static function install(App $app, array $userOverride = []): array
    {
        $report = [
            'migrations'         => [],
            'pages_synced'       => 0,
            'user_created'       => null,
            'user_skipped_reason' => null,
        ];

        // 0. Ensure runtime directories exist and are writable.
        //    The theme is NOT scaffolded — it's a separate package that the
        //    user (or skill) installs into `theme/` independently.
        self::ensureStorage($app);

        // 0b. Per-project files — copy `.example` templates into place if
        //     the live versions don't exist yet. Existing files are preserved
        //     (this method never overwrites), so admins can customize freely.
        $report['project_files_copied'] = self::ensureProjectFiles($app);

        // 1. Migrations
        $migrator = new Migrator($app->db, $app->config->path('migrations'));
        $report['migrations'] = $migrator->migrate();

        // 2. Sync pages from site.json
        $pageCount = 0;
        foreach ($app->config->pages() as $key => $page) {
            $title = (string) ($page['label'] ?? $key);
            if (Page::findByKey((string) $key) === null) {
                Page::upsert((string) $key, $title, []);
                $pageCount++;
            }
        }
        $report['pages_synced'] = $pageCount;

        // 3. Initial user (only if no users exist)
        if (User::count() > 0) {
            $report['user_skipped_reason'] = 'users_already_exist';
            return $report;
        }

        $email    = (string) ($userOverride['email']    ?? Env::get('INITIAL_USER_EMAIL', ''));
        $password = (string) ($userOverride['password'] ?? Env::get('INITIAL_USER_PASSWORD', ''));
        $name     = (string) ($userOverride['name']     ?? Env::get('INITIAL_USER_NAME', 'Admin'));

        if ($email === '' || $password === '') {
            $report['user_skipped_reason'] = 'no_initial_user_credentials';
            return $report;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('INITIAL_USER_EMAIL is not a valid email address.');
        }
        if (strlen($password) < 8) {
            throw new \RuntimeException('INITIAL_USER_PASSWORD must be at least 8 characters.');
        }

        $user = User::create($email, $password, $name);
        $report['user_created'] = $user->email;

        return $report;
    }

    /**
     * Per-project files map: `<example>` → `<live>`. The `.example` files
     * are versioned with Velk core; the live versions are gitignored so each
     * project can customize them (custom redirects in `.htaccess`, additional
     * `Disallow` rules in `robots.txt`, etc.) without future Velk updates
     * overwriting their changes.
     *
     * Pattern follows the same logic as `theme/` and `.env`: ship a starting
     * point, copy on install, leave alone afterwards.
     */
    public const PROJECT_FILE_TEMPLATES = [
        '.htaccess.example'   => '.htaccess',
        'robots.txt.example'  => 'robots.txt',
    ];

    /**
     * Copy any `.example` template into its live counterpart when the live
     * file doesn't exist yet. Returns the list of files actually created,
     * for the install report.
     *
     * Idempotent and non-destructive: if `.htaccess` already exists with
     * project-specific rules, this method leaves it untouched. To force a
     * refresh from the latest example, the user must delete the live file
     * first (or copy by hand).
     *
     * @return list<string> Live paths that were created.
     */
    public static function ensureProjectFiles(App $app): array
    {
        $rootPath = $app->config->rootPath();
        $created = [];

        foreach (self::PROJECT_FILE_TEMPLATES as $example => $live) {
            $src = $rootPath . '/' . $example;
            $dst = $rootPath . '/' . $live;

            // Skip when live exists — admin's customizations win.
            if (file_exists($dst)) continue;
            if (!is_file($src)) continue;

            // Make sure parent dir exists (always should, since these live
            // at the project root, but defensive code is cheap).
            $dir = dirname($dst);
            if (!is_dir($dir)) @mkdir($dir, 0775, true);

            if (@copy($src, $dst)) {
                @chmod($dst, 0644);
                $created[] = $live;
            }
        }

        return $created;
    }

    /**
     * Make sure `storage/uploads`, `storage/cache`, and `storage/logs` exist
     * and are writable. The web server (e.g. `_www` on macOS, `www-data` on
     * Debian) needs write access; here we create with 0775 and chmod existing
     * dirs so a fresh checkout boots cleanly under any common SAPI.
     */
    public static function ensureStorage(App $app): void
    {
        $dirs = [
            $app->config->path('uploads'),
            $app->config->path('cache'),
            $app->config->path('storage') . '/logs',
        ];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            @chmod($dir, 0775);
        }
    }

    public static function isUnknownDatabaseError(\Throwable $e): bool
    {
        $code = $e instanceof PDOException ? ($e->errorInfo[1] ?? null) : null;
        if ($code === 1049) return true;
        return str_contains($e->getMessage(), 'Unknown database');
    }
}
