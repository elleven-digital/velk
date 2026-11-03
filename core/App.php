<?php

declare(strict_types=1);

namespace Velk;

use Velk\Admin\AdminRouter;
use Velk\Front\MemberRouter;
use Velk\Front\StoreRouter;

final class App
{
    private static ?App $instance = null;

    public Config $config;
    public Database $db;
    public Session $session;
    public Auth $auth;
    public MemberAuth $memberAuth;
    public Router $router;
    public Request $request;
    public I18n $i18n;
    public \Velk\Admin\I18n $adminI18n;

    /** URL prefix when Velk is mounted under a subdirectory (e.g., "/velk"). Empty when at host root. */
    public string $basePath = '';

    public function __construct(Config $config)
    {
        $this->config = $config;
        self::$instance = $this;
    }

    public static function instance(): App
    {
        if (self::$instance === null) {
            throw new \RuntimeException('App not booted yet.');
        }
        return self::$instance;
    }

    /**
     * O app já bootou?
     *
     * Existe para código que roda ANTES ou FORA do boot e não pode lançar —
     * o caso concreto é `Response::send()`, que decide `Cache-Control`
     * consultando a sessão, e que também é usado pelos tratadores de erro do
     * index.php quando o boot falhou. Ali `instance()` lançaria dentro do
     * handler de exceção, transformando um 500 legível em erro fatal.
     */
    public static function hasInstance(): bool
    {
        return self::$instance !== null;
    }

    public function register(): void
    {
        $dbConfig = $this->config->get('database');

        // Step 0 — theme must be installed (it brings site.json with the schema).
        // CLI is allowed to boot without a theme so commands like `install` and
        // `migrate` still work for setup.
        if (!$this->config->themeInstalled() && PHP_SAPI !== 'cli') {
            throw new SetupException(
                'No theme installed at theme/. Install a theme to provide site.json.',
                Installer::diagnose($dbConfig, $this->config->rootPath())
            );
        }

        // Step 1 — connect to DB. If it doesn't exist, try to create it.
        try {
            $this->db = new Database($dbConfig);
        } catch (\PDOException $e) {
            if (!Installer::isUnknownDatabaseError($e)) {
                error_log('[Velk] DB connect failed: ' . $e->getMessage());
                throw new SetupException(
                    $this->dbErrorMessage('Cannot connect to database.', $e),
                    Installer::diagnose($dbConfig, $this->config->rootPath())
                );
            }
            try {
                Installer::ensureDatabase($dbConfig);
                $this->db = new Database($dbConfig);
            } catch (\Throwable $e2) {
                error_log('[Velk] DB create failed: ' . $e2->getMessage());
                throw new SetupException(
                    $this->dbErrorMessage('Database does not exist and could not be created.', $e2),
                    Installer::diagnose($dbConfig, $this->config->rootPath())
                );
            }
        }

        $this->session = new Session($this->config->get('session'));
        $this->auth = new Auth(
            $this->db,
            $this->session,
            (int) $this->config->get('session.admin_lifetime', Auth::DEFAULT_LIFETIME)
        );
        $this->memberAuth = new MemberAuth(
            $this->db,
            $this->session,
            (int) $this->config->get('session.member_lifetime', MemberAuth::DEFAULT_LIFETIME)
        );

        // Step 2 — auto-install if migrations or initial user are missing.
        // CLI is trusted: it never gets a SetupException — the operator is
        // expected to run `./bin/velk install` (or whichever command they need)
        // and handle errors directly.
        if (!Installer::isInstalled($this->db)) {
            $email = (string) Env::get('INITIAL_USER_EMAIL', '');
            $password = (string) Env::get('INITIAL_USER_PASSWORD', '');

            if ($email !== '' && $password !== '') {
                try {
                    Installer::install($this);
                } catch (\Throwable $e) {
                    if (PHP_SAPI === 'cli') {
                        throw $e;
                    }
                    error_log('[Velk] Auto-install failed: ' . $e->getMessage());
                    throw new SetupException(
                        $this->dbErrorMessage('Database setup failed.', $e),
                        Installer::diagnose($dbConfig, $this->config->rootPath())
                    );
                }
            } elseif (PHP_SAPI !== 'cli') {
                throw new SetupException(
                    'Setup required — schema or initial user missing.',
                    Installer::diagnose($dbConfig, $this->config->rootPath())
                );
            }
        }

        // Step 3 — aplica migrations PENDENTES no boot web (sites já instalados).
        // Permite upgrade em hosts sem shell: subir os arquivos novos e a próxima
        // requisição aplica as pendentes sob um lock. CLI nunca entra aqui (o
        // operador usa `bin/velk migrate`). Kill-switch: AUTO_MIGRATE=false.
        if (PHP_SAPI !== 'cli') {
            (new AutoMigrator(
                $this->db,
                new Migrator($this->db, $this->config->path('migrations')),
                $this->config->rootPath() . '/storage',
                Env::get('AUTO_MIGRATE', true) !== false
            ))->run();
        }

        $this->basePath = $this->resolveBasePath();
        $this->request = Request::capture($this->basePath);
        $this->router = new Router();

        // I18n needs the captured request to read ?lang= preview param.
        // Constructed after Request::capture so it can introspect it.
        $this->i18n = new I18n($this->config, $this->db, $this->request);

        // Admin-panel i18n (file-based catalogs, distinct from the site's
        // DB-backed I18n above). Locale here is just the configured default —
        // the base admin Controller sets the per-request active locale from
        // the authenticated user (Auth::user() is a lazy DB hit, so we don't
        // resolve it this early).
        $this->adminI18n = new \Velk\Admin\I18n(
            $this->config->path('core') . '/Admin/lang',
            (string) $this->config->get('admin.locale', 'en'),
            'en',
        );

        // Step 3 — load the theme's PHP entrypoint if present.
        //
        // theme/functions.php is the conventional place for site-specific
        // PHP — most commonly webhook handlers for forms (declared in
        // site.json → forms.<id>.webhook). We load it AFTER db/auth/i18n
        // are booted so handlers can call App::instance() helpers freely,
        // and BEFORE routes are registered so any setup the file wants
        // to do still has a chance to influence the request.
        //
        // CLI runs without a theme installed in some flows (install/migrate
        // bootstrap), so we still guard with file_exists — themeInstalled()
        // was already checked above for non-CLI paths.
        $themeFunctions = $this->config->path('theme') . '/functions.php';
        if (file_exists($themeFunctions)) {
            require_once $themeFunctions;
        }

        $this->router->get('/uploads/{path:.+}', [UploadController::class, 'serve']);
        $this->router->get('/theme/{path:.+}', [ThemeAssetController::class, 'serve']);
        $this->router->post('/forms/{id}', [PublicFormController::class, 'submit']);

        AdminRouter::register($this->router, $this->config);
        // Member routes register BEFORE FrontRouter so the configured
        // member URLs can't be shadowed by a page with the same path.
        MemberRouter::register($this->router, $this->config);
        StoreRouter::register($this->router, $this->config);
        FrontRouter::register($this->router, $this->config);
    }

