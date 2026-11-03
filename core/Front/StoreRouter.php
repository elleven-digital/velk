<?php

declare(strict_types=1);

namespace Velk\Front;

use Velk\Config;
use Velk\Router;

/**
 * Rotas públicas da loja. Montadas só quando a loja está ligada
 * (isStoreEnabled). O webhook é PÚBLICO (sem auth/CSRF) — a segurança é
 * a assinatura do Stripe (StoreWebhookController, mesmo namespace).
 */
final class StoreRouter
{
    public static function register(Router $router, Config $config): void
    {
        if (!$config->isStoreEnabled()) {
            return;
        }
        $routes = $config->storeRoutes();
        $router->post($routes['checkout'], [StoreController::class, 'checkout']);
        $router->get($routes['success'], [StoreController::class, 'success']);
        $router->get($routes['cancel'], [StoreController::class, 'cancel']);
        $router->post($routes['webhook'], [StoreWebhookController::class, 'handle']);
    }
}
