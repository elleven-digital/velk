<?php

declare(strict_types=1);

namespace Velk\Front;

use Velk\App;
use Velk\Request;
use Velk\Response;
use Velk\Services\SubscriptionService;

/**
 * Endpoint público do webhook do Stripe. Diferente dos endpoints de
 * membro: SEM auth e SEM CSRF — o Stripe chama servidor-a-servidor. A
 * segurança vem da verificação de assinatura (SubscriptionService).
 *
 * Lê o corpo CRU de php://input — a assinatura é sobre os bytes exatos,
 * então não dá para usar $_POST (que é parseado/reordenado).
 */
final class StripeWebhookController
{
    public function handle(Request $request): Response
    {
        $app = App::instance();
        if (!$app->config->isSubscriptionsEnabled()) {
            return Response::json(['error' => 'disabled'], 404);
        }

        $payload = file_get_contents('php://input');
        if ($payload === false) {
            $payload = '';
        }
        $sig = (string) ($request->headers['stripe-signature'] ?? '');

        try {
            $ok = SubscriptionService::make()->handleWebhook($payload, $sig);
        } catch (\Throwable $e) {
            // 500 → o Stripe reentrega. Idempotência (upsert) torna isso seguro.
            error_log('[Velk] stripe webhook error: ' . $e->getMessage());
            return Response::json(['error' => 'processing'], 500);
        }

        if (!$ok) {
            return Response::json(['error' => 'invalid signature'], 400);
        }
        return Response::json(['received' => true], 200);
    }
}
