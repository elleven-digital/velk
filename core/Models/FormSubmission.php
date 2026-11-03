<?php

declare(strict_types=1);

namespace Velk\Models;

use Velk\App;
use Velk\Services\FormUploadService;

final class FormSubmission
{
    public int $id;
    public string $formId;
    public array $data = [];
    public ?string $ip = null;
    public ?string $userAgent = null;
    public ?string $referer = null;
    public ?string $emailStatus = null;   // 'sent' | 'failed' | null
    public ?string $emailError = null;
    public ?string $sentTo = null;
    /**
     * Qual antispam reprovou: 'honeypot', 'turnstile', ou null (aprovada).
     *
     * Só existe linha bloqueada quando o formulário declara `keep_spam` no
     * site.json. Sem a flag, a submissão é descartada como sempre foi — a
     * coluna fica NULL para todo mundo e nada muda.
     */
    public ?string $blockedBy = null;
    /**
     * A etapa que falta preencher. **`NULL` = completo** — e é essa
     * inversão que faz a coluna nascer correta em toda linha que já existe.
     *
     * É a AUTORIDADE sobre o lead estar aberto: todo caminho que anexa dado
     * a um lead confere isto, não o token.
     */
    public ?int $pendingStep = null;
    /**
     * A credencial que o cliente devolve para dizer QUAL lead é o dele.
     * `NULL` quando o lead fecha — o token morre junto, então um token
     * vazado de lead antigo não tem nem como ser procurado.
     */
    public ?string $stepToken = null;
    // UTM attribution captured from session at submission time.
    /**
     * Parâmetros de rastreamento atribuídos à visita (UTM + click IDs),
     * governados pela whitelist de Velk\Params. Só as chaves realmente
     * capturadas — array vazio quando a visita foi direta.
     *
     * @var array<string, string>
     */
    public array $params = [];
    
    // Only whitelist keys with a value; empty when the visitor didn't
    // arrive through a tracked ad click.
    public string $createdAt;

    /** Máximo de caracteres do rótulo na lista de preenchimentos. */
    public const PREVIEW_MAX_LENGTH = 60;

    /**
     * Rótulo de UMA submissão na lista do admin — o texto do cartão fechado.
     *
     * `$preferredField` vem de `forms.<id>.preview_field` no site.json. Sem
     * ele, cai na regra histórica: o primeiro valor de texto não-vazio. Essa
     * regra funciona por acidente de ordenação — os dados são gravados na
     * ordem de declaração dos campos, então "o primeiro texto preenchido"
     * costuma ser o nome. Quem monta o formulário não tem como adivinhar
     * isso, e reordenar campos por motivo de layout mudava o rótulo da lista
     * sem aviso; daí a chave explícita.
     *
     * O campo nomeado ainda cai no fallback quando está vazio (campo
     * opcional que ninguém preencheu) ou quando não é texto (arquivo guarda
     * array de metadados). Preferir um rótulo pior a nenhum: um cartão
     * vazio na lista não ajuda ninguém. Nome errado é problema de
     * configuração, e o `schema:validate` acusa.
     *
     * Pura e estática, como parseBulkIds: nunca lança,
     * devolve '' quando não há nada aproveitável — o chamador decide o
     * fallback final (hoje `#<id>`).
     *
     * @param array<string,mixed> $data
     */
    public static function previewFrom(array $data, ?string $preferredField = null): string
    {
        $chosen = self::subtitleFrom($data, $preferredField);
        if ($chosen !== '') {
            return $chosen;
        }

        foreach ($data as $value) {
            // Campo de arquivo guarda array de metadados (marcador `_file`),
            // não string — is_string() já o descarta, e o preview segue pro
            // próximo campo de texto.
            if (is_string($value) && trim($value) !== '') {
                return mb_substr(trim($value), 0, self::PREVIEW_MAX_LENGTH);
            }
        }

        return '';
    }

    /**
     * O texto de UM campo nomeado, aparado e cortado como o rótulo — ou ''
     * quando não há campo, ele está vazio ou não é texto (arquivo guarda
     * array). É o subtítulo do cartão fechado (`forms.<id>.preview_subtitle_field`),
     * na linha da data, e o primeiro passo do `previewFrom()`.
     *
     * Sem fallback, ao contrário do rótulo: "o primeiro texto preenchido"
     * seria o próprio título repetido embaixo dele. Sem valor, a linha fica
     * só com a data.
     *
     * @param array<string,mixed> $data
     */
    public static function subtitleFrom(array $data, ?string $field): string
    {
        if ($field === null || $field === '') {
            return '';
        }
        $value = $data[$field] ?? null;
        return is_string($value) && trim($value) !== ''
            ? mb_substr(trim($value), 0, self::PREVIEW_MAX_LENGTH)
            : '';
    }

