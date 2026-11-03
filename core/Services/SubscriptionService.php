<?php

declare(strict_types=1);

namespace Velk\Services;

use Velk\App;
use Velk\Models\Member;
use Velk\Models\MemberSubscription;
use Velk\Models\Option;

/**
 * Regra de negócio das assinaturas. Orquestra StripeClient + persistência;
 * o CMS é a fonte de verdade dos preços (editados no painel), o webhook é
 * a fonte de verdade do estado da assinatura.
 *
 * A lógica pura (merge de planos, lookup de cache de preço) é estática e
 * testável sem I/O. Os métodos de instância tocam Stripe/DB.
 */
final class SubscriptionService
{
    /** Chave de settings (fora de resolvedOptions → não aparece no admin). */
    private const BILLING_CACHE_KEY = '_stripe_billing';

    public function __construct(private StripeClient $stripe) {}

    public static function make(): self
    {
        return new self(StripeClient::fromEnv());
    }

    public function isEnabled(): bool
    {
        return $this->stripe->isConfigured()
            && App::instance()->config->memberPlans() !== [];
    }

    // ─── Lógica pura (testável) ───────────────────────────────────

    /**
     * Junta a estrutura declarada (site.json) com os valores do painel
     * (option group). PURO. Cada plano: key, name, description, features,
     * active, currency, prices{interval:{amount,formatted}}.
     */
    public static function buildPlans(array $declared, array $values, bool $onlyActive = true): array
    {
        $currency = strtoupper(trim((string) ($values['currency'] ?? 'BRL'))) ?: 'BRL';
        $out = [];
        foreach ($declared as $key => $plan) {
            if (!is_array($plan)) {
                continue;
            }
            $active = !empty($values[$key . '_active']);
            if ($onlyActive && !$active) {
                continue;
            }

            $intervals = (array) ($plan['intervals'] ?? ['month']);
            $prices = [];
            foreach (['month', 'year'] as $interval) {
                if (!in_array($interval, $intervals, true)) {
                    continue;
                }
                $cents = Money::toCents($values[$key . '_price_' . $interval] ?? null);
                if ($cents === null || $cents <= 0) {
                    continue;
                }
                $prices[$interval] = [
                    'amount' => $cents,
                    'formatted' => Money::format($cents, $currency),
                ];
            }
            if ($onlyActive && $prices === []) {
                continue;
            }

            $out[] = [
                'key' => (string) $key,
                'name' => (string) ($plan['name'] ?? $key),
                'description' => (string) ($plan['description'] ?? ''),
                'features' => array_values((array) ($plan['features'] ?? [])),
                'active' => $active,
                'currency' => $currency,
                'prices' => $prices,
            ];
        }
        return $out;
    }

    /** PURO. Devolve o price_id em cache se o valor bate, senão null. */
    public static function priceCacheLookup(array $cache, string $planKey, string $interval, int $cents): ?string
    {
        $entry = $cache[$planKey][$interval] ?? null;
        if (is_array($entry) && (int) ($entry['amount'] ?? -1) === $cents && !empty($entry['price_id'])) {
            return (string) $entry['price_id'];
        }
        return null;
    }

    // ─── Leitura de planos ────────────────────────────────────────

    public function plans(bool $onlyActive = true): array
    {
        $declared = App::instance()->config->memberPlans();
        $values = Option::getGroup('subscriptions');
        return self::buildPlans($declared, $values, $onlyActive);
    }

    public function findPlan(string $planKey, bool $onlyActive = false): ?array
    {
        foreach ($this->plans($onlyActive) as $p) {
            if ($p['key'] === $planKey) {
                return $p;
            }
        }
        return null;
    }

    // ─── Stripe: preço, customer, checkout, portal ────────────────

