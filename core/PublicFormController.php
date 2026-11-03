<?php

declare(strict_types=1);

namespace Velk;

use Velk\Models\FormRecipient;
use Velk\Models\FormSettings;
use Velk\Models\FormSubmission;
use Velk\Services\FormUploadService;
use Velk\Services\TurnstileService;
use Velk\Services\WebhookDispatcher;

/**
 * Receives public form submissions at POST /forms/{id}.
 *
 * Validates against the form definition declared in `theme/site.json → forms`,
 * persists the data as JSON in `form_submissions`, emails the configured
 * recipients, and redirects back to the referrer with a flash status the
 * theme can pick up via `form_status($id)`.
 */
final class PublicFormController
{
    /**
     * Teto por campo ao gravar spam. Um lead legítimo cabe folgado; um bot
     * despejando payload gigante não transforma a tabela em depósito.
     */
    private const SPAM_VALUE_MAX_LENGTH = 2000;

    public function submit(Request $request, array $params): Response
    {
        $app = App::instance();
        $formId = (string) ($params['id'] ?? '');
        $form = self::findForm($app->config, $formId);

        // Always redirect back to where the form was submitted from
        $back = $this->backUrl($request);

        if ($form === null) {
            return $this->respond($request, $formId, $back, [
                'status' => 'error',
                'code' => 'form_not_found',
            ]);
        }

        // Corpo do POST maior que post_max_size do PHP: o PHP descarta TUDO
        // (inclusive o _csrf) e a requisição chega com $_POST e $_FILES
        // vazios apesar de um Content-Length grande. Sem esta checagem, o
        // CSRF abaixo falharia e o visitante veria "sessão expirada" — uma
        // mentira, já que a sessão está ok, o arquivo é que não coube.
        // Mesmo padrão de Velk\Admin\Controllers\MediaController::postWasDiscarded
        // (replicado aqui, não reaproveitado de lá, porque este controller é
        // público e não depende do namespace do admin). Só dispara neste
        // caso exato (Content-Length > 0 E post/files vazios) — um POST
        // comum, mesmo sem nenhum campo de arquivo no formulário, sempre
        // chega com $_POST não-vazio (ao menos o _csrf), então o CSRF
        // seguinte continua sendo a única defesa em qualquer outro cenário.
        if (self::postWasDiscarded((int) ($_SERVER['CONTENT_LENGTH'] ?? 0), $request->post, $request->files)) {
            return $this->respond($request, $formId, $back, [
                'status' => 'error',
                'code' => 'file_too_large',
            ]);
        }

        // CSRF (theme template MUST include csrf_field()).
        $token = (string) ($request->post['_csrf'] ?? '');
        if (!$app->session->verifyCsrf($token)) {
            return $this->respond($request, $formId, $back, [
                'status' => 'error',
                'code' => 'session_expired',
            ]);
        }

        // ─── Etapas ──────────────────────────────────────────────────────
        //
        // `forms.<id>.steps` parte os campos em etapas; sem a chave, tudo
        // daqui para baixo é o caminho de etapa única de sempre e as duas
        // colunas novas ficam NULL.
        //
        // A resolução vem ANTES do antispam de propósito: é ela que diz se
        // esta requisição CRIA a linha ou mexe numa que já existe, e disso
        // depende o `$isFirstStep` que restringe o honeypot e o Turnstile à
        // criação, logo abaixo. Existindo a linha, ela pode ser um lead real
        // — motivo completo no comentário de cada bloco.
        $totalSteps = count($app->config->formSteps($formId));
        $stepPlan = null;
        $lead = null;

        if ($totalSteps > 0) {
            $stepToken = (string) ($request->post['_step_token'] ?? '');
            $hasToken = $stepToken !== '';
            $lead = $hasToken ? FormSubmission::findOpenByStepToken($formId, $stepToken) : null;

            $stepPlan = FormSubmission::resolveStep(
                $totalSteps,
                isset($request->post['_step']) ? (int) $request->post['_step'] : null,
                $hasToken,
                $lead?->pendingStep,
                $hasToken && self::sessionIssuedToken($app->session, $formId, $stepToken)
            );

            if ($stepPlan['action'] === 'reject') {
                return $this->respond($request, $formId, $back, [
                    'status' => 'error',
                    'code' => $stepPlan['code'],
                ]);
            }

            // Etapa intermediária EXIGE AJAX. Em POST nativo o tema não tem
            // como receber o token da etapa seguinte, então continuar
            // deixaria o visitante preso num formulário que "enviou" e não
            // avança — falha silenciosa, que é o que este engine não faz.
            if (!$stepPlan['completes'] && !$request->wantsJson()) {
                return $this->respond($request, $formId, $back, [
                    'status' => 'error',
                    'code' => 'ajax_required',
                ]);
            }
        }

        // "Primeira etapa" aqui quer dizer A LINHA AINDA NÃO EXISTE, e não "o
        // número da etapa é 1". A diferença aparece na navegação para trás:
        // voltar para corrigir a etapa 1 resolve como `merge` com `step` 1, e
        // ali já existe lead gravado. `create` é o único plano que nasce de
        // "sem token e etapa 1" — a linha nascendo agora.
        //
        // Nada se perde em segurança ao afrouxar o número: para chegar a um
        // `merge` é preciso um token que ESTA sessão emitiu, ou seja, a
        // criação daquele lead já passou pelos dois filtros abaixo.
        $isFirstStep = $stepPlan === null || $stepPlan['action'] === 'create';

        // Honeypot — silently treat as success when the hidden field is filled.
        // The response is a deliberate lie so the bot doesn't learn it was
        // caught; nothing is persisted. Note there is NO conversion event
        // here, and that's structural rather than a check: the enqueue lives
        // after FormSubmission::create() below, which this return never
        // reaches. See docs/superpowers/specs/2026-08-13-conversion-events-design.md
        //
        // Só enquanto a linha não existe (decisão 3 do spec de etapas). O
        // falso positivo do honeypot é real e conhecido — autofill do
        // navegador, gerenciador de senha; é o docblock de recordSpam() que o
        // documenta, e ele não fala de etapas. Sem linha, o falso positivo
        // custa uma submissão. Com a linha já gravada custaria um lead REAL
        // em andamento: a correção que a visitante acabou de digitar seria
        // descartada em silêncio, ela receberia um token descartável, e o
        // lead ficaria encalhado na etapa pendente para sempre — nunca vira
        // e-mail, nunca vira conversão.
        if ($isFirstStep && !empty($request->post['_hp'])) {
            self::recordSpam($formId, $form, $request, 'honeypot');

            // Passa pelo mesmo successPayload() do envio real, e não por um
            // payload montado à mão: assim o `message` do site.json vai junto.
            // Sem ele, bastava comparar as duas respostas para descobrir a
            // armadilha — e um honeypot descoberto não serve para mais nada.
            //
            // O `event` ainda diferencia as duas, como diz o comentário acima,
            // e isso é aceito de propósito: igualar exigiria emitir uma
            // conversão falsa, ou seja, sujar a analytics do cliente para
            // esconder a armadilha de um bot. Preço alto demais pelo silêncio.
            //
            // `$stepPlan`/`$totalSteps` vão junto para o formulário com
            // etapas mentir com a forma de `step_saved`, e não de
            // `submitted`. O token fica de fora justamente aqui: sem lead
            // para continuar, `successPayload()` põe um descartável — ver o
            // docblock dele.
            return $this->respond($request, $formId, $back, self::successPayload($form, $stepPlan, $totalSteps, self::newDiscardableToken()));
        }

        // Turnstile — DEPOIS do honeypot de propósito: o honeypot é grátis e
        // já derrubou o bot ingênuo, enquanto isto custa uma chamada de rede.
        // E antes da validação, que é mais cara que uma comparação de string.
        //
        // Ao contrário do honeypot, a reprovação aqui é ERRO HONESTO. O
        // honeypot mente para o bot não descobrir que foi pego; um captcha
        // reprova humano de verdade às vezes (JS bloqueado, rede ruim), e
        // dizer "enviado!" para quem não enviou perde o lead sem ninguém
        // ficar sabendo.
        //
        // Nenhum evento de conversão é gerado aqui — não por checagem, mas
        // porque o enfileiramento vive depois do FormSubmission::create()
        // lá embaixo, que este return nunca alcança.
        //
        // Mesma guarda do honeypot, e aqui há um motivo a mais: o token do
        // Turnstile vale UMA verificação (a Cloudflare o invalida no primeiro
        // siteverify), então ele não teria como rodar em toda etapa mesmo que
        // se quisesse. Numa volta para a etapa 1 o formulário nem traz campo
        // de captcha para preencher: perguntar ali reprovaria 100% das voltas,
        // para todo visitante.
        if ($isFirstStep && !TurnstileService::verify($request->post[TurnstileService::RESPONSE_FIELD] ?? null, $request->ip())) {
            self::recordSpam($formId, $form, $request, 'turnstile');

            return $this->respond($request, $formId, $back, [
                'status' => 'error',
                'code' => 'captcha_failed',
            ]);
        }

        $fields = (array) ($form['fields'] ?? []);

        // Só os campos DESTA etapa. Um obrigatório de etapa futura não pode
        // reprovar a atual — pedir o CEP da etapa 2 já na etapa 1
        // inviabilizaria o recurso inteiro.
        if ($stepPlan !== null) {
            $stepNames = $app->config->formStepFields($formId, $stepPlan['step']);
            $fields = array_values(array_filter(
                $fields,
                static fn($f) => is_array($f) && in_array((string) ($f['name'] ?? ''), $stepNames, true)
            ));
        }

        [$values, $errors] = $this->validate($fields, $request->post, $request->files);

        if ($errors !== []) {
            // Um campo de arquivo pode ter sido gravado com sucesso mesmo
            // que OUTRO campo do mesmo POST tenha reprovado — a submissão
            // inteira é rejeitada, então esse arquivo já gravado vira órfão.
            $values = self::discardUploads($values);

            return $this->respond($request, $formId, $back, [
                'status' => 'error',
                'code' => 'validation_failed',
                'errors'  => $errors,
                'values'  => $values,
            ]);
        }

        // Tracking params attributed to this visit (UTM + ad click IDs),
        // captured by FrontRouter on arrival — see Velk\Params. Empty when
        // the visitor came in directly.
        $params = Params::fromSession($app->session);


        // Grava primeiro — mesmo que o e-mail falhe, o dado fica.
        //
        // Etapa única (sem `steps`): create() como sempre. Com etapas: cria
        // na primeira, mescla nas demais, fecha na final. `params`, `ip`,
        // `user_agent` e `referer` só na CRIAÇÃO: a atribuição é a do
        // primeiro contato e não é reescrita etapa a etapa.
        $completed = true;

        if ($stepPlan === null) {
            $submission = FormSubmission::create([
                'form_id'      => $formId,
                'data'         => $values,
                'ip'           => $request->ip(),
                'user_agent'   => $request->userAgent(),
                'referer'      => $request->server['HTTP_REFERER'] ?? null,
                'params'       => $params,
            ]);
        } elseif ($stepPlan['action'] === 'create') {
            $newToken = $stepPlan['completes'] ? null : FormSubmission::newStepToken();
            $submission = FormSubmission::create([
                'form_id'      => $formId,
                'data'         => $values,
                'ip'           => $request->ip(),
                'user_agent'   => $request->userAgent(),
                'referer'      => $request->server['HTTP_REFERER'] ?? null,
                'params'       => $params,
                'pending_step' => $stepPlan['next_step'],
                'step_token'   => $newToken,
            ]);
            if ($newToken !== null) {
                self::rememberStepToken($app->session, $formId, $newToken);
            }
            $completed = $stepPlan['completes'];
        } else {
            // `merge` só sai do resolveStep() quando `$lead?->pendingStep`
            // não era null, ou seja, quando o lead existe — mas esse
            // invariante mora no outro arquivo e nada aqui o executa. Uma
            // linha troca um fatal em endpoint público por um erro nomeado.
            $submission = $lead ?? throw new \RuntimeException(
                "Plano de etapa 'merge' sem lead aberto no formulário '{$formId}'."
            );

            if ($stepPlan['completes']) {
                // Rede, não guarda principal: a regra de ordenação do
                // resolveStep() já impede pular etapa. Isto só alcança o caso
                // de alguém reorganizar `steps` ou tornar um campo
                // obrigatório com lead em andamento — sem a rede, "completo"
                // passaria a significar "chegou no fim".
                $missing = FormSubmission::missingRequired(
                    (array) ($form['fields'] ?? []),
                    array_merge($submission->data, $values)
                );
                if ($missing !== []) {
                    // Mesmo motivo do `validation_failed`: o `store()` já
                    // gravou o arquivo desta etapa e este envio não vai virar
                    // lead nenhum. Sem isto, cada tentativa deixa um arquivo
                    // que ninguém referencia — e um obrigatório de etapa
                    // anterior deixado em branco cai aqui TODA vez.
                    self::discardUploads($values);

                    return $this->respond($request, $formId, $back, [
                        'status' => 'error',
                        'code'   => 'incomplete_lead',
                        'errors' => array_fill_keys($missing, ['code' => 'required']),
                    ]);
                }
                if (!$submission->completeWith($values)) {
                    // Zero linhas afetadas = outra requisição fechou este
                    // lead entre a leitura e a gravação (ver o docblock do
                    // completeWith). Este POST não fecha nada, então NADA
                    // depois daqui pode rodar: `step_webhook`, e-mail,
                    // conversão e `webhook` são exatamente os efeitos que
                    // "exatamente uma vez" promete, e o vencedor da corrida
                    // já os disparou.
                    //
                    // O upload deste POST vai junto, pelo mesmo motivo do
                    // `incomplete_lead` logo acima: nenhuma linha guarda esse
                    // `path`, e sem a limpeza ele fica órfão para sempre.
                    self::discardUploads($values);

                    // `form_completed` e não um código novo: é literalmente o
                    // que aconteceu, é o que a visitante receberia se o
                    // segundo POST chegasse um instante depois (o token já
                    // teria morrido), e o tema já sabe tratá-lo.
                    return $this->respond($request, $formId, $back, [
                        'status' => 'error',
                        'code'   => 'form_completed',
                    ]);
                }
            } else {
                $submission->mergeStep($values, $stepPlan['next_step']);
                $completed = false;
            }
        }

        // `step_webhook`: TODA etapa, a primeira inclusive — é o caso de uso
        // principal do recurso. Roda antes da resposta e depois da gravação,
        // como o `webhook`, e só existe para formulário com etapas.
        if ($stepPlan !== null) {
            $this->dispatchStepWebhook(
                $formId,
                $form,
                $submission,
                $values,
                $stepPlan['step'],
                $totalSteps,
                $completed
            );
        }

        if (!$completed) {
            // Etapa intermediária: nada de e-mail, conversão ou `webhook` (o
            // final). O `step_webhook` já disparou, logo acima — TODA etapa,
            // esta inclusive, é o caso de uso dele.
            //
            // A resposta segue pelo MESMO successPayload() do honeypot, e não
            // por um literal igual ao lado: duas montagens da mesma forma é
            // precisamente o que aquele método existe para impedir. O
            // `step_webhook` não teria como vazar essa diferença mesmo se
            // quisesse — o honeypot retorna bem acima, antes de
            // `FormSubmission::create()`, e nunca alcança este ponto.
            //
            // O token vai explícito porque este caminho tem lead: é o do
            // lead. O descartável só existe para quem não tem nenhum.
            return $this->respond(
                $request,
                $formId,
                $back,
                self::successPayload($form, $stepPlan, $totalSteps, $submission->stepToken)
            );
        }

        // Conversion event — built HERE, right after the submission is
        // persisted and BEFORE email and webhook. Two reasons, and the
        // second is the one that matters:
        //
        // 1. This is the instant "a submission was recorded" becomes true.
        //    Email and webhook are downstream effects that must not get a
        //    vote on whether the conversion fires — a lead is a lead even
        //    if the notification bounces or the CRM is down.
        // 2. The honeypot branch above returns ~50 lines before this point,
        //    so it can never produce an event. That's not a check that
        //    could be forgotten; it's the shape of the code.

        // O lead INTEIRO, não só a etapa que acabou de chegar. Num formulário sem
        // `steps` é o mesmo array de sempre — daí a condição, que mantém aquele
        // caminho byte a byte idêntico. Com etapas, `$values` traz só os campos da
        // última etapa, e mandar isso para o e-mail faria o dono do site receber um
        // aviso com um campo só; para o `user_data` da conversão seria pior, porque
        // o e-mail e o telefone quase sempre estão na etapa 1 e o enhanced
        // conversions do Google pararia de casar, sem sintoma nenhum.
        //
        // O webhook segue recebendo `$values`: ali "os campos que chegaram agora" é
        // o contrato declarado, e o lead acumulado chega pelo `$context`.
        $leadValues = $stepPlan === null ? $values : $submission->data;

        $settings = FormSettings::forForm($formId);
        $event = [
            'name' => 'velk_form_success',
            'data' => [
                'form_id'       => $formId,
                'submission_id' => $submission->id,
                // (object) para os dois: são mapas por contrato, e sem o
                // cast um vazio sairia como `[]` em vez de `{}`. O tipo não
                // pode mudar entre uma conversão com campanha e uma direta —
                // é justamente a estabilidade que permite montar um container
                // GTM uma vez e reusar em todo site.
                'params'        => (object) $params,
                'user_data'     => (object) ClientEvents::buildUserData($settings->userDataFields, $leadValues),
            ],
            'snippet' => $settings->conversionSnippet,
        ];

        // Email the recipients configured in the admin panel.
        $this->dispatchEmail($formId, $form, $submission, $leadValues);

        // Dispatch user-defined webhook(s) declared in site.json. Runs
        // AFTER email so a slow external HTTP call can't delay the
        // notification to recipients. Failures here are logged but
        // never break the user-facing submit flow — by this point
        // the submission is persisted and email already dispatched,
        // and the user has done nothing wrong.
        //
        // `$stepPlan['step'] ?? 1` e `max(1, $totalSteps)` são o valor de
        // sempre (1/1) quando o formulário não usa `steps`.
        $this->dispatchWebhook(
            $formId,
            $form,
            $submission,
            $values,
            $stepPlan['step'] ?? 1,
            max(1, $totalSteps)
        );

        // `success_message` é o ÚNICO texto que sobrevive no payload, e por
        // um motivo: ele vem do site.json, ou seja, foi escrito pelo tema.
        // Não é o engine tendo opinião sobre a redação — é o tema mandando a
        // dele. Segue passando por t() para que um site multi-idioma consiga
        // traduzi-lo pelo painel, como qualquer outra string de tema.
        //
        // Ausente, não há fallback em texto: o tema recebe só o código e
        // decide. Um fallback aqui seria o engine escolhendo palavras de
        // novo, na única porta que ainda estava aberta.
        $payload = self::successPayload($form);
        $payload['event'] = $event;

        return $this->respond($request, $formId, $back, $payload);
    }