    /**
     * PURO — o token opaco de uma etapa: 32 bytes aleatórios em hex.
     *
     * 64 caracteres, que é exatamente a largura da coluna. Inadivinhável de
     * propósito: da etapa 2 em diante é ele que diz QUAL lead continuar — e a
     * sessão que o emitiu é que prova ser a mesma visitante (ver
     * `resolveStep()`). O honeypot e o Turnstile só rodam enquanto a linha
     * não existe — na requisição que a CRIA, e não em toda requisição de
     * etapa 1, porque voltar para corrigir a etapa 1 mexe num lead que já
     * está gravado. O sucesso falso do honeypot usa este MESMO gerador para o
     * token descartável que preenche a forma de `step_saved` — nunca gravado,
     * e por isso incapaz de abrir lead nenhum na etapa seguinte.
     */
    public static function newStepToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * PURO — decide o que fazer com (`_step`, `_step_token`) dado o estado do
     * lead. É a ÚNICA definição dessa regra; o controller só executa.
     *
     * Devolve sempre `action`. Com `action === 'reject'` vem `code`; nos
     * outros casos vêm `step` (1-based), `completes` (esta gravação fecha o
     * lead?) e `next_step` (para onde mandar o visitante, `null` no fim).
     *
     * `action` é 'create' (não há lead: nasce) ou 'merge' (existe: mescla).
     * Ortogonal a `completes`, porque um formulário de UMA etapa cria e fecha
     * na mesma requisição.
     *
     * Três regras carregam segurança:
     *
     *   - token que ESTA sessão não emitiu (`$tokenWasOurs === false`) é
     *     recusado antes de qualquer ramo que dependa do lead. O token diz
     *     QUAL lead é; a sessão é que prova ser a mesma visitante. Sem essa
     *     regra, quem pegasse um token vazado continuava o lead alheio até o
     *     fim: o e-mail e a conversão do dono do site sairiam com o conteúdo
     *     injetado, e a visitante de verdade, ao voltar, levaria
     *     `invalid_token` porque o token já teria morrido no envio dela.
     *   - `$requestedStep > $pendingStep` é RECUSADO. É o que fecha o pulo de
     *     etapa por POST forjado — sem isso alguém posta direto na final e
     *     completa um lead sem passar pela validação das intermediárias.
     *   - token DESTA sessão que não abre lead é `form_completed`, não
     *     `invalid_token`. O token morre no envio final, então um lead
     *     concluído não tem token para achar; sem essa distinção o visitante
     *     que reenviasse receberia "token inválido", que é mentira sobre o
     *     que aconteceu.
     *   - COM token, `_step` ausente é `invalid_step`, e não a etapa 1. O
     *     default `?? 1` está certo onde não há token — ali só a etapa 1
     *     pode nascer. Com token ele era um palpite silencioso e errado: um
     *     POST sem `_step` num lead em `pending_step = 2` resolvia como
     *     `merge` na etapa 1, e o engine validava os campos da etapa 1
     *     contra um corpo que trazia os da 2. Obrigatório na etapa 1 =
     *     `validation_failed` citando campos que a visitante já preencheu;
     *     etapa 1 toda opcional = `mergeStep` gravando string vazia por cima
     *     das respostas reais dela. Dizer o que faltou custa uma linha; o
     *     silêncio custava o dado.
     *
     * @param int  $totalSteps    quantas etapas o formulário declara (>= 1)
     * @param ?int $requestedStep o `_step` do POST; null quando ausente
     * @param bool $hasToken      veio `_step_token` no POST
     * @param ?int $pendingStep   a etapa pendente do lead; null = não achou lead aberto
     * @param bool $tokenWasOurs  o token está na lista que ESTA sessão emitiu
     * @return array{action:string, code?:string, step?:int, completes?:bool, next_step?:?int}
     */
    public static function resolveStep(
        int $totalSteps,
        ?int $requestedStep,
        bool $hasToken,
        ?int $pendingStep,
        bool $tokenWasOurs = false
    ): array {
        $step = $requestedStep ?? 1;

        // Fora do intervalo declarado nunca é aceito, com token ou sem.
        if ($step < 1 || $step > $totalSteps) {
            return ['action' => 'reject', 'code' => 'invalid_step'];
        }

        // Um token que esta sessão não emitiu não abre lead nenhum — nem para
        // continuar, nem para saber que ele existe. É a tranca que a decisão 5
        // do spec promete: o token identifica o lead, a sessão prova que é a
        // mesma visitante. Não cria modo de falha novo, porque o verifyCsrf()
        // já exige a mesma sessão em toda etapa; se ela morreu, o pedido nem
        // chega aqui.
        if ($hasToken && !$tokenWasOurs) {
            return ['action' => 'reject', 'code' => 'invalid_token'];
        }

        // Com token, `_step` ausente é ERRO — nunca a etapa 1. Ver a terceira
        // regra do docblock: o `?? 1` lá em cima serve ao caso SEM token, e
        // aqui ele reescrevia calado a etapa 1 de um lead que já estava na 2.
        // Depois da guarda de sessão de propósito: token alheio segue
        // respondendo `invalid_token` e não ganha um segundo código capaz de
        // distinguir alguma coisa sobre ele.
        if ($hasToken && $requestedStep === null) {
            return ['action' => 'reject', 'code' => 'invalid_step'];
        }

        if (!$hasToken) {
            // Sem token não há lead, e só a primeira etapa pode nascer.
            if ($step !== 1) {
                return ['action' => 'reject', 'code' => 'invalid_step'];
            }
            return self::advanceFrom('create', 1, $totalSteps);
        }

        if ($pendingStep === null) {
            // O token é desta sessão (a guarda acima já barrou o resto) e não
            // acha lead aberto: só pode ter morrido no envio final. Dizer
            // "token inválido" aqui seria mentir sobre o que aconteceu.
            return ['action' => 'reject', 'code' => 'form_completed'];
        }

        if ($step > $pendingStep) {
            return ['action' => 'reject', 'code' => 'invalid_step'];
        }

        if ($step < $pendingStep) {
            // Reedita: sobrescreve aqueles campos, o ponteiro não anda para
            // trás, e o visitante volta para onde estava.
            return [
                'action' => 'merge',
                'step' => $step,
                'completes' => false,
                'next_step' => $pendingStep,
            ];
        }

        return self::advanceFrom('merge', $step, $totalSteps);
    }