    /**
     * price_id do Stripe que bate com o preço ATUAL do painel. Cria
     * Product (uma vez) e Price (quando o valor mudou) lazy; atualiza o
     * cache. Auto-invalidante por comparação de valor.
     */
    public function ensurePrice(string $planKey, string $interval): string
    {
        $plan = $this->findPlan($planKey, false);
        if ($plan === null || !isset($plan['prices'][$interval])) {
            throw new \RuntimeException("Plano ou intervalo inválido: {$planKey}/{$interval}");
        }
        $cents = (int) $plan['prices'][$interval]['amount'];
        $currency = (string) $plan['currency'];

        $cache = Option::getGroup(self::BILLING_CACHE_KEY);
        $hit = self::priceCacheLookup($cache, $planKey, $interval, $cents);
        if ($hit !== null) {
            return $hit;
        }

        $productId = $cache[$planKey]['product_id'] ?? null;
        if (!is_string($productId) || $productId === '') {
            $product = $this->stripe->createProduct($plan['name'], ['plan_key' => $planKey]);
            $productId = (string) $product['id'];
            $cache[$planKey]['product_id'] = $productId;
        }

        $price = $this->stripe->createPrice($productId, $cents, $currency, $interval);
        $cache[$planKey][$interval] = ['amount' => $cents, 'price_id' => (string) $price['id']];
        Option::setGroup(self::BILLING_CACHE_KEY, $cache);

        return (string) $price['id'];
    }

    private function ensureCustomer(Member $member): string
    {
        if ($member->stripeCustomerId !== null && $member->stripeCustomerId !== '') {
            return $member->stripeCustomerId;
        }
        $customer = $this->stripe->createCustomer(
            $member->email,
            $member->name,
            ['member_id' => (string) $member->id]
        );
        $id = (string) $customer['id'];
        $member->save(['stripe_customer_id' => $id]);
        return $id;
    }

    public function createCheckoutSession(
        Member $member,
        string $planKey,
        string $interval,
        string $successUrl,
        string $cancelUrl
    ): string {
        $customerId = $this->ensureCustomer($member);
        $priceId = $this->ensurePrice($planKey, $interval);
        $session = $this->stripe->createSubscriptionCheckout(
            $customerId,
            $priceId,
            $successUrl,
            $cancelUrl,
            ['member_id' => (string) $member->id, 'plan_key' => $planKey, 'interval' => $interval]
        );
        if (empty($session['url'])) {
            throw new \RuntimeException('Stripe não retornou URL de checkout.');
        }
        return (string) $session['url'];
    }

    public function createPortalSession(Member $member, string $returnUrl): string
    {
        if ($member->stripeCustomerId === null || $member->stripeCustomerId === '') {
            throw new \RuntimeException('Membro sem Customer no Stripe.');
        }
        $session = $this->stripe->createPortalSession($member->stripeCustomerId, $returnUrl);
        if (empty($session['url'])) {
            throw new \RuntimeException('Stripe não retornou URL do portal.');
        }
        return (string) $session['url'];
    }

    // ─── Webhook ──────────────────────────────────────────────────

    /** Verifica a assinatura e roteia o evento. Retorna false se inválido. */
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
        // Timestamp do evento — usado para aplicar upserts em ordem (C1).
        $eventTs = (int) ($event['created'] ?? time());

