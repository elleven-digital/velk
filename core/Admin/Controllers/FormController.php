<?php

declare(strict_types=1);

namespace Velk\Admin\Controllers;

use Velk\Models\FormRecipient;
use Velk\Models\FormSettings;
use Velk\Models\FormSubmission;
use Velk\Request;
use Velk\Response;
use Velk\Services\FormCsv;
use Velk\Services\FormUploadService;

final class FormController extends Controller
{
    public function index(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;

        $forms = (array) $this->app->config->site('forms', []);
        $stats = [];
        foreach ($forms as $id => $def) {
            $stats[(string) $id] = [
                'def'             => $def,
                'submission_count' => FormSubmission::count((string) $id),
                'recipient_count'  => count(FormRecipient::forForm((string) $id)),
            ];
        }

        return $this->render('forms/index', [
            'forms' => $stats,
        ]);
    }

    public function show(Request $request, array $params): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;

        $id = (string) ($params['id'] ?? '');
        $form = $this->app->config->site('forms.' . $id);
        if (!is_array($form)) {
            return Response::notFound(__('forms.error_not_found'));
        }

        // Duas listas na mesma tela: os preenchimentos e, atrás do link
        // "Spam", os que o antispam reprovou. Não é um filtro entre os
        // outros — é outro conjunto, e por isso a contagem, a paginação e o
        // vazio são todos do recorte escolhido.
        $isSpamView = !empty($request->query['spam']);

        // Pull filter state from query string. Only dates: UTM filters were
        // dropped in v4 along with the columns behind them — see the view.
        // Completude: '' (padrão) = só completos · 'all' · 'incomplete'.
        // Valor fora dos três cai no padrão — o recorte seguro.
        //
        // Quem decide se o controle EXISTE é o `$showCompleteness`, e ele é
        // mais largo que `formHasSteps()` de propósito — mesma forma do
        // `showSpamLink` logo abaixo, e pelo mesmo motivo. `formSteps()`
        // devolve [] para qualquer malformação estrutural, calada: tirar o
        // `steps` do site.json, ou só errar a digitação, fazia o `<select>`
        // desaparecer, forçava um `?completeness=` montado à mão de volta ao
        // default e punha `AND pending_step IS NULL` em toda consulta do
        // painel. O lead que já tinha sido coletado sumia da tela e ficava
        // inapagável junto — o delete precisa do `sid` que a lista parou de
        // exibir. Oferecer o recorte quando JÁ EXISTE linha incompleta é o
        // que mantém o dado alcançável enquanto a configuração não volta ao
        // lugar. A contagem some quando a última linha incompleta sai.
        $hasSteps = $this->app->config->formHasSteps($id);
        $incompleteCount = FormSubmission::countIncomplete($id);
        $showCompleteness = $hasSteps || $incompleteCount > 0;

        $completeness = (string) ($request->query['completeness'] ?? '');
        if (!$showCompleteness || !in_array($completeness, ['all', 'incomplete'], true)) {
            $completeness = '';
        }

        $filters = [
            'date_from'    => (string) ($request->query['date_from'] ?? ''),
            'date_to'      => (string) ($request->query['date_to'] ?? ''),
            'spam'         => $isSpamView,
            // O painel de spam é sempre 'all', e não o default: o controle
            // nunca é oferecido ali, e desde que `markSpam()` voltou a
            // PRESERVAR o `pending_step` uma linha bloqueada pode estar
            // incompleta. Sem isto ela sumiria da tela um segundo depois de o
            // badge "Spam (1)" anunciar que existe. Fixo aqui, e não apoiado
            // numa invariante de outro método, porque foi exatamente uma
            // invariante dessas que falhou da primeira vez.
            'completeness' => $isSpamView ? 'all' : $completeness,
        ];

