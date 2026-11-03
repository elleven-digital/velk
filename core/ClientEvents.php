<?php

declare(strict_types=1);

namespace Velk;

/**
 * Fila de eventos de cliente: um fato que acabou de acontecer no servidor e
 * precisa virar um `dataLayer.push` no navegador.
 *
 * Existe por causa da ponte POST → redirect → GET. No instante em que a
 * conversão de fato acontece — `FormSubmission::create()`, `Member::create()`
 * — não existe HTML nenhum sendo renderizado: a resposta é um 302. O evento
 * então é guardado e emitido no render SEGUINTE, uma vez só.
 *
 * Dois drenos, e a distinção é o que mantém "exatamente uma vez" verdadeiro:
 *
 *   - resposta redirect → enfileira aqui, `tracking_head()` emite depois
 *   - resposta JSON     → vai no corpo da resposta e NÃO enfileira
 *
 * Enfileirar nos dois dispararia duas vezes; só enfileirar no modo JSON
 * dispararia tarde ou nunca (num tema SPA a página não re-renderiza).
 *
 * INTERNA nesta versão. Um segundo produtor (cadastro) já revelou o dreno
 * duplo e o terceiro (compra) tem modelo de confiança diferente — a verdade
 * de que houve compra chega por webhook da Stripe, sem sessão nenhuma. Expor
 * isso como API pública agora congelaria um formato que ainda vai mudar.
 *
 * Ver docs/superpowers/specs/2026-08-13-conversion-events-design.md
 */
final class ClientEvents
{
    private const SESSION_KEY = '_velk_events';

    /**
     * Janela de validade de uma entrada enfileirada.
     *
     * A emissão depende do tema chamar `tracking_head()` — o tema padrão
     * chama, mas um que não chame deixaria a entrada parada na sessão, e ela
     * dispararia numa visita futura qualquer, reportando conversão no momento
     * errado, possivelmente dias depois. POST → redirect é sub-segundo, então
     * 10 minutos é folga generosa e limita o estrago ao caso real.
     */
    public const TTL_SECONDS = 600;

    /**
     * As únicas chaves que podem sair em `user_data`.
     *
     * São as que o enhanced conversions do Google consome — mandar qualquer
     * outra coisa não melhora correspondência nenhuma, só expõe o dado à toa.
     * `buildUserData()` percorre ESTA lista, nunca o mapeamento recebido, então
     * empurrar uma chave arbitrária pro dataLayer é estruturalmente impossível
     * — mesmo padrão do `Params::WHITELIST`.
     */
    public const USER_DATA_KEYS = ['email', 'phone_number'];

    /**
     * O tema pediu para ENFILEIRAR o evento em vez de recebê-lo no corpo?
     *
     * Em modo JSON o padrão é devolver no corpo e não enfileirar (ver os dois
     * drenos no docblock da classe). Isso deixa um furo no arranjo mais comum
     * de conversão: tema AJAX que navega para uma página de obrigado. Empurrar
     * e navegar no mesmo instante corre contra o unload — o gtag do GA4
     * aguenta, uma tag HTML customizada do GTM pode não — e a perda é
     * silenciosa.
     *
     * O engine não tem como saber se o tema vai navegar; o TEMA tem. Com
     * `_event=queue` no POST ele diz, e a resposta enfileira em vez de
     * devolver. Ou um, ou outro — nunca os dois, então "exatamente uma vez"
     * continua verdadeiro.
     *
     * Valor exato de propósito: um truthy qualquer (`1`, `true`, `on`) ligaria
     * o modo por acidente em quem manda um campo `_event` com outro sentido.
     */
    public static function requestedQueue(Request $request): bool
    {
        return ($request->post['_event'] ?? null) === 'queue';
    }

    /**
     * Enfileira um evento para o próximo render de front.
     *
     * `$now` é injetável só para os testes exercitarem o TTL sem dormir.
     */
    public static function queue(
        Session $session,
        string $name,
        array $data,
        ?string $snippet = null,
        ?int $now = null
    ): void {
        $entries = self::entries($session);
        $entries[] = [
            'name'      => $name,
            'data'      => $data,
            'snippet'   => ($snippet !== null && $snippet !== '') ? $snippet : null,
            'queued_at' => $now ?? time(),
        ];
        $session->put(self::SESSION_KEY, $entries);
    }

    /**
     * Devolve os eventos válidos e ESVAZIA a fila.
     *
     * Consumo one-shot com get + forget, mesmo padrão de `form_status()` em
     * core/helpers.php — é o que impede um F5 na página de obrigado de contar
     * a conversão de novo. Esvazia antes de filtrar de propósito: entrada
     * vencida é descartada, não guardada pra uma próxima chance.
     *
     * @return list<array{name:string, data:array, snippet:?string, queued_at:int}>
     */
    public static function drain(Session $session, ?int $now = null): array
    {
        $entries = self::entries($session);
        $session->forget(self::SESSION_KEY);

        return self::fresh($entries, $now ?? time());
    }

    /** @return array<int, mixed> conteúdo cru da sessão, sem confiar na forma */
    private static function entries(Session $session): array
    {
        $raw = $session->get(self::SESSION_KEY);
        return is_array($raw) ? $raw : [];
    }

    /**
     * Descarta o que venceu e o que está malformado. A sessão é do usuário —
     * nunca confie na forma do que está lá dentro.
     */
    private static function fresh(array $entries, int $now): array
    {
        $out = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) continue;

            $queuedAt = $entry['queued_at'] ?? null;
            if (!is_int($queuedAt)) continue;
            if ($now - $queuedAt > self::TTL_SECONDS) continue;