    /**
     * Public-facing text for a boot-time setup failure (DB connect, DB
     * create, or auto-install/migration). The raw exception message — a PDO
     * error in particular — routinely contains the DB username/host, and a
     * failing migration can echo SQL/schema text; fine to show in local
     * debug, never to an anonymous visitor of the public setup/503 page.
     * Callers always error_log() the full detail before throwing, regardless
     * of this. `$generic` lets each call site describe its own failure
     * accurately (e.g. "Cannot connect to database." vs "Database setup
     * failed.") while sharing the same debug-gated disclosure rule.
     */
    private function dbErrorMessage(string $generic, \Throwable $e): string
    {
        if ((bool) $this->config->get('app.debug')) {
            return $generic . ' ' . $e->getMessage();
        }
        return $generic;
    }

    /**
     * Resolve the URL prefix when Velk is mounted under a subdirectory.
     * Precedence: explicit `app.base_path` config → autodetect from SCRIPT_NAME → "".
     */
    private function resolveBasePath(): string
    {
        $configured = $this->config->get('app.base_path');
        if (is_string($configured)) {
            return $configured === '' ? '' : '/' . trim($configured, '/');
        }

        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        // index.php lives at the project root (single-tier layout). The
        // legacy `/public/index.php` suffix is checked too in case someone
        // is running an older install that still has the public/ subdir.
        foreach (['/index.php', '/public/index.php'] as $suffix) {
            if (str_ends_with($script, $suffix)) {
                $base = substr($script, 0, -strlen($suffix));
                return $base === '' ? '' : '/' . trim($base, '/');
            }
        }

        return '';
    }

    public function run(): void
    {
        $response = AdminRouter::noIndex($this->request, $this->router->dispatch($this->request), $this->config);
        $response = PublicCsp::apply($this->request, $response, $this->config);
        $response->send();
    }
}