    /** PURO — o resultado de uma etapa que AVANÇA o ponteiro. */
    private static function advanceFrom(string $action, int $step, int $totalSteps): array
    {
        $completes = $step >= $totalSteps;
        return [
            'action' => $action,
            'step' => $step,
            'completes' => $completes,
            'next_step' => $completes ? null : $step + 1,
        ];
    }

    /**
     * PURO — quais campos obrigatórios faltam no lead acumulado, na ordem de
     * declaração.
     *
     * Rede para o envio final, não guarda principal: a regra de ordenação do
     * `resolveStep()` já impede pular etapa, então isto só alcança o caso de
     * alguém reorganizar `steps` ou tornar um campo obrigatório com lead em
     * andamento. Sem a rede, "completo" passaria a significar "chegou no
     * fim" em vez de "está completo".
     *
     * @param list<array<string,mixed>> $fields a declaração de `forms.<id>.fields`
     * @param array<string,mixed>       $data   o lead acumulado
     * @return list<string>
     */
    public static function missingRequired(array $fields, array $data): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (!is_array($field) || empty($field['required'])) {
                continue;
            }
            $name = (string) ($field['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $value = $data[$name] ?? null;

            // Campo `file` guarda array de metadados com o marcador `_file`;
            // presente é suficiente, e não há string para aparar.
            if (is_array($value)) {
                if (empty($value['_file'])) {
                    $out[] = $name;
                }
                continue;
            }

            if (!is_string($value) || trim($value) === '') {
                $out[] = $name;
            }
        }
        return $out;
    }

    public static function fromRow(array $row): self
    {
        $s = new self();
        $s->id = (int) $row['id'];
        $s->formId = (string) $row['form_id'];
        $s->data = is_string($row['data'] ?? null) ? (array) (json_decode($row['data'], true) ?? []) : [];
        $s->ip = $row['ip'] ?? null;
        $s->userAgent = $row['user_agent'] ?? null;
        $s->referer = $row['referer'] ?? null;
        $s->emailStatus = $row['email_status'] ?? null;
        $s->emailError = $row['email_error'] ?? null;
        $s->sentTo = $row['sent_to'] ?? null;
        $s->blockedBy = $row['blocked_by'] ?? null;
        // Null-coalesce porque uma base pré-migration não tem as colunas —
        // e ausente significa exatamente o mesmo que NULL: completo.
        $s->pendingStep = isset($row['pending_step']) && $row['pending_step'] !== null
            ? (int) $row['pending_step']
            : null;
        $s->stepToken = isset($row['step_token']) && $row['step_token'] !== null
            ? (string) $row['step_token']
            : null;
        $s->params = is_string($row['params'] ?? null) ? (array) (json_decode($row['params'], true) ?? []) : [];
        $s->createdAt = (string) $row['created_at'];
        return $s;
    }

    public static function find(int $id): ?self
    {
        $row = App::instance()->db->fetch('SELECT * FROM form_submissions WHERE id = ? LIMIT 1', [$id]);
        return $row === null ? null : self::fromRow($row);
    }

    /**
     * O lead ABERTO deste formulário com este token, ou null.
     *
     * Quatro condições, e cada uma fecha uma coisa: `form_id` impede um token
     * de um formulário anexar dado em outro; `step_token` é a credencial;
     * `pending_step IS NOT NULL` é o que garante que só lead aberto recebe
     * dado — é o `pending_step`, não o token, a autoridade sobre isso; e
     * `blocked_by IS NULL` é defesa em PROFUNDIDADE.
     *
     * Quem barra hoje o lead EM ANDAMENTO que o admin acabou de mandar para o
     * spam é o `step_token`: o `markSpam()` o zera, e nenhum valor casa com
     * uma coluna nula. Não é o `pending_step` — desde a revisão final o
     * `markSpam()` o PRESERVA (ver o docblock dele: apagá-lo destruía a única
     * informação que o `markNotSpam()` não conseguia devolver), então a linha
     * bloqueada continua casando com `pending_step IS NOT NULL`. Sobra o
     * recorte de `blocked_by`, que não custa nada e é o que resta de pé se um
     * `markSpam()` futuro voltar a esquecer de matar a credencial — a
     * situação exata que existia antes da v3.8.0. O regression test deste
     * caminho (chama markSpam() de verdade, afirma que este método devolve
     * null depois) é o
     * form_step_persistence_test.php; spam_submissions_test.php é a guarda
     * estrutural da POSIÇÃO do antispam — nunca disparar webhook/e-mail.
     */
    public static function findOpenByStepToken(string $formId, string $token): ?self
    {
        if ($token === '') {
            return null;
        }
        $row = App::instance()->db->fetch(
            'SELECT * FROM form_submissions WHERE form_id = ? AND step_token = ? AND pending_step IS NOT NULL AND blocked_by IS NULL
             LIMIT 1',
            [$formId, $token]
        );
        return $row === null ? null : self::fromRow($row);
    }