        switch ($type) {
            case 'checkout.session.completed':
                $subId = $object['subscription'] ?? null;
                if (is_string($subId) && $subId !== '') {
                    $sub = $this->stripe->retrieveSubscription($subId);
                    // Garante member_id mesmo se a subscription não herdou metadata.
                    if (empty($sub['metadata']['member_id']) && !empty($object['metadata']['member_id'])) {
                        $sub['metadata']['member_id'] = $object['metadata']['member_id'];
                    }
                    $this->syncFromStripeSubscription($sub, $eventTs, $type);
                }
                break;

            case 'customer.subscription.created':
            case 'customer.subscription.updated':
            case 'customer.subscription.deleted':
                $this->syncFromStripeSubscription($object, $eventTs, $type);
                break;
        }
        return true;
    }

    /**
     * Upsert do estado a partir de um objeto subscription do Stripe.
     *
     * T4 (payment-webhook): `$stripeEvent` é o TYPE bruto do evento Stripe
     * (ex.: "customer.subscription.updated") — só handleWebhook() sabe esse
     * valor, então ele é "threaded" aqui pra virar `$context['stripe_event']`
     * no dispatch do `subscription.<status>`. Default null preserva a
     * assinatura pra qualquer outro chamador (nenhum encontrado além dos
     * dois pontos internos em handleWebhook() acima — grep confirmado).
     */
    public function syncFromStripeSubscription(array $sub, int $eventTs, ?string $stripeEvent = null): void
    {
        $memberId = $this->resolveMemberId($sub);
        if ($memberId === null) {
            error_log('[Velk] webhook: subscription órfã (sem member_id resolvível): ' . ($sub['id'] ?? '?'));
            return;
        }

        $item = $sub['items']['data'][0] ?? [];
        $interval = $item['price']['recurring']['interval']
            ?? ($item['plan']['interval'] ?? 'month');

        // plan_key: deriva do preço/produto efetivamente cobrado (robusto a
        // upgrade/downgrade pelo Customer Portal, que NÃO atualiza a metadata
        // da subscription — I1), caindo para a metadata só sem correspondência.
        $productId = $item['price']['product'] ?? null;
        $priceId = $item['price']['id'] ?? null;
        $planKey = self::planKeyForStripe(
            Option::getGroup(self::BILLING_CACHE_KEY),
            is_string($productId) ? $productId : null,
            is_string($priceId) ? $priceId : null
        ) ?? (string) ($sub['metadata']['plan_key'] ?? '');

        // I11: raiz vence quando presente; cai pro item (API 2025-03-31.basil+)
        // via currentPeriodEndFromStripe() — seleção pura, testada em isolado.
        $periodEnd = self::currentPeriodEndFromStripe($sub);

        $subData = [
            'member_id' => $memberId,
            'plan_key' => $planKey,
            'billing_interval' => (string) $interval,
            'status' => (string) ($sub['status'] ?? ''),
            'stripe_customer_id' => (string) ($sub['customer'] ?? ''),
            'stripe_subscription_id' => (string) ($sub['id'] ?? ''),
            // UTC explícito (I2) — o epoch do Stripe é UTC; gmdate evita
            // gravar no fuso local do servidor.
            'current_period_end' => $periodEnd !== null
                ? gmdate('Y-m-d H:i:s', $periodEnd)
                : null,
            'cancel_at_period_end' => !empty($sub['cancel_at_period_end']) ? 1 : 0,
            'last_event_ts' => $eventTs,
        ];

        $applied = MemberSubscription::upsertFromStripe($subData, $eventTs);

        // Cardinalidade (spec 2026-09-07, decisão 3): a guarda do formulário
        // não fecha a janela entre o clique e o webhook — duas abas concluídas
        // antes do primeiro evento passam as duas. Aqui dá para DETECTAR:
        // grava mesmo assim (o dinheiro já saiu), registra com destaque e o
        // admin marca. Nunca cancela nem estorna — decisão de dinheiro sem
        // humano. Só reavalia quando o evento aplicou E deixou uma ativa; um
        // cancelamento não pode gerar aviso.
        if ($applied !== null && MemberSubscription::isActiveStatus((string) $subData['status'])) {
            try {
                $this->logCardinalityViolation($memberId);
            } catch (\Throwable $e) {
                error_log('[Velk] cardinalidade de assinatura: ' . $e->getMessage());
            }
        }

        // Dispara o payment_webhook do tema SÓ quando o upsert de fato
        // aplicou (nunca em reentrega/evento fora de ordem que a guarda de
        // idempotência descartou) E quando é uma transição de verdade —
        // tie-replay hardening (design doc "Events → Subscriptions"):
        // status mudou OU o ts do evento avançou (renovação com o MESMO
        // status ainda dispara; um replay byte-idêntico, mesmo status e
        // mesmo ts, não). Sempre DEPOIS da escrita no banco, isolado num
        // try/catch próprio: nada aqui pode derrubar o processamento do
        // webhook (Stripe precisa receber 2xx mesmo se um handler de tema
        // quebrar).
        if ($applied !== null && ($applied['previous_status'] !== $subData['status'] || $applied['ts_advanced'])) {
            try {
                $this->dispatchSubscriptionWebhook($subData, $applied['previous_status'], $stripeEvent, $eventTs);
            } catch (\Throwable $e) {
                error_log('[Velk payment_webhook] ' . $e->getMessage());
            }
        }
    }

    /**
     * Registra quando o conjunto de assinaturas ATIVAS do membro excede a
     * regra de site.json → members.multiple_plans. Público pelo mesmo
     * motivo que syncFromStripeSubscription(): o teste chama direto.
     */
    public function logCardinalityViolation(int $memberId): void
    {
        $multiple = App::instance()->config->allowsMultiplePlans();
        $active = array_map(
            fn(MemberSubscription $s) => $s->planKey,
            MemberSubscription::activeListForMember($memberId)
        );
        if (!MemberSubscription::violatesCardinality($active, $multiple)) {
            return;
        }
        error_log(sprintf(
            '[Velk] ATENÇÃO: membro %d tem assinaturas ativas além da regra (%s): %s — verifique no Stripe',
            $memberId,
            $multiple ? 'multiple_plans: uma por plano' : 'uma por membro',
            implode(', ', $active)
        ));
    }

    /**
     * Monta o payload/context do `subscription.<status>` (contrato exato do
     * design doc) e dispara via WebhookDispatcher. Reaproveita o MESMO
     * array já escrito pelo upsert ($subData) — não reconsulta o banco nem
     * reprocessa o $sub cru do Stripe.
     */
    private function dispatchSubscriptionWebhook(
        array $subData,
        ?string $previousStatus,
        ?string $stripeEvent,
        int $eventTs
    ): void {
        $data = [
            'member_id' => (int) $subData['member_id'],
            'plan_key' => (string) $subData['plan_key'],
            'status' => (string) $subData['status'],
            'previous_status' => $previousStatus,
            'billing_interval' => (string) $subData['billing_interval'],
            'current_period_end' => $subData['current_period_end'],
            'cancel_at_period_end' => (bool) $subData['cancel_at_period_end'],
        ];
        $context = [
            'stripe_event' => $stripeEvent,
            'event_ts' => $eventTs,
            'stripe_subscription_id' => (string) $subData['stripe_subscription_id'],
            'stripe_customer_id' => (string) $subData['stripe_customer_id'],
        ];

        WebhookDispatcher::dispatch(
            App::instance()->config->paymentWebhook(),
            'Velk payment_webhook',
            ['subscription.' . $subData['status'], $data, $context]
        );
    }

    /**
     * PURO. Reverse-lookup do planKey a partir do product_id ou price_id do
     * Stripe, usando o cache _stripe_billing. Null se não houver match.
     */
    public static function planKeyForStripe(array $cache, ?string $productId, ?string $priceId): ?string
    {
        foreach ($cache as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if ($productId !== null && ($entry['product_id'] ?? null) === $productId) {
                return (string) $key;
            }
            foreach (['month', 'year'] as $iv) {
                if ($priceId !== null && (($entry[$iv]['price_id'] ?? null) === $priceId)) {
                    return (string) $key;
                }
            }
        }
        return null;
    }

    /**
     * PURO. I11: a partir da versão de API do Stripe 2025-03-31.basil,
     * webhooks de subscription não trazem mais `current_period_end` na
     * raiz — o valor passou a viver em `items.data[0].current_period_end`
     * (cobrança por item). A raiz vence quando presente (compat com
     * versões antigas / payloads já achatados); cai pro primeiro item
     * quando ausente ou null. Null quando nenhum dos dois traz o campo.
     */
    public static function currentPeriodEndFromStripe(array $sub): ?int
    {
        if (isset($sub['current_period_end'])) {
            return (int) $sub['current_period_end'];
        }
        $fallback = $sub['items']['data'][0]['current_period_end'] ?? null;
        return $fallback !== null ? (int) $fallback : null;
    }

    /** Resolve o membro: metadata primeiro, customer_id como fallback. */
    private function resolveMemberId(array $sub): ?int
    {
        $mid = $sub['metadata']['member_id'] ?? null;
        if ($mid !== null && (int) $mid > 0) {
            return (int) $mid;
        }
        $customerId = (string) ($sub['customer'] ?? '');
        if ($customerId !== '') {
            $row = App::instance()->db->fetch(
                'SELECT id FROM members WHERE stripe_customer_id = ? LIMIT 1',
                [$customerId]
            );
            if ($row !== null) {
                return (int) $row['id'];
            }
        }
        return null;
    }
}