    /**
     * Single exit point for every branch of submit().
     *
     * Two shapes, same payload: a theme that asks for JSON
     * (`Accept: application/json`) gets the body directly and renders the
     * outcome itself; everything else keeps the flash-and-redirect flow the
     * theme reads back through `form_status($id)`.
     *
     * Purely additive — a plain `<form>` POST sends no such Accept header,
     * so every existing theme behaves exactly as before, byte for byte.
     *
     * Why this matters beyond convenience: without a JSON path, a theme that
     * reached for `fetch()` got a 302 that fetch silently FOLLOWS, and the
     * fetched page render consumed the flash (`form_status()` does get +
     * forget). The message evaporated with no error anywhere — and the same
     * would happen to the conversion event. See the spec.
     */
    /**
     * Grava uma submissão reprovada pelo antispam — se o formulário pediu.
     *
     * Existe porque falso positivo é real nos DOIS canais. O Turnstile
     * reprova humano com JS bloqueado ou rede ruim (está dito no comentário
     * dele, algumas linhas acima). O honeypot é preenchido por gerenciador
     * de senha e autofill do navegador. Antes disto, os dois casos perdiam o
     * lead em silêncio absoluto: nem o visitante sabia, nem o dono do site.
     *
     * ─── O que NÃO acontece aqui ─────────────────────────────────────────
     *
     * Nem email, nem evento de conversão, nem webhook. Isso não é uma
     * checagem que alguém possa esquecer de fazer: os três vivem depois do
     * `FormSubmission::create()` do fluxo normal, lá embaixo, e as duas
     * chamadas a este método retornam antes de chegar lá. É a forma do
     * código, como já era quando spam era simplesmente descartado.
     *
     * ─── Arquivo de bot nunca é gravado ──────────────────────────────────
     *
     * Campos `file` são pulados de propósito. Guardar upload de quem o
     * antispam reprovou é aceitar que um bot escreva no disco do servidor —
     * exatamente o que ele queria. O nome do arquivo entra como texto, para
     * o falso positivo não virar um registro sem sentido, mas o conteúdo
     * não sai do /tmp do PHP.
     *
     * ─── Silêncio do honeypot preservado ─────────────────────────────────
     *
     * Gravar é server-side e não muda um byte da resposta. O bot continua
     * vendo o mesmo sucesso falso de sempre.
     */
    private static function recordSpam(
        string $formId,
        array $form,
        Request $request,
        string $blockedBy
    ): void {
        $app = App::instance();
        if (!$app->config->keepsSpamFor($formId)) {
            return;
        }

        // A declaração INTEIRA, e não só a etapa que o visitante viu. Um
        // falso positivo do honeypot é um lead de verdade, e o tema decide o
        // que vai no POST: recortar pela etapa jogaria fora campo que a
        // visitante preencheu, nesta linha que é o único registro que sobra
        // dela. `spamValues()` grava string vazia para o que não veio, e é
        // isso que o painel já sabe mostrar.
        $fields = (array) ($form['fields'] ?? []);

        try {
            FormSubmission::create([
                'form_id'    => $formId,
                'blocked_by' => $blockedBy,
                'data'       => self::spamValues($fields, $request),
                'ip'         => $request->ip(),
                'user_agent' => $request->userAgent(),
                'referer'    => $request->server['HTTP_REFERER'] ?? null,
                // Atribuição vai junto: se for falso positivo, saber de qual
                // campanha veio o lead é metade do valor de tê-lo recuperado.
                'params'     => Params::fromSession($app->session),
            ]);
        } catch (\Throwable $e) {
            // Registrar spam NUNCA pode quebrar a resposta. Se quebrasse, um
            // bot derrubaria o formulário para todo mundo mandando payload
            // que estoura a gravação — e no caso do honeypot o erro ainda
            // denunciaria a armadilha, já que a resposta deixaria de ser
            // idêntica à de sucesso.
            error_log('[Velk] spam record failed (' . $formId . '/' . $blockedBy . '): ' . $e->getMessage());
        }
    }