    /**
     * Flags de `json_encode` de `data` e `params` — num lugar só, para o
     * merge de uma etapa gravar exatamente como o insert da primeira.
     *
     * `JSON_INVALID_UTF8_SUBSTITUTE` é o que impede um único byte inválido
     * vindo do POST de derrubar a submissão inteira — ver o docblock de
     * `create()`.
     */
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    public static function create(array $payload): self
    {
        // params stays NULL when empty (never "[]"/"{}") — matches the
        // JSON NULL column: NULL means "no click id captured this session",
        // not an empty JSON value.
        $params = (array) ($payload['params'] ?? []);

        // JSON_INVALID_UTF8_SUBSTITUTE porque `data` vem direto do POST de um
        // visitante anônimo. Sem isso, um único byte inválido em qualquer
        // campo faz json_encode devolver false, o MySQL rejeita a coluna JSON
        // ("Invalid JSON text: The document is empty") e a exceção sobe como
        // 500 — o visitante vê erro e o lead se perde inteiro. Substituir o
        // byte perde um caractere; falhar perde a submissão.
        $encodeFlags = self::JSON_FLAGS;

        $id = App::instance()->db->insert('form_submissions', [
            'form_id'      => $payload['form_id'],
            'data'         => json_encode($payload['data'] ?? [], $encodeFlags),
            'ip'           => $payload['ip'] ?? null,
            'user_agent'   => isset($payload['user_agent']) ? mb_substr((string) $payload['user_agent'], 0, 500) : null,
            'referer'      => isset($payload['referer']) ? mb_substr((string) $payload['referer'], 0, 500) : null,
            'email_status' => $payload['email_status'] ?? null,
            'email_error'  => $payload['email_error'] ?? null,
            'sent_to'      => $payload['sent_to'] ?? null,
            // NULL para submissão normal. Preenchido só pelo antispam, e só
            // quando o formulário pediu para guardar — ver PublicFormController.
            'blocked_by'   => $payload['blocked_by'] ?? null,
            // Etapas. AUSENTE = NULL = completo e sem token, que é o
            // comportamento de todo formulário sem `steps` — e o de toda
            // linha que já existe na tabela.
            //
            // Esta lista é explícita, e coluna fora dela é descartada em
            // SILÊNCIO. Foi assim que `sort_order` se perdeu duas vezes
            // (Item::create e Term::create): o valor calculado pelo
            // chamador não chegava ao banco e nada acusava.
            'pending_step' => $payload['pending_step'] ?? null,
            'step_token'   => $payload['step_token'] ?? null,
            'params'       => $params === [] ? null : json_encode($params, $encodeFlags),
        ]);
        return self::find($id) ?? throw new \RuntimeException('Failed to record form submission.');
    }

    public function markEmail(string $status, ?string $error = null, ?string $sentTo = null): void
    {
        App::instance()->db->update('form_submissions', [
            'email_status' => $status,
            'email_error'  => $error,
            'sent_to'      => $sentTo,
        ], ['id' => $this->id]);
        $this->emailStatus = $status;
        $this->emailError = $error;
        $this->sentTo = $sentTo;
    }

    /**
     * PURO-ish — os `path` que este merge deixa de referenciar.
     *
     * O `array_merge` troca o valor de uma chave sem olhar o que havia ali.
     * Quando o que havia era metadado de upload (`_file`), o arquivo em disco
     * some do `data` e NADA mais o alcança: o `deleteWithFiles()` percorre o
     * `data`, então o órfão sobrevive ao apagar do painel e aos dois
     * `cleanup:*`. Não é caso de laboratório — é a visitante voltando uma
     * etapa para trocar o anexo (fluxo que a documentação apoia) ou clicando
     * duas vezes em "Próximo" numa conexão ruim.
     *
     * Só entra chave presente NOS DOIS lados. Reenviar a etapa sem escolher
     * arquivo de novo nem chega aqui: o `validate()` sai por `continue` sem
     * gravar a chave quando não há upload, então o metadado antigo é
     * preservado pelo próprio `array_merge`.
     *
     * Compara por `path` para não apagar o arquivo que acabou de ser gravado
     * caso o novo valor seja, por qualquer caminho, o MESMO arquivo. Valor
     * novo que não é `_file` conta como diferente: o antigo deixou de ser
     * referenciado do mesmo jeito.
     *
     * @param array<string,mixed> $values
     * @return list<string>
     */
    private function replacedFilePaths(array $values): array
    {
        $paths = [];
        foreach ($values as $name => $new) {
            $old = $this->data[$name] ?? null;
            if (!is_array($old) || empty($old['_file']) || !isset($old['path'])) {
                continue;
            }
            $newPath = is_array($new) && !empty($new['_file'])
                ? (string) ($new['path'] ?? '')
                : '';
            if ($newPath !== (string) $old['path']) {
                $paths[] = (string) $old['path'];
            }
        }
        return $paths;
    }