            $out[] = $entry;
        }
        return $out;
    }

    /**
     * Drena a fila e devolve o HTML pronto — o que `tracking_head()` chama.
     */
    public static function flush(Session $session, ?int $now = null): string
    {
        return self::render(self::drain($session, $now));
    }

    /**
     * Emite os eventos como `<script>`. Pura: entradas entram, string sai.
     *
     * Dois blocos SEPARADOS por evento, nesta ordem, e a separação é
     * requisito e não estilo: se o gestor colar JS com erro de sintaxe no
     * snippet, o navegador descarta o bloco inteiro. No mesmo bloco isso
     * levaria o push do core junto. `try/catch` não resolve — erro de
     * sintaxe acontece na leitura, não na execução. Isolar é a única
     * mitigação possível.
     *
     * O guard `window.dataLayer = window.dataLayer || []` vem antes do push
     * pra a ordem em relação ao snippet do GTM não importar: se o push
     * chegar primeiro, o GTM processa a fila ao inicializar.
     */
    public static function render(array $entries): string
    {
        $out = '';
        $nonce = PublicCsp::nonce();

        foreach ($entries as $entry) {
            if (!is_array($entry)) continue;

            $name = $entry['name'] ?? '';
            // O nome vira identificador JS abaixo. A sessão é do usuário, e
            // um nome fora do formato esperado não pode virar código.
            if (!is_string($name) || preg_match('/^[a-z][a-z0-9_]*$/D', $name) !== 1) {
                continue;
            }

            $var = self::varNameFor($name);
            $data = is_array($entry['data'] ?? null) ? $entry['data'] : [];

            // JSON_HEX_* é o que impede um valor de dado fechar o <script>.
            // Não é hipotético: valores de utm vêm da query string, ou seja,
            // são controlados por quem monta o link.
            //
            // JSON_INVALID_UTF8_SUBSTITUTE porque `user_data` chega CRU do
            // POST (buildUserData só apara espaço), então um campo mapeado
            // com bytes inválidos faria json_encode devolver false. Sem isso,
            // um cast pra string emitiria `window.velkFormSuccess = ;` — erro
            // de sintaxe que faz o navegador descartar o bloco inteiro e a
            // conversão sumir sem rastro. Substituir o byte perde no máximo a
            // correspondência daquele identificador; falhar perde a conversão.
            // (os params não chegam aqui inválidos: Params::captureFromRequest
            // Params já passa por mb_substr.)
            // Sem JSON_FORCE_OBJECT de propósito: ele forçaria QUALQUER array a
            // virar objeto, e um produtor futuro com uma lista de verdade (os
            // itens de uma compra) veria `{"0":…,"1":…}` sem entender por quê.
            // Quem sabe que utm/click_ids/user_data são mapas é o produtor, e
            // é lá que eles são convertidos com (object).
            $json = json_encode(
                $data,
                JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS
                    | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
            );

            // Cinto-e-suspensório: com a substituição acima o encode
            // praticamente não falha, mas se falhar (um resource no payload,
            // por exemplo) pula a entrada inteira. Não emitir é sempre melhor
            // do que emitir um bloco que quebra.
            if (!is_string($json)) {
                continue;
            }

            $out .= "\n<script nonce=\"{$nonce}\">\n"
                . "window.{$var} = {$json};\n"
                . "window.dataLayer = window.dataLayer || [];\n"
                . "window.dataLayer.push(Object.assign({ event: \"{$name}\" }, window.{$var}));\n"
                . "</script>\n";

            $snippet = $entry['snippet'] ?? null;
            if (is_string($snippet) && trim($snippet) !== '') {
                // Cru, mesmo modelo de confiança do tracking.head: é código
                // que um admin autenticado colou de propósito.
                $out .= "<script nonce=\"{$nonce}\">\n" . $snippet . "\n</script>\n";
            }
        }

        return $out;
    }

    /**
     * `velk_form_success` → `velkFormSuccess`.
     *
     * Transformação mecânica pra o contrato do snippet valer igual em todo
     * produtor: quem escreve `velkFormSuccess.submission_id` num formulário
     * escreve `velkSignupSuccess.member_id` num cadastro, sem decorar tabela.
     */
    private static function varNameFor(string $eventName): string
    {
        return lcfirst(str_replace('_', '', ucwords($eventName, '_')));
    }

    /**
     * Monta o `user_data` a partir do mapeamento configurado no painel.
     *
     * `$mapping` é `chave-do-Google => nome-do-campo-de-origem`
     * (ex.: `['email' => 'seu_email']`); `$values` é a saída do
     * `FieldRenderer::collect` — ou, no cadastro, um array sintético.
     *
     * A chave de SAÍDA é sempre a do Google, nunca o nome do campo de origem:
     * é isso que o enhanced conversions consegue consumir. Foi por isso que o
     * painel virou mapeamento em vez de lista de checkbox — uma checkbox num
     * campo `mensagem` seria ignorada pelo Google e ficaria exposta à toa.
     *
     * Pura: sem banco, sem sessão, sem I/O.
     *
     * @param  array<string,mixed> $mapping
     * @param  array<string,mixed> $values
     * @return array<string,string>
     */
    public static function buildUserData(array $mapping, array $values): array
    {
        $out = [];

        foreach (self::USER_DATA_KEYS as $key) {
            $source = $mapping[$key] ?? null;
            if (!is_string($source) || $source === '') continue;

            $value = $values[$source] ?? null;
            // Só escalar de texto/número. Campo `file` guarda array de
            // metadados e boolean viraria "1" — nada disso pode virar
            // identificador.
            if (!is_string($value) && !is_int($value) && !is_float($value)) continue;

            $value = trim((string) $value);
            // Vazio é pior que ausente: o Google tentaria casar contra string
            // vazia e a taxa de correspondência cairia sem sintoma nenhum.
            if ($value === '') continue;

            $out[$key] = $value;
        }

        return $out;
    }
}