    /**
     * Os valores como o visitante mandou, sem validar.
     *
     * Sem `validate()` de propósito: ela rejeita, normaliza e — o que importa
     * aqui — processa upload. Para um registro de spam o que interessa é o
     * que CHEGOU, inclusive malformado; um lead recuperado de falso positivo
     * precisa mostrar o email torto que a pessoa digitou, não um campo vazio
     * porque a validação o descartou.
     *
     * Só campos declarados: `_csrf`, `_hp` e o token do Turnstile ficam de
     * fora porque não são o lead, são mecanismo.
     *
     * @param list<array<string,mixed>> $fields
     * @return array<string,string>
     */
    private static function spamValues(array $fields, Request $request): array
    {
        $values = [];
        foreach ($fields as $field) {
            $name = (string) ($field['name'] ?? '');
            if ($name === '') {
                continue;
            }

            if (($field['type'] ?? '') === 'file') {
                // Só o nome declarado pelo cliente, e ele é dado hostil —
                // vem do header do upload. Truncado e sem tocar no arquivo.
                $original = $request->files[$name]['name'] ?? null;
                if (is_string($original) && $original !== '') {
                    $values[$name] = mb_substr($original, 0, 255);
                }
                continue;
            }

            $raw = $request->post[$name] ?? null;
            if (is_array($raw)) {
                // Checkbox múltiplo / select multiple: achata, porque a
                // coluna `data` de spam não precisa preservar estrutura.
                $raw = implode(', ', array_map(fn($v) => is_scalar($v) ? (string) $v : '', $raw));
            }
            if (!is_scalar($raw) && $raw !== null) {
                continue;
            }

            // Teto por campo: sem ele, um bot grava megabytes por submissão
            // e transforma a tabela de leads em depósito de lixo.
            $values[$name] = mb_substr((string) $raw, 0, self::SPAM_VALUE_MAX_LENGTH);
        }
        return $values;
    }