    /**
     * Mescla os valores de uma etapa e move o ponteiro.
     *
     * `array_merge` por nome de campo: reenvio de etapa anterior sobrescreve
     * aqueles campos e preserva todos os outros. `params`, `ip`,
     * `user_agent`, `referer` e o token não são tocados — a atribuição é a do
     * primeiro contato, e o token vive até o lead fechar.
     *
     * O upload que a sobrescrita desreferencia é apagado DEPOIS do UPDATE, e
     * não antes: falhando a gravação, o `data` no banco ainda aponta para o
     * arquivo antigo, e tê-lo apagado deixaria a linha apontando para o
     * nada. Ver `replacedFilePaths()` para por que isto existe.
     */
    public function mergeStep(array $values, int $pendingStep): void
    {
        $replaced = $this->replacedFilePaths($values);
        $data = array_merge($this->data, $values);
        App::instance()->db->update('form_submissions', [
            'data' => json_encode($data, self::JSON_FLAGS),
            'pending_step' => $pendingStep,
        ], ['id' => $this->id]);
        $this->data = $data;
        $this->pendingStep = $pendingStep;

        foreach ($replaced as $path) {
            FormUploadService::delete($path);
        }
    }

    /**
     * Fecha o lead: mescla a última etapa e zera ponteiro e token.
     *
     * Os dois no MESMO `UPDATE`, e isso é requisito e não estilo: o ponteiro
     * porque o lead está completo, o token porque ele morre junto. Se o token
     * sobrevivesse, ficaria no banco uma credencial que só é inerte enquanto
     * todo caminho conferir `pending_step` — o tipo de garantia que apodrece
     * quando alguém adiciona um caminho novo.
     *
     * ─── Por que a condição no WHERE, e por que devolve bool ──────────────
     *
     * "Exatamente uma vez na etapa final" era um UPDATE incondicional, e o
     * `submit()` relê o lead fora de qualquer transação. Dois POSTs finais
     * sobrepostos com o mesmo token resolviam os dois como completos: dois
     * e-mails, dois `velk_form_success`, dois disparos de `webhook`. Na
     * prática eles não se sobrepõem porque o handler de sessão em ARQUIVO os
     * serializa — `Session::boot()` chama `session_start()` em toda
     * requisição que lê o `_csrf` e nada chama `session_write_close()`. Isso
     * é verdade por acidente e evapora num handler Redis/memcached com lock
     * desligado; `pending_step IS NOT NULL` no WHERE faz o banco decidir, que
     * é onde a decisão pertence. O segundo POST vê zero linhas afetadas e o
     * chamador pula os efeitos.
     *
     * Escrito à mão e não pelo `Database::update()` porque aquele monta o
     * WHERE só com igualdades — `IS NOT NULL` não cabe na forma dele. O
     * `rowCount()` é confiável aqui mesmo no MySQL, que conta linhas
     * ALTERADAS e não casadas: a linha casada sempre muda, porque
     * `pending_step` sai de não-nulo para NULL.
     *
     * @return bool `false` = outra requisição já fechou este lead; o chamador
     *              não deve disparar e-mail, conversão nem webhook.
     */
    public function completeWith(array $values): bool
    {
        $replaced = $this->replacedFilePaths($values);
        $data = array_merge($this->data, $values);

        $changed = App::instance()->db->query(
            'UPDATE form_submissions SET data = ?, pending_step = NULL, step_token = NULL'
                . ' WHERE id = ? AND pending_step IS NOT NULL',
            [json_encode($data, self::JSON_FLAGS), $this->id]
        )->rowCount();

        if ($changed === 0) {
            return false;
        }

        $this->data = $data;
        $this->pendingStep = null;
        $this->stepToken = null;

        // Depois do UPDATE, pelo mesmo motivo do mergeStep(): enquanto a
        // gravação não vale, o `data` do banco ainda referencia o arquivo
        // antigo.
        foreach ($replaced as $path) {
            FormUploadService::delete($path);
        }
        return true;
    }

    /** Quantas submissões aprovadas E completas — é este número que vira badge no menu. */
    public static function count(string $formId): int
    {
        return (int) App::instance()->db->fetchColumn(
            'SELECT COUNT(*) FROM form_submissions WHERE form_id = ? AND blocked_by IS NULL AND pending_step IS NULL',
            [$formId]
        );
    }

