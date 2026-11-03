<?php

declare(strict_types=1);

/**
 * Hardening T12 (M3) — member_subscribe_button(), member_portal_button()
 * and store_checkout_button() used to default their $label parameter to a
 * hardcoded pt-BR string ('Assinar', 'Gerenciar assinatura', 'Comprar').
 * That's a frozen-API footgun on a PUBLIC theme helper: an EN (or any
 * other locale) site that doesn't pass an explicit $label silently got
 * Portuguese button text with no way to discover it short of reading the
 * engine source.
 *
 * Fixed by defaulting $label to null and resolving it via t('<English
 * source>') when omitted — English-first AND translatable through the
 * site's own i18n catalog, while a theme passing an explicit label still
 * overrides it untouched (widening `string` to `?string` is backward
 * compatible: no existing caller could have passed null before).
 *
 * Boots the lightest possible App — real Config (no DB), a CLI Session
 * (no-op per Session::__construct), and an I18n instance built via
 * Reflection with `activeLanguage` pre-seeded to the real
 * defaultLanguage() — so current() short-circuits on its very first line
 * and t()'s hot path (current === default) never touches Database or
 * Request, matching the "no setAccessible() needed, PHP 8.1+" convention
 * already used by i18n_translation_merge_test.php.
 */

require_once dirname(__DIR__, 2) . '/core/helpers.php';

if (!function_exists('button_label_test_app')) {
    function button_label_test_app(): \Velk\App
    {
        $app = new \Velk\App(new \Velk\Config(dirname(__DIR__, 2)));
        $app->session = new \Velk\Session(); // no-op under CLI SAPI

        $i18nRef = new ReflectionClass(\Velk\I18n::class);
        $i18n = $i18nRef->newInstanceWithoutConstructor();
        $i18nRef->getProperty('config')->setValue($i18n, $app->config);
        // Pre-seed the cache current() checks FIRST, so it returns
        // immediately without ever touching the (unset) db/request
        // properties — both stay safely uninitialized for this test.
        $i18nRef->getProperty('activeLanguage')->setValue($i18n, $i18n->defaultLanguage());
        $app->i18n = $i18n;

        return $app;
    }
}

test('member_subscribe_button: sem $label, usa o default em inglês "Subscribe" via t()', function () {
    button_label_test_app();
    $html = member_subscribe_button('pro', 'month');
    check(str_contains($html, '>Subscribe</button>'), 'esperava o botão "Subscribe" (source EN, resolvido por t())');
});

test('member_subscribe_button: $label explícito sobrescreve o default', function () {
    button_label_test_app();
    $html = member_subscribe_button('pro', 'month', 'Quero assinar');
    check(str_contains($html, '>Quero assinar</button>'), 'label explícito deve aparecer literalmente');
    check(!str_contains($html, 'Subscribe'), 'default em inglês não deve vazar quando um label é passado');
});

test('member_portal_button: sem $label, usa o default em inglês "Manage subscription" via t()', function () {
    button_label_test_app();
    $html = member_portal_button();
    check(str_contains($html, '>Manage subscription</button>'));
});

test('member_portal_button: $label explícito sobrescreve o default', function () {
    button_label_test_app();
    $html = member_portal_button('Minha assinatura');
    check(str_contains($html, '>Minha assinatura</button>'));
    check(!str_contains($html, 'Manage subscription'));
});

test('store_checkout_button: sem $label, usa o default em inglês "Buy" via t()', function () {
    button_label_test_app();
    $html = store_checkout_button([['id' => 1, 'qty' => 1]]);
    check(str_contains($html, '>Buy</button>'));
});

test('store_checkout_button: $label explícito sobrescreve o default', function () {
    button_label_test_app();
    $html = store_checkout_button([['id' => 1, 'qty' => 1]], 'Finalizar compra');
    check(str_contains($html, '>Finalizar compra</button>'));
    check(!str_contains($html, '>Buy</button>'));
});
