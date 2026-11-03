<?php

declare(strict_types=1);

namespace Velk\Front;

use Velk\Config;
use Velk\Router;

/**
 * Registers the public-facing member endpoints. Skipped entirely when
 * `site.json → members.enabled` is false — no routes mounted, so the
 * theme can use those paths for other things if members aren't a thing
 * for this site.
 *
 * Routes are configurable via `members.routes` in site.json (with the
 * defaults in Config::memberRoutes). Wired before FrontRouter so member
 * paths can't be shadowed by a regular page with the same URL.
 */
final class MemberRouter
{
    public static function register(Router $router, Config $config): void
    {
        if (!$config->isMembersEnabled()) {
            return;
        }

        $routes = $config->memberRoutes();

        // Login/logout are always available when members are enabled.
        $router->post($routes['login'], [MemberController::class, 'login']);
        $router->post($routes['logout'], [MemberController::class, 'logout']);

        // 2FA endpoints — harmless to mount always: they no-op (error
        // status) unless the session holds a pending password-verified
        // login for a member with two_factor_enabled.
        $router->post($routes['two_factor'], [MemberController::class, 'twoFactor']);
        $router->post($routes['two_factor_resend'], [MemberController::class, 'twoFactorResend']);

        // Registration only mounts when public registration is allowed.
        // When admin-only, this URL 404s on the public side; admins
        // create accounts via /admin/members/new.
        if ($config->isMemberPublicRegistration()) {
            $router->post($routes['register'], [MemberController::class, 'register']);
        }

        // Password reset is available even when registration is closed —
        // members created by the admin still need to set their password.
        $router->post($routes['password_reset'], [MemberController::class, 'passwordResetRequest']);
        $router->post($routes['password_new'], [MemberController::class, 'passwordResetComplete']);

        // Assinaturas — montadas sempre que membros estão ligados; os
        // controllers fazem 404 quando isSubscriptionsEnabled() é false
        // (sem chave do Stripe / sem planos). O webhook é PÚBLICO: sem
        // auth de membro e sem CSRF — a segurança é a assinatura do Stripe
        // (StripeWebhookController vive no mesmo namespace Velk\Front).
        $router->post($routes['subscribe'], [MemberController::class, 'subscribe']);
        $router->post($routes['subscription_portal'], [MemberController::class, 'subscriptionPortal']);
        $router->get($routes['subscription_success'], [MemberController::class, 'subscriptionSuccess']);
        $router->get($routes['subscription_cancel'], [MemberController::class, 'subscriptionCancel']);
        $router->post($routes['stripe_webhook'], [StripeWebhookController::class, 'handle']);
    }
}
