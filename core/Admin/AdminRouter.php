<?php

declare(strict_types=1);

namespace Velk\Admin;

use Velk\Admin\Controllers\AssetController;
use Velk\Admin\Controllers\AuthController;
use Velk\Admin\Controllers\BlockController;
use Velk\Admin\Controllers\DashboardController;
use Velk\Admin\Controllers\FormController;
use Velk\Admin\Controllers\ItemController;
use Velk\Admin\Controllers\MediaController;
use Velk\Admin\Controllers\OptionController;
use Velk\Admin\Controllers\PageController;
use Velk\Admin\Controllers\PasswordResetController;
use Velk\Admin\Controllers\ConfigTranslationController;
use Velk\Admin\Controllers\LanguageController;
use Velk\Admin\Controllers\TrackingScriptController;
use Velk\Admin\Controllers\MemberController;
use Velk\Admin\Controllers\OrderController;
use Velk\Admin\Controllers\TermController;
use Velk\Admin\Controllers\ThemeStringController;
use Velk\Admin\Controllers\UserController;
use Velk\Config;
use Velk\Request;
use Velk\Response;
use Velk\Router;

final class AdminRouter
{
    /**
     * O painel não vai para buscador: toda resposta sob o prefixo do admin
     * sai com `X-Robots-Tag: noindex, nofollow` — login, telas, JSON,
     * downloads, a exportação e os estáticos do painel. É o cabeçalho, e não
     * o robots.txt, que tira uma página do índice: o `Disallow` só impede a
     * visita (o endereço ainda entra por um link), e o robots.txt é público,
     * então citar o admin nele entregaria o caminho de quem o trocou.
     */
    public static function noIndex(Request $request, Response $response, Config $config): Response
    {
        $prefix = '/' . trim((string) $config->get('admin.prefix', '/admin'), '/');
        if ($request->path === $prefix || str_starts_with($request->path, $prefix . '/')) {
            $response->header('X-Robots-Tag', 'noindex, nofollow');
        }
        return $response;
    }

