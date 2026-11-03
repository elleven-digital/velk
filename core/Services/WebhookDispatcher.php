<?php

declare(strict_types=1);

namespace Velk\Services;

/**
 * Disparo genérico de webhooks declarados em site.json e resolvidos como
 * função(ões) PHP em `theme/functions.php` — o mesmo mecanismo já usado
 * pelo webhook de formulários (`forms.<id>.webhook`) e, a partir daqui,
 * compartilhado também pelo webhook global de pagamento
 * (`payment_webhook`). Extraído da metade genérica de
 * `PublicFormController::dispatchWebhook` (comportamento idêntico, zero
 * mudança de semântica) pra eliminar a duplicação verbatim entre os
 * pontos de disparo (forms hoje; order/subscription depois).
 *
 * Nunca lança e nunca retorna nada — é sempre "melhor esforço": um nome
 * inválido, uma função ausente ou um handler que quebra viram
 * `error_log`, nunca interrompem o request que disparou o evento (ex.: o
 * webhook do Stripe precisa sempre receber 2xx, mesmo que um handler de
 * tema falhe).
 */
final class WebhookDispatcher
{
    /**
     * @param mixed $handlers Valor bruto declarado em site.json — tipado
     *   `mixed` (não `string|array|null`) DE PROPÓSITO: quem escreve
     *   site.json não é validado por tipo antes de chegar aqui (alguns
     *   pontos de disparo, como `forms.<id>.webhook`, passam o valor cru
     *   do JSON direto, sem normalização prévia), então um autor pode
     *   escrever `"webhook": 42` por engano. Com `strict_types=1`, um
     *   parâmetro tipado `string|array|null` faria isso virar `TypeError`
     *   — e esse dispatcher promete nunca lançar (é sempre "melhor
     *   esforço", ver docblock da classe). `null`/`''`/`[]` → nenhum
     *   handler (no-op, retorna direto); uma string → um único handler;
     *   um array → uma lista de nomes de função (cada entrada precisa ser
     *   string não-vazia após `trim()` — entradas que não forem isso são
     *   logadas e ignoradas, sem interromper as demais); qualquer OUTRO
     *   tipo (int, float, bool, objeto...) é logado e tratado como no-op.
     * @param string $logLabel Prefixo identificando a ORIGEM do disparo
     *   nas linhas de log (ex.: "Velk webhook form=contato" pros forms,
     *   "Velk payment_webhook" pro pagamento) — sai como `[$logLabel] ...`.
     * @param array $args Argumentos posicionais passados a cada handler via
     *   `$name(...$args)` — a mesma lista pra todos os handlers de um
     *   disparo.
     */
    public static function dispatch(mixed $handlers, string $logLabel, array $args): void
    {
        if ($handlers === null || $handlers === '' || $handlers === []) {
            return;
        }

        if (!is_string($handlers) && !is_array($handlers)) {
            error_log("[{$logLabel}] invalid webhook value in site.json (expected string or list of strings)");
            return;
        }

        $names = is_array($handlers) ? $handlers : [$handlers];

        foreach ($names as $handler) {
            $name = is_string($handler) ? trim($handler) : '';
            if ($name === '') {
                error_log("[{$logLabel}] empty handler name in site.json");
                continue;
            }
            if (!function_exists($name)) {
                error_log("[{$logLabel}] fn={$name}: function not defined (check theme/functions.php)");
                continue;
            }
            try {
                $name(...$args);
            } catch (\Throwable $e) {
                error_log("[{$logLabel}] fn={$name}: " . $e->getMessage());
            }
        }
    }
}