    /**
     * Quantas foram reprovadas pelo antispam.
     *
     * Separado de `count()` porque as duas respondem perguntas diferentes e
     * nunca devem somar: "quantos leads eu tenho" não inclui bot.
     */
    public static function countSpam(string $formId): int
    {
        return (int) App::instance()->db->fetchColumn(
            'SELECT COUNT(*) FROM form_submissions WHERE form_id = ? AND blocked_by IS NOT NULL',
            [$formId]
        );
    }

    /**
     * Quantos leads em andamento (não bloqueados) o formulário tem.
     *
     * Irmã do `countSpam()`, e existe pelo mesmo motivo que ele: decidir se o
     * painel OFERECE o recorte. `formHasSteps()` sozinho não serve para isso
     * porque `Config::formSteps()` devolve [] para qualquer malformação
     * estrutural, de propósito e em silêncio — tirar o `steps` do site.json,
     * ou só errar a digitação, apagava o `<select>` de completude, forçava
     * `?completeness=` de volta ao default e deixava toda consulta do painel
     * com `AND pending_step IS NULL`. O que já tinha sido coletado de quem
     * não terminou — nome, e-mail, telefone — sumia da tela e ficava
     * inapagável, porque o delete precisa do `sid` que a lista deixou de
     * exibir. É exatamente o desfecho que o comentário do `showSpamLink`
     * chama de o pior possível, e pela mesma razão: LGPD não pergunta se a
     * configuração estava certa.
     *
     * `blocked_by IS NULL` porque a pergunta é sobre a lista NORMAL; a de
     * spam recorta por `all` e não oferece o controle.
     */
    public static function countIncomplete(string $formId): int
    {
        return (int) App::instance()->db->fetchColumn(
            'SELECT COUNT(*) FROM form_submissions WHERE form_id = ? AND blocked_by IS NULL AND pending_step IS NOT NULL',
            [$formId]
        );
    }

    /**
     * Cláusulas de filtro da listagem do admin, concatenáveis após
     * "WHERE form_id = ?". Compartilhadas entre filteredRecent e
     * countFiltered pra nunca divergirem. Pura — testável isolada.
     *
     * O recorte spam/aprovado mora AQUI, e não no chamador, porque é a
     * única forma de o default ser seguro: quem não sabe que spam existe
     * recebe só aprovados. Deixar a cláusula por conta de cada consulta
     * significaria que esquecer uma enche a lista de leads do cliente com
     * bot — falha silenciosa, do tipo que ninguém percebe até o cliente
     * reclamar.
     *
     * @return array{0: string, 1: array}
     */
    public static function filterWhere(array $filters): array
    {
        // `blocked_by IS NULL` é o default deliberado, não uma otimização:
        // ausência de chave significa "a lista normal".
        $sql = empty($filters['spam'])
            ? ' AND blocked_by IS NULL'
            : ' AND blocked_by IS NOT NULL';
        $params = [];

        // Completude, ao lado do recorte de spam e pelo MESMO motivo escrito
        // ali: é a única forma de o default ser seguro. Ausência de chave
        // significa "só os completos" — quem quer lead em andamento pede.
        //
        // Num formulário sem `steps` nenhuma linha tem `pending_step`, então
        // a cláusula não altera resultado nenhum ali. É o que mantém a
        // invariante de "nada muda para quem não usa etapas".
        $completeness = (string) ($filters['completeness'] ?? '');
        if ($completeness === 'incomplete') {
            $sql .= ' AND pending_step IS NOT NULL';
        } elseif ($completeness !== 'all') {
            $sql .= ' AND pending_step IS NULL';
        }

        // Date range. `date_to` is treated as inclusive end-of-day so
        // picking "today" actually shows today's submissions instead
        // of cutting off at midnight.
        if (!empty($filters['date_from'])) {
            $sql .= ' AND created_at >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $sql .= ' AND created_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        return [$sql, $params];
    }

    /** @param int $limit 0 = sem limite */
    public static function filteredRecent(string $formId, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        [$where, $extraParams] = self::filterWhere($filters);
        $sql = 'SELECT * FROM form_submissions WHERE form_id = ?' . $where
            . ' ORDER BY created_at DESC';
        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
        }
        $rows = App::instance()->db->fetchAll($sql, array_merge([$formId], $extraParams));
        return array_map(fn($r) => self::fromRow($r), $rows);
    }

    public static function countFiltered(string $formId, array $filters = []): int
    {
        [$where, $extraParams] = self::filterWhere($filters);
        return (int) App::instance()->db->fetchColumn(
            'SELECT COUNT(*) FROM form_submissions WHERE form_id = ?' . $where,
            array_merge([$formId], $extraParams)
        );
    }

