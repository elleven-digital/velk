<?php

declare(strict_types=1);

namespace Velk;

/**
 * Parâmetros de rastreamento atribuídos à visita — UTM e click IDs de
 * anúncio, num conceito só.
 *
 * Captura da query string, persiste na sessão e entrega junto da submissão
 * de formulário (ou do cadastro de membro), pra a ferramenta do cliente
 * saber de onde veio aquele lead. Um click ID em particular é o que permite
 * a IMPORTAÇÃO DE CONVERSÃO OFFLINE de volta na plataforma de anúncio —
 * "esse clique específico virou venda" —, deixando o algoritmo de lances
 * otimizar por conversão real. O Velk captura e entrega; a importação é
 * sempre trabalho de fora.
 *
 * Substitui as antigas Velk\Utm e Velk\ClickIds, que eram a mesma classe
 * com constantes diferentes. Um conceito, uma whitelist, uma coluna
 * (`form_submissions.params`), uma chave no payload.
 *
 * ─── Whitelist FECHADA, e é o ponto principal ───────────────────────────
 *
 * Capturar qualquer parâmetro da URL seria um vetor de escrita ilimitado
 * (?qualquer_coisa=valor engordando o banco pra sempre, controlado por quem
 * monta o link) e um problema de LGPD: nada impede uma URL compartilhada
 * com PII acidental na query string (?cpf=..., ?email=...) de ser capturada
 * e persistida. Por isso o laço percorre a WHITELIST, nunca a query string —
 * capturar chave arbitrária é estruturalmente impossível, não uma checagem
 * que alguém pode esquecer.
 *
 * ─── Last-touch é POR GRUPO, de propósito ───────────────────────────────
 *
 * UTM e click ID respondem perguntas diferentes: uma diz a campanha (escrita
 * pelo marketing), a outra diz o clique específico (gerada pela plataforma).
 * Chegam juntas ou separadas, e têm vidas independentes.
 *
 * Por isso cada grupo tem seu próprio last-touch: chegar com `?gclid=x` sem
 * UTM substitui o click ID e DEIXA a UTM da newsletter de ontem intacta. Um
 * last-touch único zeraria a origem e a submissão sairia com click ID sem
 * campanha — menos informação, sem ninguém pedir.
 *
 * Dentro de um grupo, porém, a substituição é total e nunca mescla: não
 * existe "gclid de segunda + fbclid de sexta" ao mesmo tempo, porque
 * atribuição dupla é atribuição errada — só um clique pode ter sido o
 * último antes da conversão.
 *
 * Visita sem nenhuma chave da whitelist não toca a sessão, então a
 * atribuição sobrevive à navegação normal: o visitante cai na landing com
 * `?utm_source=google`, preenche o formulário três páginas depois, e a
 * origem ainda está lá.
 */
final class Params
{
    /**
     * Campanha, escrita por quem monta o link.
     */
    public const UTM_KEYS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
    ];

    /**
     * Click IDs, gerados pela plataforma de anúncio no clique.
     * gclid/gbraid/wbraid = Google Ads · fbclid = Meta · msclkid =
     * Microsoft Ads · ttclid = TikTok · li_fat_id = LinkedIn · twclid = X.
     */
    public const CLICK_ID_KEYS = [
        'gclid', 'gbraid', 'wbraid',
        'fbclid',
        'msclkid',
        'ttclid',
        'li_fat_id',
        'twclid',
    ];

    /**
     * Tudo que pode ser capturado. Adicionar um parâmetro novo (uma
     * plataforma nova, um `utm_id`) é uma entrada aqui e nada mais —
     * nenhuma migration, porque o storage é JSON.
     */
    public const WHITELIST = [...self::UTM_KEYS, ...self::CLICK_ID_KEYS];

    /**
     * Um grupo = um escopo de last-touch. Ver o docblock da classe.
     *
     * @var array<string, list<string>>
     */
    private const GROUPS = [
        'utm'       => self::UTM_KEYS,
        'click_ids' => self::CLICK_ID_KEYS,
    ];

    private const SESSION_KEY = 'velk_params';
    private const MAX_LENGTH = 255;

    /**
     * Lê os parâmetros da query string e persiste na sessão. Cada grupo que
     * aparecer na URL substitui o que havia guardado daquele grupo; grupo
     * ausente da URL fica intacto.
     */
    public static function captureFromRequest(Request $request, Session $session): void
    {
        if ($request->method !== 'GET') {
            return;
        }

        $stored = self::stored($session);
        $touched = false;

        foreach (self::GROUPS as $group => $keys) {
            $captured = [];
            foreach ($keys as $key) {
                $raw = $request->query[$key] ?? null;
                if (!is_string($raw)) continue;
                $value = trim($raw);
                if ($value === '') continue;
                $captured[$key] = mb_substr($value, 0, self::MAX_LENGTH);
            }

            if ($captured === []) {
                continue; // grupo ausente da URL — preserva o que já valia
            }

            // Substitui o grupo inteiro; nunca mescla dentro dele.
            foreach ($keys as $key) {
                unset($stored[$key]);
            }
            $stored += $captured;
            $touched = true;
        }

        if ($touched) {
            $session->put(self::SESSION_KEY, $stored);
        }
    }

    /**
     * Os parâmetros atribuídos agora. Só chaves da whitelist com valor de
     * fato presente — array vazio quando nada foi capturado. Formato já
     * pronto pra virar o JSON gravado em `form_submissions.params` e a
     * chave `params` do payload de conversão.
     *
     * @return array<string, string>
     */
    public static function fromSession(Session $session): array
    {
        $stored = self::stored($session);
        $out = [];
        foreach (self::WHITELIST as $key) {
            $val = $stored[$key] ?? null;
            if (is_string($val) && $val !== '') {
                $out[$key] = $val;
            }
        }
        return $out;
    }

    /** Se há qualquer parâmetro atribuído na sessão. */
    public static function hasAny(Session $session): bool
    {
        return self::fromSession($session) !== [];
    }

    /**
     * Esquece o que foi atribuído. Não usado pelos fluxos do core — a
     * atribuição vale por toda a vida da sessão — mas exposto pra tema que
     * queira resetar depois de uma conversão.
     */
    public static function clear(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
    }

    /** @return array<string, mixed> conteúdo cru da sessão, sem confiar na forma */
    private static function stored(Session $session): array
    {
        $raw = $session->get(self::SESSION_KEY);
        return is_array($raw) ? $raw : [];
    }
}