    /**
     * O payload de sucesso, usado pelo caminho real E pelo honeypot.
     *
     * Um método só, e não duas montagens parecidas, porque as duas respostas
     * precisam ser byte a byte iguais: qualquer diferença entre "enviado" e
     * "enviado (mentira)" entrega o honeypot para quem comparar.
     *
     * `message` é o único texto que sobra no payload — vem do site.json, ou
     * seja, foi escrito pelo tema. Ausente, o tema recebe só o código: um
     * fallback aqui seria o engine escolhendo palavras de novo.
     *
     * `$stepPlan`/`$totalSteps` existem porque num formulário com etapas a
     * resposta verdadeira de uma etapa intermediária NÃO é `submitted` — é
     * `step_saved`, com token e próxima etapa. Um `submitted` sem token
     * entregaria a armadilha de graça.
     *
     * `$token` é o que separa as duas chamadas, e é a única coisa que separa:
     * o caminho real manda o token do lead, o honeypot omite e recebe um
     * descartável. Tudo o mais é montado uma vez só, aqui — inclusive as
     * chaves que ainda vão nascer (o `step_webhook` da Task 8), que de outro
     * jeito apareceriam só numa das duas respostas e denunciariam qual é qual.
     *
     * O token do sucesso falso é descartável: gerado, nunca gravado, e a
     * etapa 2 do bot recebe `invalid_token`. Igualar também aquela resposta
     * exigiria fazer token desconhecido suceder falsamente, e aí um visitante
     * real com token vencido perderia os dados em silêncio.
     */
    private static function successPayload(
        array $form,
        ?array $stepPlan = null,
        int $totalSteps = 0,
        ?string $token = null
    ): array {
        if ($stepPlan !== null && !$stepPlan['completes']) {
            return [
                'status'      => 'success',
                'code'        => 'step_saved',
                'step'        => $stepPlan['step'],
                'next_step'   => $stepPlan['next_step'],
                'total_steps' => $totalSteps,
                // Quem chama diz qual token vai: o real manda o do lead, o
                // honeypot manda um descartável. Sem `??` de propósito — um
                // null vindo do caminho real é bug, e `token: null` grita,
                // enquanto um descartável fabricado aqui devolveria à
                // visitante um token que a etapa seguinte recusa, que é
                // exatamente o lead encalhado que esta task foi corrigir.
                'token'       => $token,
            ];
        }

        $payload = ['status' => 'success', 'code' => 'submitted'];
        $message = $form['success_message'] ?? null;
        if (is_string($message) && $message !== '') {
            $payload['message'] = t($message);
        }
        return $payload;
    }

