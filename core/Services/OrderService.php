<?php

declare(strict_types=1);

namespace Velk\Services;

use Velk\App;
use Velk\Models\Item;
use Velk\Models\Member;
use Velk\Models\Order;
use Velk\Models\OrderItem;

/**
 * Regra de negócio da venda. O CMS é a fonte do preço (campo do item,
 * lido no servidor — nunca confiar no cliente); o webhook é a fonte da
 * verdade do estado do pedido.
 *
 * Lógica pura (total, montagem de line_items) é estática e testável; os
 * métodos de instância tocam Item/Order/Stripe.
 */
final class OrderService
{
    public function __construct(private StripeClient $stripe) {}

    public static function make(): self
    {
        return new self(StripeClient::storeFromEnv());
    }

    public function isStoreEnabled(): bool
    {
        return $this->stripe->isConfigured()
            && App::instance()->config->sellableTypes() !== [];
    }

    // ─── Puro (testável) ──────────────────────────────────────────

    /** Soma de unit_amount * quantity, em centavos. PURO. */
    public static function cartTotal(array $lines): int
    {
        $total = 0;
        foreach ($lines as $l) {
            $total += (int) $l['unit_amount'] * (int) $l['quantity'];
        }
        return $total;
    }

    /** Teto de quantidade por linha — barra carrinho hostil (overflow do total). */
    public const MAX_QUANTITY = 100000;

    /** Normaliza a quantidade ao intervalo [1, MAX_QUANTITY]. PURO. */
    public static function clampQuantity(int $qty): int
    {
        return max(1, min(self::MAX_QUANTITY, $qty));
    }

