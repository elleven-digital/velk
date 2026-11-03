<?php

declare(strict_types=1);

namespace Velk\Services;

use Velk\Env;

/**
 * Cliente fino da API REST do Stripe. Sem SDK, sem Composer — só cURL
 * nativo + hash_hmac (mesmo espírito do Mailer, que fala SMTP na unha).
 *
 * Cobre apenas os endpoints que a feature de assinatura usa. As chaves
 * vêm do .env via Env::get(), padrão Mailer::fromEnv().
 */
final class StripeClient
{
    private const API_BASE = 'https://api.stripe.com';
    private const API_VERSION = '2024-06-20';
    private const TIMEOUT = 30;

    public function __construct(
        private readonly string $secretKey,
        private readonly string $webhookSecret = '',
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            (string) Env::get('STRIPE_SECRET_KEY', ''),
            (string) Env::get('STRIPE_WEBHOOK_SECRET', ''),
        );
    }

    /** Factory para a LOJA: mesma chave secreta, secret de webhook próprio. */
    public static function storeFromEnv(): self
    {
        return new self(
            (string) Env::get('STRIPE_SECRET_KEY', ''),
            (string) Env::get('STRIPE_STORE_WEBHOOK_SECRET', ''),
        );
    }

    public function isConfigured(): bool
    {
        return $this->secretKey !== '';
    }

    // ─── Endpoints de alto nível ──────────────────────────────────

    public function createCustomer(string $email, ?string $name, array $metadata = []): array
    {
        $params = ['email' => $email];
        if ($name !== null && $name !== '') {
            $params['name'] = $name;
        }
        foreach ($metadata as $k => $v) {
            $params['metadata'][$k] = (string) $v;
        }
        // member_id vem no metadata (ver SubscriptionService::ensureCustomer);
        // o e-mail entra na chave porque e-mail diferente é cliente diferente.
        $key = isset($metadata['member_id'])
            ? self::idempotencyKey('customer', (string) $metadata['member_id'], substr(hash('sha256', strtolower($email)), 0, 12))
            : null;
        return $this->post('/v1/customers', $params, $key);
    }

    public function createProduct(string $name, array $metadata = []): array
    {
        $params = ['name' => $name];
        foreach ($metadata as $k => $v) {
            $params['metadata'][$k] = (string) $v;
        }
        $key = isset($metadata['plan_key'])
            ? self::idempotencyKey('product', (string) $metadata['plan_key'])
            : null;
        return $this->post('/v1/products', $params, $key);
    }

    public function createPrice(string $productId, int $unitAmount, string $currency, string $interval): array
    {
        return $this->post('/v1/prices', [
            'product' => $productId,
            'unit_amount' => $unitAmount,
            'currency' => strtolower($currency),
            'recurring' => ['interval' => $interval],
        ], self::idempotencyKey('price', $productId, $interval, (string) $unitAmount, strtolower($currency)));
    }

    public function createSubscriptionCheckout(
        string $customerId,
        string $priceId,
        string $successUrl,
        string $cancelUrl,
        array $metadata = []
    ): array {
        $params = [
            'mode' => 'subscription',
            'customer' => $customerId,
            'line_items' => [['price' => $priceId, 'quantity' => 1]],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ];
        foreach ($metadata as $k => $v) {
            // metadata na sessão E na subscription — assim os eventos
            // customer.subscription.* já trazem member_id/plan_key.
            $params['metadata'][$k] = (string) $v;
            $params['subscription_data']['metadata'][$k] = (string) $v;
        }
        return $this->post('/v1/checkout/sessions', $params);
    }

    public function createPaymentCheckout(
        array $lineItems,
        string $successUrl,
        string $cancelUrl,
        ?string $customerEmail = null,
        array $metadata = []
    ): array {
        $params = [
            'mode' => 'payment',
            'line_items' => $lineItems,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ];
        if ($customerEmail !== null && $customerEmail !== '') {
            $params['customer_email'] = $customerEmail;
        }
        foreach ($metadata as $k => $v) {
            // metadata na sessão E no PaymentIntent (útil p/ refund futuro).
            $params['metadata'][$k] = (string) $v;
            $params['payment_intent_data']['metadata'][$k] = (string) $v;
        }
        return $this->post('/v1/checkout/sessions', $params);
    }

    public function createPortalSession(string $customerId, string $returnUrl): array
    {
        return $this->post('/v1/billing_portal/sessions', [
            'customer' => $customerId,
            'return_url' => $returnUrl,
        ]);
    }

    public function retrieveSubscription(string $subscriptionId): array
    {
        return $this->get('/v1/subscriptions/' . urlencode($subscriptionId));
    }

    /**
     * Verifica a assinatura de um webhook do Stripe. PURO — sem rede.
     * $now é injetável para testes (default: time()).
     */
    public function verifyWebhookSignature(
        string $payload,
        string $sigHeader,
        int $toleranceSeconds = 300,
        ?int $now = null
    ): bool {
        if ($this->webhookSecret === '' || $sigHeader === '') {
            return false;
        }
        $now ??= time();

        $parts = [];
        foreach (explode(',', $sigHeader) as $piece) {
            $kv = explode('=', $piece, 2);
            if (count($kv) === 2) {
                $parts[trim($kv[0])][] = trim($kv[1]);
            }
        }

        $timestamp = $parts['t'][0] ?? null;
        $signatures = $parts['v1'] ?? [];
        if ($timestamp === null || $signatures === []) {
            return false;
        }
        if (abs($now - (int) $timestamp) > $toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $this->webhookSecret);
        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }
        return false;
    }

    // ─── HTTP ─────────────────────────────────────────────────────

    private function post(string $path, array $params, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', $path, $params, $idempotencyKey);
    }

    /**
     * PURO. A chave de idempotência de uma criação, determinística por
     * intenção. O Stripe guarda a chave por 24h e devolve o MESMO objeto
     * para a mesma chave — é o que impede um timeout com resposta perdida,
     * seguido de novo clique, de criar cliente/produto/preço em dobro.
     * Os parâmetros entram na chave de propósito: mudou o e-mail ou o
     * valor, é outra intenção e merece outro objeto.
     */
    public static function idempotencyKey(string $kind, string ...$parts): string
    {
        $raw = 'velk-' . $kind . '-' . implode('-', $parts);
        // Stripe aceita até 255 chars; hash mantém curto e sem caractere estranho.
        return strlen($raw) <= 200 && preg_match('/^[A-Za-z0-9._-]+$/', $raw) === 1
            ? $raw
            : 'velk-' . $kind . '-' . hash('sha256', $raw);
    }

    private function get(string $path): array
    {
        return $this->request('GET', $path, null);
    }

    private function request(string $method, string $path, ?array $params, ?string $idempotencyKey = null): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Stripe não configurado (STRIPE_SECRET_KEY ausente).');
        }

        $headers = ['Stripe-Version: ' . self::API_VERSION];
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        $ch = curl_init();
        $opts = [
            CURLOPT_URL => self::API_BASE . $path,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $this->secretKey . ':',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => self::TIMEOUT,
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            // http_build_query usa notação de colchetes — aceita pelo Stripe.
            $opts[CURLOPT_POSTFIELDS] = http_build_query($params ?? [], '', '&');
        }
        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('Falha na requisição ao Stripe: ' . $err);
        }
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Stripe retornou resposta não-JSON (HTTP ' . $code . ').');
        }
        if ($code >= 400) {
            $msg = $data['error']['message'] ?? 'Erro desconhecido';
            throw new \RuntimeException('Erro do Stripe (HTTP ' . $code . '): ' . $msg);
        }
        return $data;
    }
}