    /**
     * PURO — a consulta de UM bloco da exportação em CSV: as submissões que
     * passam pelos filtros, da mais nova para a mais antiga, com id menor que
     * `$belowId` (null = a partir da mais nova).
     *
     * Keyset por id em vez de OFFSET, e é requisito: a exportação de um
     * formulário movimentado corre enquanto chegam submissões novas. Com
     * OFFSET, cada linha que entra no topo empurra a janela, e a última linha
     * de um bloco reaparece como a primeira do seguinte — duplicada no
     * arquivo. `id < ?` não se move quando chega linha nova.
     *
     * Dos filtros, só o período chega ao `filterWhere()` — `date_from` e
     * `date_to`, e nada mais. O recorte de leads reais (aprovados e completos)
     * é o default de lá, e só vale enquanto `spam` e `completeness` não
     * chegam: repassar o array inteiro faria a garantia depender de cada
     * chamador lembrar de não mandá-los — o erro que o docblock do
     * `filterWhere()` descreve para o recorte de spam. Filtrar aqui é o que
     * torna "só leads reais" uma propriedade da exportação, e não de quem a
     * chama.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public static function exportQuery(string $formId, array $filters, ?int $belowId, int $limit): array
    {
        // Um bloco de 0 linhas faria o exportRows() girar para sempre: o laço
        // só para quando um bloco vem incompleto, e com LIMIT 0 todo bloco
        // vem com 0 — exatamente o tamanho pedido. Negativo vira LIMIT -1, erro
        // de sintaxe no MySQL. Nenhum chamador passa isso; a guarda existe para
        // não haver como passar.
        if ($limit < 1) {
            throw new \InvalidArgumentException('exportQuery: o bloco precisa ter pelo menos 1 linha.');
        }
        [$where, $params] = self::filterWhere(array_intersect_key($filters, ['date_from' => true, 'date_to' => true]));
        $sql = 'SELECT * FROM form_submissions WHERE form_id = ?' . $where;
        $args = array_merge([$formId], $params);
        if ($belowId !== null) {
            $sql .= ' AND id < ?';
            $args[] = $belowId;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . (int) $limit;
        return [$sql, $args];
    }

    /**
     * Todas as submissões da exportação, lidas do banco em blocos de `$chunk`
     * — um formulário com dezenas de milhares de leads não cabe inteiro no
     * `memory_limit` de uma hospedagem compartilhada, e não precisa: o CSV
     * sai linha a linha. Ver `exportQuery()` para a ordem e o keyset.
     *
     * A PRIMEIRA consulta roda na chamada, e não no primeiro `foreach`: é o
     * que deixa o painel e o CLI descobrirem um banco fora do ar antes de
     * mandar qualquer byte do arquivo — no painel, antes do 200 com cabeçalho
     * de CSV; no CLI, antes do BOM. As seguintes correm conforme o arquivo é
     * escrito. Uma exportação sem linhas volta como lista vazia: o gerador
     * que terminou sem produzir nada não se percorre de novo (`foreach` nele
     * lança "Cannot traverse an already closed generator").
     *
     * @return iterable<self>
     */
    public static function exportRows(string $formId, array $filters, int $chunk = 500): iterable
    {
        $rows = self::exportBlocks($formId, $filters, $chunk);
        $rows->current();
        return $rows->valid() ? $rows : [];
    }

    /**
     * O laço de `exportRows()`: bloco a bloco, pelo keyset do `exportQuery()`,
     * até um bloco vir incompleto.
     *
     * @return \Generator<int, self>
     */
    private static function exportBlocks(string $formId, array $filters, int $chunk): \Generator
    {
        $belowId = null;
        do {
            [$sql, $args] = self::exportQuery($formId, $filters, $belowId, $chunk);
            $rows = App::instance()->db->fetchAll($sql, $args);
            foreach ($rows as $row) {
                $s = self::fromRow($row);
                $belowId = $s->id;
                yield $s;
            }
        } while (count($rows) === $chunk);
    }


    /**
     * Tira a marca de spam: a submissão volta para a lista de preenchimentos.
     *
     * Só isso. Email e webhook NÃO disparam — eles aconteceriam semanas
     * depois do envio, e um lead caindo no CRM com data de hoje mas conteúdo
     * de três semanas atrás confunde mais do que ajuda. Quem recupera o lead
     * está olhando para ele na tela; encaminhar é decisão de quem olha.
     *
     * O caminho inverso existe desde a v3.7.6 — ver markSpam() abaixo, que
     * também explica por que a decisão original de não tê-lo mudou.
     *
     * `blocked_by` é a ÚNICA coluna que muda, e isso é o que torna a volta
     * completa: o `markSpam()` preserva o `pending_step`, então um lead que
     * estava na etapa 2 volta para a lista na etapa 2 — em andamento, com a
     * marcação "aguardando conclusão", contado como incompleto e alcançável
     * pelo `cleanup:incomplete-submissions`. A visitante segue barrada, sem o
     * `step_token` que o bloqueio matou. Recuperar um falso positivo não pode
     * inventar que o lead terminou.
     */
    public function markNotSpam(): void
    {
        App::instance()->db->update('form_submissions', ['blocked_by' => null], ['id' => $this->id]);
        $this->blockedBy = null;
    }