    /**
     * Um token com a forma certa e que não resolve para nada.
     *
     * Existe só para o sucesso falso do honeypot ter a mesma forma da
     * resposta verdadeira. Método próprio, e não uma chamada direta a
     * `newStepToken()`, para o nome dizer no ponto de uso que este token é
     * lixo de propósito.
     */
    private static function newDiscardableToken(): string
    {
        return FormSubmission::newStepToken();
    }

    private function respond(Request $request, string $formId, string $back, array $payload): Response
    {
        if ($request->wantsJson()) {
            // O evento vai no CORPO e NÃO é enfileirado — o tema empurra ele
            // mesmo. Enfileirar aqui também dispararia duas vezes; só
            // enfileirar dispararia tarde ou nunca, já que num tema AJAX a
            // página não re-renderiza.
            //
            // A exceção é o tema PEDIR o contrário com `_event=queue`, porque
            // ele vai navegar para uma página de obrigado e é lá que a
            // conversão deve disparar. Ou um, ou outro — nunca os dois.
            $event = $payload['event'] ?? null;
            if (is_array($event) && ClientEvents::requestedQueue($request)) {
                unset($payload['event']);
                ClientEvents::queue(
                    App::instance()->session,
                    (string) ($event['name'] ?? ''),
                    (array) ($event['data'] ?? []),
                    $event['snippet'] ?? null
                );
            }

            // 422 para falha de verdade, para o tema ramificar só pelo status;
            // o sucesso falso do honeypot é 200 de propósito.
            return Response::json($payload, ($payload['status'] ?? '') === 'error' ? 422 : 200);
        }

        // Redirect mode: there is no body to carry the event, so it goes to
        // the queue for the next render. Stripped from the flash so
        // form_status() keeps handing themes the shape it always has.
        $event = $payload['event'] ?? null;
        unset($payload['event']);

        $session = App::instance()->session;
        if (is_array($event)) {
            ClientEvents::queue(
                $session,
                (string) ($event['name'] ?? ''),
                (array) ($event['data'] ?? []),
                $event['snippet'] ?? null
            );
        }

        $session->put('_form_status_' . $formId, $payload);
        return Response::redirect($back);
    }