    /**
     * PURO. Aplica repeat_purchase (site.json → item_types.<t>) às linhas já
     * resolvidas por resolveCart():
     *   - linha de tipo em $singlePurchaseTypes: quantity = 1, e linhas
     *     repetidas do mesmo item_id viram uma — é um item de uma unidade;
     *   - linha de tipo em $singlePurchaseTypes cujo item_id ∈ $ownedItemIds:
     *     removida — o membro já tem, não se cobra de novo;
     *   - demais linhas: intactas, na mesma ordem.
     * $ownedItemIds vazio (convidado) não remove nada; o clamp vale mesmo assim.
     */
    public static function enforceRepeatPurchase(array $lines, array $singlePurchaseTypes, array $ownedItemIds): array
    {
        if ($singlePurchaseTypes === []) {
            return $lines;
        }
        $owned = array_map('intval', $ownedItemIds);
        $seen = [];
        $out = [];
        foreach ($lines as $line) {
            if (!in_array((string) $line['item_type'], $singlePurchaseTypes, true)) {
                $out[] = $line;
                continue;
            }
            $id = (int) $line['item_id'];
            if (in_array($id, $owned, true) || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $line['quantity'] = 1;
            $out[] = $line;
        }
        return $out;
    }

    /** Converte linhas resolvidas no formato price_data do Stripe. PURO. */
    public static function buildStripeLineItems(array $lines, string $currency): array
    {
        $out = [];
        foreach ($lines as $l) {
            $out[] = [
                'price_data' => [
                    'currency' => strtolower($currency),
                    'unit_amount' => (int) $l['unit_amount'],
                    'product_data' => ['name' => (string) $l['title']],
                ],
                'quantity' => (int) $l['quantity'],
            ];
        }
        return $out;
    }

    // ─── Carrinho → pedido ────────────────────────────────────────

    /**
     * Resolve o carrinho cru [{id, qty}, ...] em linhas válidas, lendo o
     * preço/título DO SERVIDOR. Descarta item inexistente, não publicado,
     * de tipo não-vendável, ou sem preço (> 0).
     */
    public function resolveCart(array $rawItems): array
    {
        $config = App::instance()->config;
        $lines = [];
        foreach ($rawItems as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $id = (int) ($raw['id'] ?? 0);
            $qty = self::clampQuantity((int) ($raw['qty'] ?? 1));
            if ($id <= 0) {
                continue;
            }
            $item = Item::find($id);
            if ($item === null || $item->status !== 'published') {
                continue;
            }
            if (!$config->isItemTypeSellable($item->type)) {
                continue;
            }
            $cents = Money::toCents($item->field('price'));
            if ($cents === null || $cents <= 0) {
                continue;
            }
            $lines[] = [
                'item_id' => $item->id,
                'item_type' => $item->type,
                'title' => $item->title,
                'unit_amount' => $cents,
                'quantity' => $qty,
            ];
        }
        return $lines;
    }

    /**
     * Cria a sessão Stripe PRIMEIRO (para ter o session_id), depois grava
     * a order(pending) + order_items. Devolve a URL do checkout.
     */
    public function createCheckout(array $lines, ?Member $member, string $successUrl, string $cancelUrl): string
    {
        if ($lines === []) {
            throw new \RuntimeException('Carrinho vazio ou inválido.');
        }
        $config = App::instance()->config;
        $currency = $config->storeCurrency();
        $stripeLines = self::buildStripeLineItems($lines, $currency);
        $email = $member?->email;

        $session = $this->stripe->createPaymentCheckout(
            $stripeLines,
            $successUrl,
            $cancelUrl,
            $email,
            $member !== null ? ['member_id' => (string) $member->id] : []
        );
        if (empty($session['id']) || empty($session['url'])) {
            throw new \RuntimeException('Stripe não retornou a sessão de checkout.');
        }

        // order + linhas numa transação: nunca uma sem a outra.
        App::instance()->db->transaction(function () use ($member, $email, $currency, $lines, $session) {
            $id = Order::createPending([
                'member_id' => $member?->id,
                'email' => $email ?? '',
                'status' => 'pending',
                'currency' => $currency,
                'total_amount' => self::cartTotal($lines),
                'stripe_session_id' => (string) $session['id'],
            ]);
            OrderItem::createMany($id, $lines);
        });

        return (string) $session['url'];
    }

    // ─── Webhook ──────────────────────────────────────────────────

    /**
     * Decide o novo status do pedido a partir de um evento de checkout do
     * Stripe. PURO (sem I/O) — testável sem banco. Retorna null quando o
     * evento deve ser ignorado.
     *
     * C2: `completed` só vira 'paid' se `payment_status === 'paid'` — meios
     * assíncronos (boleto/pix) chegam 'unpaid' e só resolvem depois via
     * `async_payment_succeeded`/`async_payment_failed`. O filtro
     * `mode === 'payment'` vale para todos os tipos, para não cruzar com
     * checkout sessions de assinatura (endpoint de webhook separado).
     */
    public static function resolveWebhookStatus(string $type, array $object): ?string
    {
        if (($object['mode'] ?? '') !== 'payment') {
            return null;
        }
        return match ($type) {
            'checkout.session.completed' => (($object['payment_status'] ?? '') === 'paid') ? 'paid' : null,
            'checkout.session.async_payment_succeeded' => 'paid',
            'checkout.session.async_payment_failed' => 'failed',
            'checkout.session.expired' => 'expired',
            default => null,
        };
    }

    public function handleWebhook(string $payload, string $sigHeader): bool
    {
        if (!$this->stripe->verifyWebhookSignature($payload, $sigHeader)) {
            return false;
        }
        $event = json_decode($payload, true);
        if (!is_array($event)) {
            return false;
        }
        $type = (string) ($event['type'] ?? '');
        $object = (array) ($event['data']['object'] ?? []);
        $eventTs = (int) ($event['created'] ?? time());

        $status = self::resolveWebhookStatus($type, $object);
        if ($status !== null) {
            $sessionId = (string) ($object['id'] ?? '');
            $paymentIntent = is_string($object['payment_intent'] ?? null) ? $object['payment_intent'] : null;

            $applied = Order::markStatusFromWebhook(
                $sessionId,
                $status,
                $eventTs,
                $paymentIntent,
                isset($object['customer_details']['email']) ? (string) $object['customer_details']['email'] : null
            );

            // Cardinalidade da loja (spec 2026-09-07, decisão 3): quando o
            // pedido acabou de virar PAGO, confere se algum item de compra
            // única já estava em outro pedido pago do membro — a guarda do
            // checkout não fecha a janela das duas abas. Só registra; a
            // lista de pedidos do admin mostra os dois. Isolado: nada aqui
            // pode afetar o `return true` que o Stripe precisa receber.
            if ($applied === 'paid') {
                try {
                    $order = Order::findByStripeSession($sessionId);
                    if ($order !== null) {
                        $this->logRepeatPurchaseViolations($order);
                    }
                } catch (\Throwable $e) {
                    error_log('[Velk] cardinalidade da loja: ' . $e->getMessage());
                }
            }

            // Dispara o payment_webhook do tema SÓ quando a transição foi de
            // fato aplicada (nunca em reentrega/evento fora de ordem que a
            // guarda de idempotência descartou) — e sempre DEPOIS da escrita
            // no banco, isoladamente: nada aqui pode afetar o `return true`
            // que o Stripe precisa receber.
            if ($applied !== null) {
                try {
                    $this->dispatchOrderWebhook($sessionId, $applied, $type, $eventTs, $paymentIntent);
                } catch (\Throwable $e) {
                    error_log('[Velk payment_webhook] ' . $e->getMessage());
                }
            }
        }
        return true;
    }

    /**
     * Registra quando um pedido PAGO contém item de tipo de compra única
     * (site.json → item_types.<t>.repeat_purchase: false) que o membro já
     * tinha em OUTRO pedido pago. Não estorna. Público pelo mesmo motivo
     * que SubscriptionService::syncFromStripeSubscription(): o teste chama
     * direto, sem assinar payload.
     */
    public function logRepeatPurchaseViolations(Order $order): void
    {
        if ($order->memberId === null) {
            return;
        }
        $singleTypes = App::instance()->config->singlePurchaseTypes();
        if ($singleTypes === []) {
            return;
        }
        foreach ($order->items() as $line) {
            if ($line->itemId === null || !in_array($line->itemType, $singleTypes, true)) {
                continue;
            }
            $count = Order::countPaidForMemberContainingItem($order->memberId, $line->itemId);
            if ($count > 1) {
                error_log(sprintf(
                    '[Velk] ATENÇÃO: membro %d comprou de novo o item %d (%s), tipo de compra única — pedidos pagos: %d — verifique no Stripe',
                    $order->memberId,
                    $line->itemId,
                    $line->title,
                    $count
                ));
            }
        }
    }

    /**
     * Monta o payload/context do `order.<status>` (contrato exato do design
     * doc) e dispara via WebhookDispatcher — recarrega o pedido + itens do
     * banco (não reaproveita o $object cru do Stripe) pra refletir o
     * SNAPSHOT que ficou gravado.
     */
    private function dispatchOrderWebhook(
        string $sessionId,
        string $applied,
        string $stripeEvent,
        int $eventTs,
        ?string $paymentIntent
    ): void {
        if (App::instance()->config->paymentWebhook() === []) {
            // Nenhum handler configurado em site.json — o dispatcher faria
            // no-op de qualquer forma, mas aqui evitamos o round-trip ao
            // banco (pedido + itens) só pra descartar o resultado embaixo.
            return;
        }

        $order = Order::findByStripeSession($sessionId);
        if ($order === null) {
            // Não deveria acontecer — a linha acabou de ser escrita por
            // markStatusFromWebhook logo acima. Defensivo: loga e sai.
            error_log('[Velk payment_webhook] pedido não encontrado pra dispatch, session=' . $sessionId);
            return;
        }

        $data = [
            'order' => [
                'id' => $order->id,
                'member_id' => $order->memberId,
                'email' => $order->email,
                'status' => $order->status,
                'currency' => $order->currency,
                'total_amount' => $order->totalAmount,
                'stripe_session_id' => $order->stripeSessionId,
                'stripe_payment_intent' => $order->stripePaymentIntent,
                'created_at' => $order->createdAt,
            ],
            'items' => array_map(static fn(OrderItem $item) => [
                'item_id' => $item->itemId,
                'item_type' => $item->itemType,
                'title' => $item->title,
                'unit_amount' => $item->unitAmount,
                'quantity' => $item->quantity,
            ], OrderItem::forOrder($order->id)),
        ];
        $context = [
            'stripe_event' => $stripeEvent,
            'event_ts' => $eventTs,
            'session_id' => $sessionId,
            'payment_intent' => $paymentIntent,
        ];

        WebhookDispatcher::dispatch(
            App::instance()->config->paymentWebhook(),
            'Velk payment_webhook',
            ['order.' . $applied, $data, $context]
        );
    }
}
