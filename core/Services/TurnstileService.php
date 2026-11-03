<?php

declare(strict_types=1);

namespace Velk\Services;

use Velk\Env;

/**
 * Cloudflare Turnstile — verificação anti-bot dos formulários públicos.
 *
 * Liga pela PRESENÇA das chaves no `.env`, sem opção no painel — mesmo
 * padrão da loja, que acende quando `STRIPE_SECRET_KEY` existe:
 *
 *     TURNSTILE_SITE_KEY=0x4AAA...
 *     TURNSTILE_SECRET_KEY=0x4AAA...
 *
 * Faltando qualquer uma das duas, o recurso inteiro fica inerte: o helper
 * `form_captcha()` devolve string vazia e o `submit()` nem chama a
 * verificação. Um tema que já imprima o helper continua funcionando
 * exatamente igual num site sem chave.
 *
 * A secret fica no `.env` e não no banco de propósito — chave em tabela
 * vaza em todo dump que circula por e-mail. A site key é pública (vai no
 * HTML), então não há problema em ela sair daqui também.
 *
 * ─── Por que Turnstile e não reCAPTCHA ──────────────────────────────────
 *
 * O reCAPTCHA manda dado pro Google, grava cookie e o Google usa pra
 * finalidades próprias — o que torna cada formulário um compartilhamento
 * de dado pessoal com terceiro, e portanto uma questão de base legal sob
 * LGPD. Um captcha que só pode rodar depois do aceite de cookies é um
 * captcha que não protege. A Cloudflare declara não usar os dados do
 * Turnstile para publicidade e não depender de cookie de rastreamento, o
 * que o acomoda com mais folga em "necessário à segurança do formulário".
 *
 * ─── Falha ABERTA quando a Cloudflare não responde ──────────────────────
 *
 * Se a verificação não puder ser concluída (timeout, DNS, provedor fora),
 * a submissão PASSA, com registro no log. É decisão consciente: o honeypot
 * continua rodando, e perder lead real durante indisponibilidade de um
 * terceiro custa mais do que deixar passar spam por alguns minutos. Um
 * token ausente ou inválido, porém, é rejeitado normalmente — isso é
 * resposta do provedor, não ausência dela.
 */
final class TurnstileService
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** Nome do campo que o widget injeta sozinho no formulário. */
    public const RESPONSE_FIELD = 'cf-turnstile-response';

    /** Curto de propósito: provedor pendurado não pode travar o envio. */
    private const TIMEOUT = 3;

    public static function isConfigured(): bool
    {
        return self::siteKey() !== '' && self::secretKey() !== '';
    }

    public static function siteKey(): string
    {
        return trim((string) Env::get('TURNSTILE_SITE_KEY', ''));
    }

    private static function secretKey(): string
    {
        return trim((string) Env::get('TURNSTILE_SECRET_KEY', ''));
    }

    /**
     * Verifica o token do widget. Devolve `true` quando a submissão pode
     * seguir — o que inclui o caso "não configurado" e o caso "não deu pra
     * perguntar" (ver falha aberta no docblock da classe).
     *
     * Só devolve `false` quando o Turnstile efetivamente reprovou, ou
     * quando o token nem veio.
     */
    public static function verify(?string $token, ?string $remoteIp = null): bool
    {
        if (!self::isConfigured()) {
            return true;
        }

        $token = trim((string) $token);
        if ($token === '') {
            // Sem token: ou o widget não foi renderizado (tema esqueceu o
            // helper), ou o JS foi bloqueado, ou é um POST direto de bot.
            // Rejeita — mas o visitante recebe erro honesto, não o sucesso
            // falso que o honeypot dá de propósito.
            return false;
        }

        $payload = ['secret' => self::secretKey(), 'response' => $token];
        if ($remoteIp !== null && $remoteIp !== '') {
            $payload['remoteip'] = $remoteIp;
        }

        $raw = self::post($payload);
        if ($raw === null) {
            error_log('[Velk] Turnstile: verificação indisponível — submissão liberada (falha aberta).');
            return true;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            error_log('[Velk] Turnstile: resposta ilegível — submissão liberada (falha aberta).');
            return true;
        }

        if (($data['success'] ?? false) === true) {
            return true;
        }

        // Reprovado de verdade. Os error-codes ajudam a diagnosticar
        // configuração errada (hostname não cadastrado, chave trocada).
        $codes = is_array($data['error-codes'] ?? null) ? implode(',', $data['error-codes']) : '?';
        error_log('[Velk] Turnstile: token rejeitado (' . $codes . ')');
        return false;
    }

    /** @param array<string,string> $payload @return ?string null = não deu pra perguntar */
    private static function post(array $payload): ?string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => self::VERIFY_URL,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload, '', '&'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
        ]);

        $raw = curl_exec($ch);
        $failed = $raw === false || (int) curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200;
        // Sem curl_close(): não faz nada desde o PHP 8.0 e está deprecado no
        // 8.5, que é a versão do host. O handle é liberado pelo GC.

        return $failed ? null : (string) $raw;
    }
}
