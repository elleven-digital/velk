<?php

declare(strict_types=1);

namespace Velk\Front;

use Velk\App;
use Velk\Request;
use Velk\Response;
use Velk\Services\OrderService;

/**
 * Webhook público da loja. Sem auth/CSRF — segurança pela assinatura do
 * Stripe (secret próprio da loja). Lê o corpo cru de php://input.
 */
final class StoreWebhookController
{
    public function handle(Request $request): Response
    {
        $app = App::instance();
        if (!$app->config->isStoreEnabled()) {
            return Response::json(['error' => 'disabled'], 404);
        }

        $payload = file_get_contents('php://input');
        if ($payload === false) {
            $payload = '';
        }
        $sig = (string) ($request->headers['stripe-signature'] ?? '');

        try {
            $ok = OrderService::make()->handleWebhook($payload, $sig);
        } catch (\Throwable $e) {
            error_log('[Velk] store webhook error: ' . $e->getMessage());
            return Response::json(['error' => 'processing'], 500);
        }

        if (!$ok) {
            return Response::json(['error' => 'invalid signature'], 400);
        }
        return Response::json(['received' => true], 200);
    }
}
