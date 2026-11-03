<?php

declare(strict_types=1);

use Velk\App;
use Velk\Config;
use Velk\Front\StoreController;
use Velk\Request;
use Velk\Session;

/**
 * Revisão de pagamentos 2026-09: o backUrl() da loja também só segue
 * Referer do mesmo host. Era a terceira cópia do helper no engine; as
 * outras duas já tinham passado pela regra (Request::refererSameHost).
 */

require_once dirname(__DIR__, 2) . '/core/helpers.php';

if (!function_exists('sbu_app')) {
    function sbu_app(): App
    {
        $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_x';
        $_ENV['STRIPE_STORE_WEBHOOK_SECRET'] = 'whsec_store';
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, ['paths' => []]);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, [
            'item_types' => ['ebook' => ['label' => 'E-books', 'sellable' => true, 'template' => 't.php', 'slug' => 'ebooks']],
        ]);
        $app = new App($config);
        $app->basePath = '';
        $_SESSION = [];
        $app->session = new Session();
        return $app;
    }
}

if (!function_exists('sbu_checkout')) {
    /** POST com CSRF inválido: cai no flash + redirect para $back antes de tocar o carrinho. */
    function sbu_checkout(?string $referer): ?string
    {
        sbu_app();
        $server = ['HTTP_HOST' => 'site.com'];
        if ($referer !== null) $server['HTTP_REFERER'] = $referer;
        $req = new Request('POST', '/store/checkout', [], ['_csrf' => 'invalido'], [], $server, []);
        $r = (new StoreController())->checkout($req);
        return $r->getHeader('Location');
    }
}

test('loja: Referer do mesmo host é seguido', function () {
    check_same('https://site.com/ebooks/x', sbu_checkout('https://site.com/ebooks/x'));
});

test('loja: Referer de OUTRO host cai na raiz do site', function () {
    check_same('/', sbu_checkout('https://evil.com/phish'));
});

test('loja: sem Referer cai na raiz', function () {
    check_same('/', sbu_checkout(null));
});

$_ENV['STRIPE_SECRET_KEY'] = ''; $_ENV['STRIPE_STORE_WEBHOOK_SECRET'] = '';
