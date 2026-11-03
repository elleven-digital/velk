<?php

declare(strict_types=1);

use Velk\Config;
use Velk\I18n;
use Velk\Request;

/**
 * Fix 3 (Correctness M4): the ?lang= preview query param must NOT be
 * honored on admin requests. I18n::current() used to check it on ANY
 * route, so appending ?lang=en to an admin POST (e.g. the forgot-password
 * form) silently flipped the active language for the rest of that
 * request — and any url()-built link the handler then generated
 * (notably the emailed reset-password link, see PasswordResetController)
 * came out prefixed /en/, a prefix admin routes were never registered
 * under. Gated to front-end requests only via a new isAdminRequest()
 * check that mirrors admin_url()'s admin.prefix computation and
 * Request::capture()'s exact-or-followed-by-slash boundary check (so a
 * front-end path that merely starts with the prefix string, e.g.
 * "/administracao" against "/admin", doesn't false-positive).
 */

if (!function_exists('i18n_conv_config')) {
    function i18n_conv_config(array $env = [], array $site = []): Config
    {
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        $envProp = new ReflectionProperty(Config::class, 'env');
        $envProp->setValue($config, array_replace_recursive([
            'admin' => ['prefix' => '/admin'],
        ], $env));
        $siteProp = new ReflectionProperty(Config::class, 'site');
        $siteProp->setValue($config, $site);
        return $config;
    }
}

if (!function_exists('i18n_conv_request')) {
    function i18n_conv_request(string $path, array $query = []): Request
    {
        return new Request('POST', $path, $query, [], [], [], []);
    }
}

if (!function_exists('i18n_conv_instance')) {
    /**
     * Builds an I18n WITHOUT running its constructor — current() and
     * isAdminRequest() never touch $db (in fact nothing in this class
     * does; it's injected but unused), so no live Database is needed.
     * Same Reflection-fixture technique as config_with_debug() etc.
     * elsewhere in this suite.
     */
    function i18n_conv_instance(Config $config, Request $request): I18n
    {
        $i18n = (new ReflectionClass(I18n::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(I18n::class, 'config'))->setValue($i18n, $config);
        (new ReflectionProperty(I18n::class, 'request'))->setValue($i18n, $request);
        return $i18n;
    }
}

if (!function_exists('i18n_enabled_site')) {
    function i18n_enabled_site(): array
    {
        return ['i18n' => ['enabled' => true, 'default' => 'pt-BR']];
    }
}

// ─── current(): requisições admin ignoram ?lang= ───────────────────────
//
// Todos os casos abaixo batem no gate ANTES de chegar em isAvailable()/
// Language::all() (sem DB nesse fixture) — se o gate não estiver
// curto-circuitando corretamente pra rota admin, o teste fatal-aria em
// vez de só falhar, o que por si só já denunciaria o bug.

test('current(): ?lang=en numa rota /admin/* é ignorado (usa default)', function () {
    $config = i18n_conv_config(['admin' => ['prefix' => '/admin']], i18n_enabled_site());
    $request = i18n_conv_request('/admin/forgot-password', ['lang' => 'en']);
    $i18n = i18n_conv_instance($config, $request);
    check_same('pt-BR', $i18n->current());
});

test('current(): ?lang=en na raiz exata do admin (path == prefixo) também é ignorado', function () {
    $config = i18n_conv_config(['admin' => ['prefix' => '/admin']], i18n_enabled_site());
    $request = i18n_conv_request('/admin', ['lang' => 'en']);
    $i18n = i18n_conv_instance($config, $request);
    check_same('pt-BR', $i18n->current());
});

test('current(): prefixo de admin customizado (/painel) também gateia', function () {
    $config = i18n_conv_config(['admin' => ['prefix' => '/painel']], i18n_enabled_site());
    $request = i18n_conv_request('/painel/forgot-password', ['lang' => 'en']);
    $i18n = i18n_conv_instance($config, $request);
    check_same('pt-BR', $i18n->current());
});

test('current(): i18n desabilitado → default, mesmo em rota admin com ?lang= (ordem dos checks preservada)', function () {
    $config = i18n_conv_config(['admin' => ['prefix' => '/admin']], ['i18n' => ['enabled' => false]]);
    $request = i18n_conv_request('/admin/forgot-password', ['lang' => 'en']);
    $i18n = i18n_conv_instance($config, $request);
    check_same('pt-BR', $i18n->current());
});

// ─── isAdminRequest() isolado — a lógica pura do gate ──────────────────

if (!function_exists('i18n_is_admin_request')) {
    function i18n_is_admin_request(I18n $i18n): bool
    {
        $m = new ReflectionMethod(I18n::class, 'isAdminRequest');
        return $m->invoke($i18n);
    }
}

test('isAdminRequest(): "/admin" (raiz exata do prefixo) → true', function () {
    $i18n = i18n_conv_instance(i18n_conv_config(['admin' => ['prefix' => '/admin']]), i18n_conv_request('/admin'));
    check_same(true, i18n_is_admin_request($i18n));
});

test('isAdminRequest(): "/admin/forgot-password" → true', function () {
    $i18n = i18n_conv_instance(i18n_conv_config(['admin' => ['prefix' => '/admin']]), i18n_conv_request('/admin/forgot-password'));
    check_same(true, i18n_is_admin_request($i18n));
});

test('isAdminRequest(): "/administracao" (prefixo como substring, sem "/" depois) → false', function () {
    $i18n = i18n_conv_instance(i18n_conv_config(['admin' => ['prefix' => '/admin']]), i18n_conv_request('/administracao'));
    check_same(false, i18n_is_admin_request($i18n));
});

test('isAdminRequest(): "/" (home) → false', function () {
    $i18n = i18n_conv_instance(i18n_conv_config(['admin' => ['prefix' => '/admin']]), i18n_conv_request('/'));
    check_same(false, i18n_is_admin_request($i18n));
});

test('isAdminRequest(): "/blog/post" (rota de front-end qualquer) → false', function () {
    $i18n = i18n_conv_instance(i18n_conv_config(['admin' => ['prefix' => '/admin']]), i18n_conv_request('/blog/post'));
    check_same(false, i18n_is_admin_request($i18n));
});

test('isAdminRequest(): prefixo customizado /painel — "/painel/x" → true, "/admin/x" → false', function () {
    $config = i18n_conv_config(['admin' => ['prefix' => '/painel']]);
    $i18nPainel = i18n_conv_instance($config, i18n_conv_request('/painel/x'));
    check_same(true, i18n_is_admin_request($i18nPainel));
    $i18nAdmin = i18n_conv_instance($config, i18n_conv_request('/admin/x'));
    check_same(false, i18n_is_admin_request($i18nAdmin));
});
