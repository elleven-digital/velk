<?php

declare(strict_types=1);

use Velk\App;
use Velk\Config;
use Velk\Sitemap;

require_once dirname(__DIR__, 2) . '/core/helpers.php';

/**
 * Fix 1 (Correctness I4): APP_URL is origin-ONLY; APP_BASE_PATH is always
 * appended by url()/absolute_url() — and must be appended the SAME way by
 * every other absolute-URL builder (app_origin()/absolute_path_url() here,
 * Sitemap::resolveBaseUrl() below), never assumed to be pre-included in
 * APP_URL by the caller. These tests pin the exact composition for a root
 * install and a "/velk" subdirectory install — WITH APP_URL configured and
 * WITH it left empty (request-derived fallback) — so a doubled or missing
 * base-path segment fails loudly instead of silently 404ing Stripe
 * success/cancel or a password-reset link in production.
 *
 * Fix 2 (Correctness I5): absolute_admin_url() layers the SAME convention
 * on top of admin_url() (already ADMIN_PREFIX-aware), so an emailed admin
 * reset link works under a custom ADMIN_PREFIX too.
 */

if (!function_exists('url_conv_app')) {
    /**
     * Lightest possible App for these tests: a Config with only the
     * env/site keys the URL helpers read (Reflection-injected, same
     * technique as config_with_debug() / config_with_site() elsewhere in
     * this suite — no config/env.php or theme/site.json touched), and
     * basePath set directly (register() normally derives it from
     * app.base_path; url()/base_path() only ever read the already-
     * resolved App::instance()->basePath).
     */
    function url_conv_app(array $env = [], array $site = [], string $basePath = ''): App
    {
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        $envProp = new ReflectionProperty(Config::class, 'env');
        $envProp->setValue($config, array_replace_recursive([
            'app' => ['url' => '', 'base_path' => null],
            'admin' => ['prefix' => '/admin'],
        ], $env));
        $siteProp = new ReflectionProperty(Config::class, 'site');
        $siteProp->setValue($config, $site);

        $app = new App($config);
        $app->basePath = $basePath;
        return $app;
    }
}