        // `completeness` conta como filtro ativo quando o controle é oferecido
        // e o estado não é 'all': o default '' já recorta (só completos),
        // então SEM query string nenhuma um formulário com um lead só em
        // andamento mentia "Nenhum preenchimento ainda." sem o sufixo
        // "· filtrado" e sem o link para ver o próprio lead. Onde o controle
        // não aparece — sem etapas e sem nenhuma linha incompleta — o recorte
        // é irrelevante e não pode virar filtro ativo, senão todo painel do
        // mundo passaria a dizer "filtrado".
        // E não conta no painel de spam, onde o recorte é 'all' fixo: um
        // filtro que não filtra seria só barulho constante.
        $hasActiveFilter = $filters['date_from'] !== '' || $filters['date_to'] !== ''
            || ($showCompleteness && !$isSpamView && $completeness !== 'all');

        $spamCount = FormSubmission::countSpam($id);

        // O link aparece se o formulário guarda spam OU se já existe spam
        // guardado. A segunda metade importa: desligar `keep_spam` depois de
        // ter coletado não pode esconder o que já está no banco — dado
        // pessoal invisível e inapagável pelo painel é o pior desfecho
        // possível, ainda mais com LGPD no meio. O link some quando o último
        // registro é apagado.
        $showSpamLink = $this->app->config->keepsSpamFor($id) || $spamCount > 0;

        $submissionsTotal = FormSubmission::countFiltered($id, $filters);
        $pg = \Velk\Paginator::make($request->query['page'] ?? null, $submissionsTotal);