    /**
     * Marca à mão o que os filtros deixaram passar: a submissão sai da lista
     * de preenchimentos e vai para a de spam, com `blocked_by = 'manual'`.
     *
     * ─── Por que isto passou a existir ───────────────────────────────────
     *
     * Até a v3.7.5 este caminho não existia de propósito, e a razão escrita
     * era que o apagar já tirava o preenchimento da lista, então um segundo
     * caminho que só esconde criaria dois lugares para procurar a mesma
     * coisa. Duas coisas que aquele raciocínio não pesou o derrubaram:
     *
     * 1. Apagar DESTRÓI o lead; marcar preserva. Marcação errada volta num
     *    clique pelo markNotSpam(); apagar não tem volta.
     * 2. Não é um segundo jeito de esconder, é uma CATEGORIA. Com
     *    `keep_spam` ligado, a lista de spam é o registro do que os filtros
     *    automáticos deixaram passar — informação que o apagar joga fora.
     *
     * ─── 'manual' e não outro nome ───────────────────────────────────────
     *
     * A etiqueta da listagem imprime `blocked_by` cru, ao lado de
     * 'honeypot' e 'turnstile'. 'manual' diz a verdade sobre a origem: não
     * foi filtro nenhum, foi decisão de quem estava olhando.
     *
     * ─── v3.8.0: mata a CREDENCIAL, preserva a POSIÇÃO ───────────────────
     *
     * Até então este método só gravava `blocked_by`, e um lead EM ANDAMENTO
     * marcado como spam continuava aceitando etapas do visitante: o
     * `step_token` seguia válido. Isso tinha que acabar, e acabou — o token
     * morre aqui, no mesmo UPDATE.
     *
     * O `pending_step`, NÃO. Ele é a única informação que diz quanto do lead
     * existe, e zerá-lo era destrutivo de um jeito que só aparecia depois:
     *
     * 1. `markNotSpam()` devolve `blocked_by = null` e não tem como devolver
     *    um `pending_step` que já foi apagado. Um falso positivo recuperado
     *    virava lead COMPLETO — passava a contar no badge e na lista de
     *    formulários, aparecia como concluído, sem a marcação "aguardando
     *    conclusão", sem nunca ter saído e-mail, sem nunca ter virado
     *    conversão, e fora do alcance do `cleanup:incomplete-submissions`.
     *    Recuperar um lead não pode inventar que ele terminou.
     * 2. Zerar aqui virava invariante silenciosa de que TODA linha bloqueada
     *    é completa, e duas coisas passaram a se apoiar nela em vez de dizer
     *    o que precisavam. Quem depende de invariante herda o bug de quem a
     *    quebrar.
     *
     * Os dois problemas que o zerar resolvia foram para onde pertencem:
     *
     *   - o painel de spam pede `completeness = 'all'` explicitamente (o
     *     controle nunca é oferecido ali de qualquer forma), então a linha
     *     recém-marcada aparece porque a consulta pediu, e não porque a
     *     coluna estava num valor conveniente;
     *   - `cleanup:incomplete-submissions` ganhou `AND blocked_by IS NULL`.
     *     Quem varre linha bloqueada é o `cleanup:spam-submissions`, que
     *     respeita a retenção de spam que `keepsSpamFor()` manda honrar.
     *
     * E o recorte de `blocked_by IS NULL` em `findOpenByStepToken()` volta a
     * ser barreira de verdade, não redundância — ver o docblock de lá.
     *
     * ─── O que NÃO acontece ──────────────────────────────────────────────
     *
     * O e-mail já saiu e o evento de conversão já disparou, no momento do
     * envio. Marcar como spam depois não desfaz nenhum dos dois, e não é
     * para desfazer: são efeitos que já aconteceram no mundo. O que a marca
     * muda é onde a submissão aparece no painel, daqui para frente.
     */
    public function markSpam(): void
    {
        App::instance()->db->update('form_submissions', [
            'blocked_by' => 'manual',
            // Mesmo UPDATE, de propósito: a credencial não pode sobreviver a
            // um instante do bloqueio. O `pending_step` fica — ver
            // "mata a CREDENCIAL, preserva a POSIÇÃO" acima.
            'step_token' => null,
        ], ['id' => $this->id]);
        $this->blockedBy = 'manual';
        $this->stepToken = null;
    }

    public function delete(): void
    {
        App::instance()->db->delete('form_submissions', ['id' => $this->id]);
    }

    /**
     * Apaga a submissão E os arquivos que ela guardava.
     *
     * O painel já fazia isso, com um laço próprio; o `cleanup:spam-submissions`
     * NÃO — fazia um DELETE cru e deixava o arquivo órfão em
     * storage/form-uploads/. Um caminho só, usado pelos três pontos (painel,
     * limpeza de spam, limpeza de incompletos), é o que impede a terceira
     * cópia de nascer com o mesmo vazamento.
     *
     * Não-fatal por construção: `FormUploadService::delete()` nunca lança —
     * arquivo já ausente ou path corrompido simplesmente não faz nada.
     */
    public function deleteWithFiles(): void
    {
        foreach ($this->data as $value) {
            if (is_array($value) && !empty($value['_file']) && isset($value['path'])) {
                FormUploadService::delete((string) $value['path']);
            }
        }
        $this->delete();
    }
}