if (!function_exists('with_server')) {
    /** Temporarily overrides $_SERVER keys for the duration of $fn, then restores them. */
    function with_server(array $overrides, callable $fn): mixed
    {
        $saved = [];
        foreach ($overrides as $key => $value) {
            $saved[$key] = $_SERVER[$key] ?? null;
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
        try {
            return $fn();
        } finally {
            foreach ($saved as $key => $value) {
                if ($value === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $value;
                }
            }
        }
    }
}

// ─── app_origin() ──────────────────────────────────────────────────────

test('app_origin(): APP_URL configurado → origin sem trailing slash', function () {
    url_conv_app(['app' => ['url' => 'https://example.com/']]);
    check_same('https://example.com', app_origin());
});

test('app_origin(): APP_URL vazio → cai pro scheme+host da request (https)', function () {
    url_conv_app(['app' => ['url' => '']]);
    with_server(['HTTPS' => 'on', 'HTTP_HOST' => 'fallback.test'], function () {
        check_same('https://fallback.test', app_origin());
    });
});

test('app_origin(): APP_URL vazio + sem HTTPS → http', function () {
    url_conv_app(['app' => ['url' => '']]);
    with_server(['HTTPS' => null, 'HTTP_HOST' => 'plain.test'], function () {
        check_same('http://plain.test', app_origin());
    });
});

// ─── absolute_path_url() — rotas fixas (Stripe success/cancel, reset) ──

test('absolute_path_url(): instalação raiz, APP_URL setado', function () {
    url_conv_app(['app' => ['url' => 'https://example.com']], [], '');
    check_same('https://example.com/store/success', absolute_path_url('/store/success'));
});

test('absolute_path_url(): subdir /velk, APP_URL origin-only → exatamente um /velk', function () {
    url_conv_app(['app' => ['url' => 'https://example.com']], [], '/velk');
    check_same('https://example.com/velk/store/success', absolute_path_url('/store/success'));
    check_same('https://example.com/velk/member/password-new', absolute_path_url('/member/password-new'));
});

test('absolute_path_url(): subdir /velk, APP_URL vazio (fallback pro request) → ainda um /velk só', function () {
    url_conv_app(['app' => ['url' => '']], [], '/velk');
    with_server(['HTTPS' => 'on', 'HTTP_HOST' => 'example.com'], function () {
        check_same('https://example.com/velk/store/cancel', absolute_path_url('/store/cancel'));
    });
});

test('absolute_path_url(): i18n habilitado NÃO injeta prefixo de idioma numa rota fixa', function () {
    // isI18nEnabled()=true exercita o branch de i18n dentro de url(); a
    // linguagem default é passada explicitamente, então tem que sair sem
    // prefixo de qualquer forma — e como $app->i18n nem é montado nesse
    // fixture, uma regressão que fizesse absolute_path_url() cair pra
    // $app->i18n->current() faria ISSO FATALIZAR aqui (typed property
    // não inicializada), não só prefixar errado silenciosamente.
    url_conv_app(
        ['app' => ['url' => 'https://example.com']],
        ['i18n' => ['enabled' => true, 'default' => 'pt-BR']],
        ''
    );
    check_same('https://example.com/store/success', absolute_path_url('/store/success'));
});

// ─── absolute_admin_url() — Fix 2 (link de reset do admin) ─────────────

test('absolute_admin_url(): raiz, prefixo padrão /admin', function () {
    url_conv_app(['app' => ['url' => 'https://example.com'], 'admin' => ['prefix' => '/admin']], [], '');
    check_same('https://example.com/admin/reset-password/tok123', absolute_admin_url('reset-password/tok123'));
});

test('absolute_admin_url(): subdir /velk + ADMIN_PREFIX customizado /painel → um /velk, um /painel', function () {
    url_conv_app(
        ['app' => ['url' => 'https://example.com'], 'admin' => ['prefix' => '/painel']],
        [],
        '/velk'
    );
    check_same('https://example.com/velk/painel/reset-password/tok123', absolute_admin_url('reset-password/tok123'));
});

test('absolute_admin_url(): APP_URL vazio (fallback) + prefixo customizado', function () {
    url_conv_app(['app' => ['url' => ''], 'admin' => ['prefix' => '/painel']], [], '');
    with_server(['HTTPS' => null, 'HTTP_HOST' => 'dev.local'], function () {
        check_same('http://dev.local/painel/reset-password/tok123', absolute_admin_url('reset-password/tok123'));
    });
});

// ─── absolute_url() — regressão: convenção canônica permanece a mesma ──

test('absolute_url(): regressão — raiz continua igual', function () {
    url_conv_app(['app' => ['url' => 'https://example.com']], [], '');
    check_same('https://example.com/blog/post', absolute_url('/blog/post'));
});

test('absolute_url(): regressão — subdir /velk continua um /velk só (já era a referência canônica)', function () {
    url_conv_app(['app' => ['url' => 'https://example.com']], [], '/velk');
    check_same('https://example.com/velk/blog/post', absolute_url('/blog/post'));
});

// ─── Sitemap::resolveBaseUrl() — I4 no <loc> do sitemap.xml ────────────

if (!function_exists('sitemap_base_url')) {
    function sitemap_base_url(): string
    {
        $m = new ReflectionMethod(Sitemap::class, 'resolveBaseUrl');
        return $m->invoke(null);
    }
}

test('Sitemap::resolveBaseUrl(): raiz, APP_URL setado', function () {
    url_conv_app(['app' => ['url' => 'https://example.com']], [], '');
    check_same('https://example.com', sitemap_base_url());
});

test('Sitemap::resolveBaseUrl(): subdir /velk, APP_URL setado — ANTES perdia o /velk, agora não', function () {
    url_conv_app(['app' => ['url' => 'https://example.com']], [], '/velk');
    check_same('https://example.com/velk', sitemap_base_url());
});

test('Sitemap::resolveBaseUrl(): subdir /velk, APP_URL vazio (fallback) — já funcionava, continua', function () {
    url_conv_app(['app' => ['url' => '']], [], '/velk');
    with_server(['HTTPS' => 'on', 'HTTP_HOST' => 'example.com'], function () {
        check_same('https://example.com/velk', sitemap_base_url());
    });
});