        return $this->render('forms/show', [
            'formId'      => $id,
            'form'        => $form,
            'settings'    => FormSettings::forForm($id),
            'recipients'  => FormRecipient::forForm($id),
            'submissions' => FormSubmission::filteredRecent($id, $filters, $pg['perPage'], $pg['offset']),
            'submissionsTotal' => $submissionsTotal,
            'pg'          => $pg,
            'pgQuery'     => array_diff_key($request->query, ['page' => '']),
            'fields'      => (array) ($form['fields'] ?? []),
            // Qual campo rotula o cartão fechado na lista. Null = regra
            // histórica (primeiro texto não-vazio). Resolvido aqui, e não na
            // view, pelo mesmo motivo que 'fields': a view consome valores,
            // não escava a definição crua do site.json.
            'previewField' => is_string($form['preview_field'] ?? null)
                ? (string) $form['preview_field']
                : null,
            // E qual vai na linha de baixo, antes da data. Null = só a data.
            'previewSubtitleField' => is_string($form['preview_subtitle_field'] ?? null)
                ? (string) $form['preview_subtitle_field']
                : null,
            'hasSteps'     => $hasSteps,
            // Quem gateia o `<select>` é este, não o `hasSteps`: ver o
            // comentário lá em cima. Um formulário cujo `steps` parou de
            // parsear continua oferecendo o recorte enquanto restar lead em
            // andamento para alcançar.
            'showCompleteness' => $showCompleteness,
            'completeness' => $completeness,
            'stepCount'    => count($this->app->config->formSteps($id)),
            'filters'     => $filters,
            'hasActiveFilter' => $hasActiveFilter,
            'isSpamView'  => $isSpamView,
            'spamCount'   => $spamCount,
            'showSpamLink' => $showSpamLink,
            // Diferente do showSpamLink de propósito: aquele fica ligado
            // quando há spam antigo mesmo com a chave desligada, para não
            // esconder dado já coletado. Marcar lead NOVO exige a chave.
            'canMarkSpam' => $this->app->config->keepsSpamFor($id),
            // Os checkboxes do bloco "Exportar" saem da mesma lista que monta
            // o cabeçalho do arquivo — ver exportColumns().
            'exportColumns' => $this->exportColumns($form),
        ]);
    }

    /**
     * Baixa os preenchimentos em CSV — o bloco "Exportar" da tela.
     *
     * Só leads reais (aprovados e completos): os filtros passam apenas o
     * período, e o resto é o default do `filterWhere()`. O recorte da tela
     * (spam, completude) não vale aqui, de propósito — spam e lead em
     * andamento ficam só na tela.
     *
     * GET, como o `downloadFile()`: é leitura, exige sessão e não CSRF, e a
     * URL resultante serve de "exportação salva".
     *
     * ─── O marcador `p` ──────────────────────────────────────────────────
     *
     * Checkbox desmarcado não é enviado, então "desmarquei todas as colunas"
     * e "link nu, sem `cols`" chegam iguais: sem o parâmetro. E têm de
     * significar o oposto — o link nu exporta tudo; desmarcar tudo é um
     * pedido impossível. O formulário manda `p=1` sempre, e é ele que separa
     * os dois. Cai no mesmo erro o link salvo cujas colunas sumiram todas do
     * site.json: um CSV sem colunas não é um arquivo, é um bug silencioso.
     */
    public function export(Request $request, array $params): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;

        $id = (string) ($params['id'] ?? '');
        $form = $this->app->config->site('forms.' . $id);
        if (!is_array($form)) {
            return Response::notFound(__('forms.error_not_found'));
        }

        $q = $request->query;
        $requested = FormCsv::requested($q['cols'] ?? null);
        $columns = FormCsv::select($requested, $this->exportColumns($form));
        if (($requested === [] && !empty($q['p'])) || $columns === []) {
            $this->app->session->flash('error', __('forms.export_no_columns'));
            return Response::redirect(admin_url('forms/' . $id));
        }

        // Separador fora dos dois cai no padrão, sem erro; data inválida é
        // ignorada. Os dois só chegam de uma URL montada à mão — o formulário
        // não manda outra coisa.
        $sep = ($q['sep'] ?? '') === ',' ? ',' : ';';
        $filters = [
            'date_from' => FormCsv::validDate($q['date_from'] ?? null) ? (string) $q['date_from'] : '',
            'date_to'   => FormCsv::validDate($q['date_to'] ?? null) ? (string) $q['date_to'] : '',
        ];

        $name = FormCsv::filename($id, date('Y-m-d'));

        // A primeira consulta roda aqui — o exportRows() a faz na chamada —,
        // antes de qualquer header: se o banco falhar, é um 500 comum. Dentro
        // do stream() ela rodaria depois do 200 com cabeçalho de CSV, e a
        // página de erro sairia colada no arquivo baixado, como se ele
        // estivesse certo.
        $rows = FormSubmission::exportRows($id, $filters);

        return (new Response())
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header(
                'Content-Disposition',
                'attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name)
            )
            // Nome, e-mail, telefone e IP de quem preencheu — nunca cacheado
            // por proxy/CDN, o mesmo cuidado do download de anexo.
            ->header('Cache-Control', 'private, no-store')
            ->stream(function () use ($rows, $columns, $sep): void {
                $out = fopen('php://output', 'w');
                FormCsv::write($out, $rows, $columns, $sep, true);
                fclose($out);
            });
    }

    /**
     * As colunas que este formulário pode exportar — a MESMA lista para os
     * checkboxes do bloco "Exportar" (show) e para o cabeçalho do arquivo
     * (export). Um lugar só: é o que faz a tela oferecer exatamente o que a
     * rota entende.
     *
     * @param array<string,mixed> $form `forms.<id>` do site.json
     * @return list<array{key:string, label:string, group:string}>
     */
    private function exportColumns(array $form): array
    {
        return FormCsv::columns($form, fn(string $key): string => __raw($key));
    }

    /**
     * Save the per-form conversion settings: which fields map onto Google's
     * enhanced-conversion identifiers, and the optional snippet that runs
     * when the conversion fires.
     *
     * The mapping arrives keyed by Google's names, but nothing here trusts
     * that — FormSettings::save() walks its own whitelist, so an extra key
     * in the POST is never read rather than merely rejected.
     */
    public function saveConversion(Request $request, array $params): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;
        $csrf = $this->verifyCsrfOrFail($request);
        if ($csrf !== null) return $csrf;

        $id = (string) ($params['id'] ?? '');
        if (!is_array($this->app->config->site('forms.' . $id))) {
            return Response::notFound(__('forms.error_not_found'));
        }

        $snippet = (string) ($request->post['conversion_snippet'] ?? '');

        FormSettings::save(
            $id,
            (array) ($request->post['mapping'] ?? []),
            trim($snippet) === '' ? null : $snippet
        );

        $this->app->session->flash('success', __('forms.flash_conversion_saved'));
        return Response::redirect(admin_url('forms/' . $id));
    }

    public function addRecipient(Request $request, array $params): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;
        $csrf = $this->verifyCsrfOrFail($request);
        if ($csrf !== null) return $csrf;

        $id = (string) ($params['id'] ?? '');
        $email = trim((string) ($request->post['email'] ?? ''));
        $name = trim((string) ($request->post['name'] ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->app->session->flash('error', __('auth.invalid_email'));
            return Response::redirect(admin_url('forms/' . $id));
        }

        $existing = $this->app->db->fetch(
            'SELECT id FROM form_recipients WHERE form_id = ? AND email = ? LIMIT 1',
            [$id, strtolower($email)]
        );
        if ($existing !== null) {
            $this->app->session->flash('error', __('forms.error_email_exists'));
            return Response::redirect(admin_url('forms/' . $id));
        }

        FormRecipient::create($id, $email, $name === '' ? null : $name);
        $this->app->session->flash('success', __('forms.flash_recipient_added'));
        return Response::redirect(admin_url('forms/' . $id));
    }

    public function removeRecipient(Request $request, array $params): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;
        $csrf = $this->verifyCsrfOrFail($request);
        if ($csrf !== null) return $csrf;

        $id = (string) ($params['id'] ?? '');
        $rid = (int) ($params['rid'] ?? 0);
        $r = FormRecipient::find($rid);
        if ($r !== null && $r->formId === $id) {
            $r->delete();
            $this->app->session->flash('success', __('forms.flash_recipient_removed'));
        }
        return Response::redirect(admin_url('forms/' . $id));
    }

    public function deleteSubmission(Request $request, array $params): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;
        $csrf = $this->verifyCsrfOrFail($request);
        if ($csrf !== null) return $csrf;

        $id = (string) ($params['id'] ?? '');
        $sid = (int) ($params['sid'] ?? 0);
        $s = FormSubmission::find($sid);
        if ($s !== null && $s->formId === $id) {
            // Arquivos antes da linha — ver FormSubmission::deleteWithFiles(),
            // que é o mesmo caminho que os dois `cleanup:*` usam.
            $s->deleteWithFiles();
            $this->app->session->flash('success', __('forms.flash_submission_deleted'));
        }
        return Response::redirect(admin_url('forms/' . $id));
    }

    /**
     * "Não é spam": devolve um preenchimento bloqueado para a lista normal.
     *
     * O antispam erra — autofill preenchendo o campo escondido, Turnstile
     * reprovando quem está numa conexão ruim. Guardar o bloqueado só resolve
     * metade do problema; sem este caminho de volta, o lead recuperado fica
     * preso numa lista que ninguém trata como caixa de entrada.
     *
     * Volta para a lista de preenchimentos, e não para uma terceira lista de
     * "recuperados": depois de você dizer que não é spam, ele é um lead como
     * qualquer outro.
     */
    /**
     * Marca à mão um preenchimento como spam.
     *
     * Exige `keep_spam` LIGADO no formulário, e não basta existir spam
     * guardado: o link "Spam" do topo também aparece com a chave desligada,
     * só para não esconder dado pessoal já coletado. Marcar um lead NOVO num
     * formulário que declarou não guardar spam seria gravar registro que a
     * configuração diz não querer.
     */
    public function markSpam(Request $request, array $params): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;
        $csrf = $this->verifyCsrfOrFail($request);
        if ($csrf !== null) return $csrf;

        $id = (string) ($params['id'] ?? '');
        $sid = (int) ($params['sid'] ?? 0);

        if (!$this->app->config->keepsSpamFor($id)) {
            return Response::notFound();
        }

        $s = FormSubmission::find($sid);

        // Mesma conferência do markNotSpam e do deleteSubmission: sem ela, o
        // id de uma submissão de OUTRO formulário seria marcável por aqui.
        // `blockedBy === null` impede remarcar o que já está bloqueado.
        if ($s !== null && $s->formId === $id && $s->blockedBy === null) {
            $s->markSpam();
            $this->app->session->flash('success', __('forms.flash_marked_spam'));
        }

        // Volta para a lista em que a pessoa estava, não para a de spam: aqui
        // ela está triando a caixa de entrada, e ser jogada para outra tela a
        // cada marcação quebraria o fluxo. O flash é a confirmação.
        return Response::redirect(admin_url('forms/' . $id));
    }

    public function markNotSpam(Request $request, array $params): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;
        $csrf = $this->verifyCsrfOrFail($request);
        if ($csrf !== null) return $csrf;

        $id = (string) ($params['id'] ?? '');
        $sid = (int) ($params['sid'] ?? 0);
        $s = FormSubmission::find($sid);

        // A conferência do formulário importa: sem ela, o id de uma submissão
        // de OUTRO formulário desbloquearia por esta rota. Mesmo cuidado do
        // deleteSubmission logo acima.
        if ($s !== null && $s->formId === $id && $s->blockedBy !== null) {
            $s->markNotSpam();
            $this->app->session->flash('success', __('forms.flash_not_spam'));
        }

        // Volta para a lista normal, não para a de spam: é onde a submissão
        // está agora, e é o que confirma para quem clicou que deu certo.
        return Response::redirect(admin_url('forms/' . $id));
    }

    /**
     * Download autenticado de um campo de arquivo de uma submissão. O
     * caminho em disco vem SEMPRE da submissão gravada (nunca de input do
     * usuário) e passa pelo containment por realpath() de
     * FormUploadService::absolutePath() — qualquer coisa que não bata
     * (submissão de outro formulário, campo sem arquivo, path que escapa de
     * `form_uploads`, arquivo ausente) devolve 404, nunca um erro que
     * revele detalhe do sistema de arquivos.
     */
    public function downloadFile(Request $request, array $params): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;

        $id = (string) ($params['id'] ?? '');
        $sid = (int) ($params['sid'] ?? 0);
        $field = (string) ($params['field'] ?? '');

        $s = FormSubmission::find($sid);
        if ($s === null || $s->formId !== $id) {
            return Response::notFound(__('forms.error_file_not_found'));
        }

        $meta = $s->data[$field] ?? null;
        if (!is_array($meta) || empty($meta['_file']) || !isset($meta['path'])) {
            return Response::notFound(__('forms.error_file_not_found'));
        }

        $abs = FormUploadService::absolutePath((string) $meta['path']);
        if ($abs === null) {
            return Response::notFound(__('forms.error_file_not_found'));
        }

        $mime = (string) ($meta['mime'] ?? 'application/octet-stream');
        $originalName = (string) ($meta['name'] ?? basename($abs));
        // CR/LF e aspas fora do nome — evita injeção de header via um nome
        // de arquivo malicioso. filename* (RFC 5987) cobre acentos (comuns
        // em nomes pt-BR, ex. "Currículo.pdf") pros navegadores modernos;
        // filename= plano fica como fallback pros que não leem filename*.
        $safeName = str_replace(['"', "\r", "\n"], '', $originalName);

        return (new Response())
            ->header('Content-Type', $mime)
            ->header(
                'Content-Disposition',
                'attachment; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($originalName)
            )
            ->header('Content-Length', (string) filesize($abs))
            // Conteúdo privado enviado por um visitante anônimo (pode ser
            // PII — currículo, documento) — nunca cacheado por proxy/CDN.
            ->header('Cache-Control', 'private, no-store')
            ->stream(function () use ($abs): void {
                readfile($abs);
            });
    }
}