    /** @param array<string, array> $files $_FILES-shaped, keyed by field name (Request::$files) */
    private function validate(array $fields, array $post, array $files = []): array
    {
        $values = [];
        $errors = [];

        foreach ($fields as $field) {
            $name = (string) ($field['name'] ?? '');
            if ($name === '') continue;

            $type = (string) ($field['type'] ?? 'text');
            $required = !empty($field['required']);

            // Arquivo: vem de $_FILES, não de $post. Em sucesso o valor
            // gravado é o array de metadados do FormUploadService (marcador
            // `_file`), não uma string — por isso sai cedo (continue) antes
            // das checagens de string (maxlength/email/url/...) abaixo, que
            // não fazem sentido pra esse tipo.
            if ($type === 'file') {
                $uploaded = $files[$name] ?? null;
                $hasUpload = is_array($uploaded)
                    && (int) ($uploaded['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

                if (!$hasUpload) {
                    if ($required) {
                        $errors[$name] = ['code' => 'required'];
                    }
                    continue;
                }

                try {
                    $values[$name] = FormUploadService::store($uploaded, $field);
                } catch (\Velk\Services\FormUploadException $e) {
                    // O código vem da exceção, não a mensagem: o serviço sabe
                    // O QUE deu errado, o tema decide como dizer. Os `params`
                    // levam o que só o serviço conhece — o limite em MB, a
                    // lista aceita — sem os quais o tema não consegue ser
                    // específico sem recodificar as regras do engine.
                    $errors[$name] = ['code' => $e->errorCode];
                    if ($e->params !== []) {
                        $errors[$name]['params'] = $e->params;
                    }
                } catch (\RuntimeException $e) {
                    // Rede de segurança para qualquer RuntimeException que não
                    // tenha sido convertida — nunca vaza texto interno.
                    $errors[$name] = ['code' => 'upload_failed'];
                }
                continue;
            }

            $maxlength = (int) ($field['maxlength'] ?? ($type === 'textarea' ? 5000 : 1000));

            $raw = $post[$name] ?? null;
            $value = is_string($raw) ? trim($raw) : '';

            if ($required && $value === '') {
                $errors[$name] = ['code' => 'required'];
                $values[$name] = $value;
                continue;
            }

            if ($value !== '' && mb_strlen($value) > $maxlength) {
                $errors[$name] = ['code' => 'max_length', 'params' => ['max' => $maxlength]];
                $values[$name] = mb_substr($value, 0, $maxlength);
                continue;
            }

            if ($type === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[$name] = ['code' => 'invalid_email'];
            }

            if ($type === 'url' && $value !== '' && !filter_var($value, FILTER_VALIDATE_URL)) {
                $errors[$name] = ['code' => 'invalid_url'];
            }

            if ($type === 'number' && $value !== '' && !is_numeric($value)) {
                $errors[$name] = ['code' => 'invalid_number'];
            }

            // M5: a select only ever offers its declared `options` ({value:
            // label}, same convention as content select fields) — a
            // submitted value outside that set didn't come from the
            // rendered <select> (tampered client, or a stale/renamed
            // option) and must not be stored/emailed as if it had. Skipped
            // when the field declares no options at all — nothing to
            // validate against, so don't break an existing form over it.
            if ($type === 'select' && $value !== '') {
                $options = (array) ($field['options'] ?? []);
                if ($options !== [] && !array_key_exists($value, $options)) {
                    $errors[$name] = ['code' => 'invalid_option'];
                }
            }

            if ($type === 'checkbox') {
                $value = !empty($raw) && $raw !== '0' ? '1' : '';
                if ($required && $value !== '1') {
                    $errors[$name] = ['code' => 'required'];
                }
            }

            $values[$name] = $value;
        }

        return [$values, $errors];
    }

    /**
     * Apaga os arquivos que o `validate()` já gravou e devolve `$values` sem
     * o metadado deles.
     *
     * Existe porque `FormUploadService::store()` roda DENTRO do `validate()`,
     * ou seja, o arquivo já foi para o armazenamento definitivo antes das
     * checagens que ainda podem recusar o envio. Quando uma delas recusa —
     * `validation_failed` (outro campo do mesmo POST reprovou) ou
     * `incomplete_lead` (falta um obrigatório de etapa anterior) — nenhuma
     * linha guarda esse `path`, e sem esta limpeza cada tentativa deixa um
     * arquivo que ninguém referencia e ninguém apaga.
     *
     * O delete é não-fatal, e tirar o metadado do array importa para quem
     * re-exibe os valores ao tema: depois do delete, `path` não aponta mais
     * para nada.
     *
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private static function discardUploads(array $values): array
    {
        foreach ($values as $name => $value) {
            if (is_array($value) && !empty($value['_file'])) {
                FormUploadService::delete((string) ($value['path'] ?? ''));
                unset($values[$name]);
            }
        }

        return $values;
    }

    private function dispatchEmail(string $formId, array $form, FormSubmission $submission, array $values): void
    {
        $recipients = FormRecipient::forForm($formId);
        if ($recipients === []) {
            $submission->markEmail('skipped', 'no recipients configured');
            return;
        }

        $siteName = (string) (App::instance()->config->site('site.name') ?? 'Velk');
        $subjectTpl = (string) ($form['subject'] ?? sprintf('[%s] Novo envio em %s', $siteName, (string) ($form['label'] ?? $formId)));
        $subject = self::interpolate($subjectTpl, $values);

        $emailView = new View(App::instance()->config->path('core') . '/Admin/views');
        $html = $emailView->render('emails/form-submission', [
            'form'       => $form,
            'formId'     => $formId,
            'siteName'   => $siteName,
            'submission' => $submission,
            'values'     => $values,
            'fields'     => (array) ($form['fields'] ?? []),
        ]);

        $mailer = Mailer::fromEnv();
        $sentTo = [];
        $errors = [];

        foreach ($recipients as $r) {
            try {
                $mailer->send($r->email, $subject, $html);
                $sentTo[] = $r->email;
            } catch (\Throwable $e) {
                $errors[] = $r->email . ': ' . $e->getMessage();
            }
        }

        if ($sentTo === []) {
            $submission->markEmail('failed', implode(' | ', $errors), null);
            error_log('[Velk] Form email failed: ' . implode(' | ', $errors));
        } else {
            $submission->markEmail(
                $errors === [] ? 'sent' : 'partial',
                $errors === [] ? null : implode(' | ', $errors),
                implode(', ', $sentTo)
            );
        }
    }

    /**
     * Invokes user-defined webhook function(s) for a form. The function name(s)
     * come from `site.json → forms.<id>.webhook`, which accepts either:
     *
     *   "webhook": "send_to_crm"                    (single handler)
     *   "webhook": ["send_to_crm", "notify_slack"]  (multiple handlers)
     *
     * The handlers themselves must be defined in `theme/functions.php`,
     * which App::register() loads at boot. Expected signature:
     *
     *   function name(string $formId, array $values, array $context): void
     *
     * $values has the validated form fields (same array used for DB + email).
     * $context bundles the submission metadata so handlers can attribute
     * the lead, log the IP, or attach UTM/click ID data without depending
     * on the FormSubmission class directly.
     *
     * Errors are caught and logged via error_log — the submit flow never
     * fails because a webhook misbehaved. Tradeoff: silent failures need
     * to be discovered via server logs. Adding a webhook_status column to
     * form_submissions would make this visible in the admin; intentionally
     * left for a follow-up.
     *
     * v3.8.0: `$step`/`$totalSteps` (default 1/1, o caso sem etapas) fazem o
     * `$context` trazer também `step`/`total_steps`/`completed`/`data` — ver
     * o docblock de webhookContext(), a montagem compartilhada com o
     * `step_webhook` de dispatchStepWebhook() logo abaixo.
     */
    private function dispatchWebhook(
        string $formId,
        array $form,
        FormSubmission $submission,
        array $values,
        int $step = 1,
        int $totalSteps = 1
    ): void {
        $webhook = $form['webhook'] ?? null;
        if ($webhook === null || $webhook === '' || $webhook === []) {
            return;
        }

        // Snapshot dos metadados. Array puro (não o model) para o handler
        // poder serializar e encaminhar à vontade — útil ao mandar para API
        // externa, que não conhece os tipos do Velk. Ver webhookContext().
        //
        // `true` fixo, não uma variável: este método só é alcançado com
        // `$completed === true` (o `if (!$completed)` de submit() retorna
        // antes de chegar aqui).
        $context = self::webhookContext($submission, $step, $totalSteps, true);

        // Normalização, function_exists, invoke + try/catch por handler
        // agora vivem em Velk\Services\WebhookDispatcher (mesmo dispatch
        // usado pelo webhook global de pagamento). O label vira o prefixo
        // das linhas de log: "[Velk webhook form=<id>] fn=<name>: ..."
        // (antes era "[Velk webhook] form=<id> fn=<name>: ..." — mesma
        // informação, na mesma ordem, só o fechamento do colchete se
        // deslocou pra depois de "form=<id>", já que o dispatcher é
        // genérico e sempre fecha o colchete logo antes de "fn=" — ver
        // task-1-report.md).
        WebhookDispatcher::dispatch($webhook, "Velk webhook form={$formId}", [$formId, $values, $context]);
    }

    /**
     * O `$context` dos webhooks — um lugar só, para as duas chaves
     * (`webhook` e `step_webhook`) nunca divergirem.
     *
     * As quatro últimas são acréscimo puro: um handler que já existe não as
     * lê e não percebe. Num formulário sem etapas elas valem `step: 1`,
     * `total_steps: 1`, `completed: true` e `data` igual ao `$values`.
     *
     * `data` é o lead ACUMULADO, e existe porque o `$values` de um
     * `step_webhook` da etapa 2 traz só os campos daquela etapa — o handler
     * que sincroniza o CRM precisa do e-mail da etapa 1 para saber QUAL
     * contato atualizar. Sem esta chave, todo handler iria ao banco buscar o
     * que o engine já tinha na mão.
     *
     * `completed` não é derivável de `step === total_steps`. O caso claro é o
     * dono do site encurtar o `steps` com lead em andamento: um lead pendente
     * na etapa 3 de 3, o `site.json` passa a declarar 2 etapas, a visitante
     * reenvia a 2 — chega `step: 2`, `total_steps: 2`, e ainda assim nada
     * completou, porque o ponteiro dela continua na 3. Quem sabe se fechou é
     * o `resolveStep()`, e é de lá que este valor vem.
     */
    private static function webhookContext(
        FormSubmission $submission,
        int $step,
        int $totalSteps,
        bool $completed
    ): array {
        return [
            'submission_id' => $submission->id,
            'ip'            => $submission->ip,
            'user_agent'    => $submission->userAgent,
            'referer'       => $submission->referer,
            'params'        => $submission->params,
            'created_at'    => $submission->createdAt,
            'step'          => $step,
            'total_steps'   => $totalSteps,
            'completed'     => $completed,
            'data'          => $submission->data,
        ];
    }

    /**
     * `forms.<id>.step_webhook` — a cada etapa gravada, a primeira inclusive.
     *
     * Chave própria e não a mesma do `webhook` porque os dois momentos pedem
     * handlers diferentes na prática: sincronizar contato parcial no CRM (o
     * caso de uso principal — jogar o lead lá antes de a pessoa sumir) não é
     * a mesma coisa que avisar o time que chegou lead completo. Uma chave só
     * obrigaria todo handler de todo tema a começar com um `if` sobre o
     * `$context`.
     *
     * Mesma assinatura, mesmo dispatcher e mesmo isolamento do `webhook`:
     * função ausente ou que lança é capturada e logada, nunca quebra o envio.
     *
     * O que NÃO é igual, e vale saber antes de pendurar coisa lenta aqui: o
     * `webhook` roda depois do `dispatchEmail()` justamente para uma chamada
     * externa lenta não atrasar o aviso a quem recebe o lead. Este roda
     * ANTES, porque na etapa intermediária não há e-mail nenhum e a resposta
     * da visitante é que está esperando. Na etapa final os dois disparam, e aí
     * um handler bloqueante aqui atrasa o e-mail — que é exatamente o que o
     * outro ordenamento evita.
     *
     * Na etapa final os dois disparam com `$context` e `$values` IDÊNTICOS.
     * Quem pendurar a mesma função nas duas chaves recebe duas vezes; um
     * handler que só quer as intermediárias olha o `$context['completed']`.
     */
    private function dispatchStepWebhook(
        string $formId,
        array $form,
        FormSubmission $submission,
        array $values,
        int $step,
        int $totalSteps,
        bool $completed
    ): void {
        $webhook = $form['step_webhook'] ?? null;
        if ($webhook === null || $webhook === '' || $webhook === []) {
            return;
        }

        WebhookDispatcher::dispatch(
            $webhook,
            "Velk step webhook form={$formId}",
            [$formId, $values, self::webhookContext($submission, $step, $totalSteps, $completed)]
        );
    }

    private function backUrl(Request $request): string
    {
        // Só volta para o Referer se ele for deste host — ver
        // Request::refererSameHost() para o porquê.
        return $request->refererSameHost() ?? base_path() . '/';
    }

    private static function findForm(Config $config, string $id): ?array
    {
        $forms = (array) $config->site('forms', []);
        return $forms[$id] ?? null;
    }

    /**
     * When the POST exceeds post_max_size, PHP discards the ENTIRE body
     * (file + _csrf) — the request arrives with empty POST/FILES despite a
     * large Content-Length, which would otherwise look like a CSRF mismatch.
     * Same pure/testable shape as
     * Velk\Admin\Controllers\MediaController::postWasDiscarded.
     */
    public static function postWasDiscarded(int $contentLength, array $post, array $files): bool
    {
        return $contentLength > 0 && $post === [] && $files === [];
    }

    /** Chave da sessão com os tokens de etapa emitidos, por formulário. */
    private const STEP_TOKENS_KEY = '_form_step_tokens';

    /**
     * Teto por formulário. A lista existe para o MULTI-ABA — cada aba tem o
     * seu token —, não para histórico; sem teto, quem preenche muitos
     * formulários engorda a sessão sem limite.
     *
     * Estourar o teto descarta o token MAIS ANTIGO, e isso tem preço desde
     * que a checagem de sessão passou a valer em TODOS os ramos do
     * `resolveStep()`: o lead daquela aba fica inalcançável — o próximo envio
     * dela leva `invalid_token` com o lead ainda aberto no banco. Custo
     * aceito de olho aberto, porque exige 11 abas do MESMO formulário na
     * MESMA sessão e a alternativa (lista sem teto) é pior.
     */
    private const STEP_TOKENS_MAX = 10;

    /** Registra na sessão que fomos nós que emitimos este token. */
    private static function rememberStepToken(Session $session, string $formId, string $token): void
    {
        $all = $session->get(self::STEP_TOKENS_KEY);
        $all = is_array($all) ? $all : [];
        $list = isset($all[$formId]) && is_array($all[$formId]) ? array_values($all[$formId]) : [];
        $list[] = $token;
        if (count($list) > self::STEP_TOKENS_MAX) {
            $list = array_slice($list, -self::STEP_TOKENS_MAX);
        }
        $all[$formId] = $list;
        $session->put(self::STEP_TOKENS_KEY, $all);
    }

    /**
     * Este token foi emitido por ESTA sessão?
     *
     * Duas coisas dependem desta lista, e a primeira é uma tranca:
     *
     *   1. Token que não está aqui não abre lead NENHUM — o `resolveStep()`
     *      recusa com `invalid_token` antes de olhar o lead. O token diz qual
     *      lead é; esta lista é o que prova ser a mesma visitante. Sem ela,
     *      um token vazado (tema que o colocasse na URL e ele escapasse por
     *      Referer) continuava o lead alheio até o fim.
     *   2. É o que distingue `form_completed` de `invalid_token`: o token
     *      morre no envio final, então um lead concluído não tem token para
     *      achar. Sem a lista, quem reenviasse depois de concluir receberia
     *      "token inválido" — mentira sobre o que aconteceu.
     *
     * O (2) tem uma exceção conhecida, e é o teto de `STEP_TOKENS_MAX`:
     * passado ele o token mais antigo sai da lista, e aí o `invalid_token`
     * volta a ser mentira — desta vez sobre um lead que continua aberto. Ver
     * o docblock da constante.
     */
    private static function sessionIssuedToken(Session $session, string $formId, string $token): bool
    {
        $all = $session->get(self::STEP_TOKENS_KEY);
        if (!is_array($all) || !isset($all[$formId]) || !is_array($all[$formId])) {
            return false;
        }
        return in_array($token, $all[$formId], true);
    }

    private static function interpolate(string $template, array $values): string
    {
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function ($m) use ($values) {
            $key = $m[1];
            return isset($values[$key]) ? (string) $values[$key] : $m[0];
        }, $template) ?? $template;
    }
}