    public static function register(Router $router, Config $config): void
    {
        $prefix = rtrim((string) $config->get('admin.prefix', '/admin'), '/');

        $router->get($prefix . '/__static/css/{file}', [AssetController::class, 'css']);
        $router->get($prefix . '/__static/js/{file}', [AssetController::class, 'js']);
        $router->get($prefix . '/__static/fonts/{file}', [AssetController::class, 'font']);

        $router->any($prefix . '/login', [AuthController::class, 'login']);
        // Second step of the 2FA challenge — only meaningful while the
        // session holds a pending (password-verified) login. The
        // controller redirects back to /login otherwise.
        $router->any($prefix . '/login/two-factor', [AuthController::class, 'twoFactor']);
        $router->post($prefix . '/login/two-factor/resend', [AuthController::class, 'twoFactorResend']);
        $router->post($prefix . '/logout', [AuthController::class, 'logout']);
        $router->any($prefix . '/forgot-password', [PasswordResetController::class, 'forgot']);
        $router->any($prefix . '/reset-password/{token}', [PasswordResetController::class, 'reset']);

        $router->get($prefix, [DashboardController::class, 'index']);
        $router->get($prefix . '/', [DashboardController::class, 'index']);

        $router->get($prefix . '/pages', [PageController::class, 'index']);
        // `/new` is declared BEFORE `/{key}` so it can't be shadowed by
        // a custom page with slug='new' (slug validator forbids `new`
        // implicitly via the route order — a slug 'new' would still
        // create OK in DB but the admin edit URL would route here).
        $router->any($prefix . '/pages/new', [PageController::class, 'create']);
        $router->any($prefix . '/pages/{key}', [PageController::class, 'edit']);
        $router->post($prefix . '/pages/{key}/delete', [PageController::class, 'delete']);

        $router->get($prefix . '/items/{type}', [ItemController::class, 'index']);
        $router->any($prefix . '/items/{type}/new', [ItemController::class, 'create']);
        // `/translations` and `/bulk-delete` are registered BEFORE `/{id}`
        // so the router's first-match-wins logic doesn't treat them as an
        // item id and hit ItemController::edit. Same pattern as `/new`
        // already does above.
        $router->any($prefix . '/items/{type}/translations', [ConfigTranslationController::class, 'itemType']);
        $router->post($prefix . '/items/{type}/bulk-delete', [ItemController::class, 'bulkDelete']);
        // `reorder` ANTES da rota com {id}, senão "reorder" é capturado como
        // id — mesma preocupação que já vale para `bulk-delete`.
        $router->post($prefix . '/items/{type}/reorder', [ItemController::class, 'reorder']);
        $router->any($prefix . '/items/{type}/{id}', [ItemController::class, 'edit']);
        $router->post($prefix . '/items/{type}/{id}/delete', [ItemController::class, 'delete']);

        $router->get($prefix . '/taxonomies/{taxonomy}', [TermController::class, 'index']);
        $router->any($prefix . '/taxonomies/{taxonomy}/new', [TermController::class, 'create']);
        $router->any($prefix . '/taxonomies/{taxonomy}/translations', [ConfigTranslationController::class, 'taxonomy']);
        // `/bulk-delete` before `/{id}` — same shadowing concern as items above.
        $router->post($prefix . '/taxonomies/{taxonomy}/bulk-delete', [TermController::class, 'bulkDelete']);
        // Mesma preocupação de sombreamento que items acima: `reorder` antes de `/{id}`.
        $router->post($prefix . '/taxonomies/{taxonomy}/reorder', [TermController::class, 'reorder']);
        $router->any($prefix . '/taxonomies/{taxonomy}/{id}', [TermController::class, 'edit']);
        $router->post($prefix . '/taxonomies/{taxonomy}/{id}/delete', [TermController::class, 'delete']);

        $router->get($prefix . '/media', [MediaController::class, 'index']);
        $router->post($prefix . '/media/upload', [MediaController::class, 'upload']);
        $router->get($prefix . '/media/{id}', [MediaController::class, 'show']);
        $router->post($prefix . '/media/{id}', [MediaController::class, 'update']);
        $router->post($prefix . '/media/{id}/delete', [MediaController::class, 'delete']);

        // A janela de um bloco do tema, aberta pelo editor de texto. Só POST:
        // os dados do bloco em edição não cabem numa URL.
        $router->post($prefix . '/blocks/form', [BlockController::class, 'form']);
        $router->post($prefix . '/blocks/save', [BlockController::class, 'save']);

        $router->get($prefix . '/users', [UserController::class, 'index']);
        $router->any($prefix . '/users/new', [UserController::class, 'create']);
        $router->any($prefix . '/users/{id}', [UserController::class, 'edit']);
        $router->post($prefix . '/users/{id}/delete', [UserController::class, 'delete']);

        // Site members — controller refuses to act when members.enabled
        // is false in site.json, so it's safe to always register the
        // routes. Avoids requiring the Config to be threaded down here.
        $router->get($prefix . '/members', [MemberController::class, 'index']);
        $router->any($prefix . '/members/new', [MemberController::class, 'create']);
        $router->any($prefix . '/members/{id}', [MemberController::class, 'edit']);
        $router->post($prefix . '/members/{id}/delete', [MemberController::class, 'delete']);

        // i18n — Phase 1: language registry + theme string catalog.
        // Controllers show a "disabled" view when site.json doesn't
        // declare i18n.enabled = true, so routes are safe to always
        // register.
        // Scripts de rastreamento. `reorder` e `conversions` vêm ANTES da rota
        // com {id}, senão "reorder" seria capturado como id.
        $router->get($prefix . '/scripts', [TrackingScriptController::class, 'index']);
        $router->post($prefix . '/scripts/reorder', [TrackingScriptController::class, 'reorder']);
        $router->post($prefix . '/scripts/conversions', [TrackingScriptController::class, 'saveConversions']);
        $router->any($prefix . '/scripts/new', [TrackingScriptController::class, 'create']);
        $router->any($prefix . '/scripts/{id}', [TrackingScriptController::class, 'edit']);
        $router->post($prefix . '/scripts/{id}/delete', [TrackingScriptController::class, 'delete']);
        $router->get($prefix . '/languages', [LanguageController::class, 'index']);
        $router->any($prefix . '/languages/new', [LanguageController::class, 'create']);
        $router->any($prefix . '/languages/{code}', [LanguageController::class, 'edit']);
        $router->post($prefix . '/languages/{code}/delete', [LanguageController::class, 'delete']);

        // (item/taxonomy translations routes are registered above,
        // ordered before the {id} catch-all to avoid shadowing)

        $router->get($prefix . '/translations', [ThemeStringController::class, 'index']);
        $router->any($prefix . '/translations/new', [ThemeStringController::class, 'create']);
        $router->post($prefix . '/translations/scan', [ThemeStringController::class, 'scan']);
        $router->post($prefix . '/translations/{id}/update', [ThemeStringController::class, 'update']);
        $router->post($prefix . '/translations/{id}/delete', [ThemeStringController::class, 'delete']);

        $router->get($prefix . '/forms', [FormController::class, 'index']);
        $router->get($prefix . '/forms/{id}', [FormController::class, 'show']);
        $router->get($prefix . '/forms/{id}/export', [FormController::class, 'export']);
        $router->post($prefix . '/forms/{id}/conversion', [FormController::class, 'saveConversion']);
        $router->post($prefix . '/forms/{id}/recipients', [FormController::class, 'addRecipient']);
        $router->post($prefix . '/forms/{id}/recipients/{rid}/delete', [FormController::class, 'removeRecipient']);
        $router->post($prefix . '/forms/{id}/submissions/{sid}/delete', [FormController::class, 'deleteSubmission']);
        $router->post($prefix . '/forms/{id}/submissions/{sid}/not-spam', [FormController::class, 'markNotSpam']);
        $router->post($prefix . '/forms/{id}/submissions/{sid}/spam', [FormController::class, 'markSpam']);
        $router->get($prefix . '/forms/{id}/submissions/{sid}/file/{field}', [FormController::class, 'downloadFile']);

        $router->get($prefix . '/orders', [OrderController::class, 'index']);
        $router->get($prefix . '/orders/{id}', [OrderController::class, 'show']);

        $router->any($prefix . '/options/{key}', [OptionController::class, 'edit']);
    }
}
