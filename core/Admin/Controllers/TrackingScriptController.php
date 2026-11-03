<?php

declare(strict_types=1);

namespace Velk\Admin\Controllers;

use Velk\Models\TrackingScript;
use Velk\Request;
use Velk\Response;

/**
 * CRUD dos scripts de rastreamento em /admin/scripts.
 *
 * Tela própria, e não uma página de opções, porque script deixou de ser um
 * bloco de texto e virou entidade: tem nome, posição, categoria de
 * consentimento, ordem e estado. Cardinalidade N com identidade própria é o
 * mesmo critério que levou `form_settings` a ser tabela.
 *
 * Espelha LanguageController: index / create / edit / delete, mais o
 * `reorder`, que é o endpoint do arrasto e não tem equivalente lá.
 */
final class TrackingScriptController extends Controller
{
    public function index(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;

        // Agrupa por posição: cada grupo tem ordem independente, e é isso que
        // permite arrastar sem que uma linha caia num grupo ao qual não
        // pertence (ver initSortableTable no admin.js).
        $grouped = array_fill_keys(TrackingScript::LOCATIONS, []);
        foreach (TrackingScript::all() as $script) {
            $grouped[$script->location][] = $script;
        }

        // Conversão de cadastro continua guardada no grupo `tracking` da
        // tabela de opções — é onde MemberController já lê
        // (`option('tracking.signup_snippet')`), e mover a chave exigiria
        // migração de dado para ganhar nada. O que mudou é só quem edita:
        // esta tela, em vez de uma página de opções que deixou de existir.
        $tracking = \Velk\Models\Option::getGroup('tracking');

        return $this->render('scripts/index', [
            'grouped' => $grouped,
            // EFETIVAS, não declaradas: sem esta distinção a tabela marca
            // `_optional_on`/`_optional_off` como categoria inexistente e
            // avisa que o script não carrega — enquanto a emissão, que usa as
            // efetivas, carrega normalmente. A tela mentiria sobre o estado.
            'categories' => $this->app->config->effectiveConsentCategories(),
            'consentEnabled' => $this->app->config->isConsentEnabled(),
            'signupSendEmail' => !empty($tracking['signup_send_email']),
            'signupSnippet' => (string) ($tracking['signup_snippet'] ?? ''),
            'membersEnabled' => $this->app->config->isMembersEnabled(),
            // O modo da CSP do site: no estrito, parte do que se cola aqui
            // deixa de rodar, e a tela avisa (scripts/_csp_notice.php).
            'csp' => \Velk\PublicCsp::settings($this->app->config),
        ]);
    }

    /**
     * Salva o acordeão de conversão de cadastro.
     *
     * Grava direto no grupo `tracking` via Option::setGroup, que substitui o
     * grupo inteiro — por isso as DUAS chaves vão juntas em toda gravação.
     * Escrever só uma apagaria a outra, que é exatamente a armadilha
     * documentada no upgrade-notes.
     */
    public function saveConversions(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;
        $csrf = $this->verifyCsrfOrFail($request);
        if ($csrf !== null) return $csrf;

        \Velk\Models\Option::setGroup('tracking', [
            'signup_send_email' => !empty($request->post['signup_send_email']),
            'signup_snippet' => (string) ($request->post['signup_snippet'] ?? ''),
        ]);

        $this->app->session->flash('success', __('scripts.flash_conversions_saved'));
        return Response::redirect(admin_url('scripts'));
    }

    public function create(Request $request): Response
    {
        return $this->editOrCreate($request, null);
    }

    public function edit(Request $request, array $params): Response
    {
        return $this->editOrCreate($request, (int) ($params['id'] ?? 0));
    }

    private function editOrCreate(Request $request, ?int $id): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;

        $script = $id !== null ? TrackingScript::find($id) : null;
        if ($id !== null && $script === null) {
            return Response::notFound(__('scripts.error_not_found'));
        }

        // Efetivas, não declaradas: o select oferece as implícitas quando o
        // tema não declara nenhuma, e a validação precisa aceitá-las.
        $effective = $this->app->config->effectiveConsentCategories();
        $categories = $this->app->config->consentCategories();
        $values = [
            'label' => $script->label ?? '',
            'code' => $script->code ?? '',
            'location' => $script->location ?? 'head',
            'category' => $script->category ?? '',
            'enabled' => $script === null ? true : $script->enabled,
        ];
        $errors = [];

        if ($request->isPost()) {
            $csrf = $this->verifyCsrfOrFail($request);
            if ($csrf !== null) return $csrf;

            $values['label'] = trim((string) ($request->post['label'] ?? ''));
            $values['code'] = (string) ($request->post['code'] ?? '');
            $values['location'] = (string) ($request->post['location'] ?? 'head');
            $values['enabled'] = !empty($request->post['enabled']);

            // O select de categoria só vale se o checkbox estiver marcado.
            // Ele fica ESCONDIDO, não desabilitado, então continua enviando
            // valor no POST — sem esta guarda, desmarcar o consentimento
            // deixaria a categoria antiga gravada e o script seguiria
            // bloqueado, contrariando o que a tela mostra.
            $values['category'] = !empty($request->post['requires_consent'])
                ? (string) ($request->post['category'] ?? '')
                : '';

            if ($values['label'] === '') {
                $errors['label'] = __('scripts.error_label_required');
            }
            if (trim($values['code']) === '') {
                $errors['code'] = __('scripts.error_code_required');
            }
            if (!in_array($values['location'], TrackingScript::LOCATIONS, true)) {
                $errors['location'] = __('scripts.error_invalid_location');
            }
            // Categoria em branco é válida e é o padrão: significa
            // "necessário, sempre carrega". Inválida é apontar para uma
            // categoria que o tema não declara — aí o script não sairia em
            // lugar nenhum, nem cru nem inerte.
            if ($values['category'] !== '' && !isset($effective[$values['category']])) {
                $errors['category'] = __('scripts.error_unknown_category');
            }

            if ($errors === []) {
                if ($script === null) {
                    TrackingScript::create($values);
                } else {
                    $script->save($values);
                }
                $this->app->session->flash('success', __('scripts.flash_saved'));
                return Response::redirect(admin_url('scripts'));
            }
        }

        return $this->render('scripts/edit', [
            'consentEnabled' => $this->app->config->isConsentEnabled(),
            'script' => $script,
            'values' => $values,
            'errors' => $errors,
            'categories' => $categories,
        ]);
    }

    public function delete(Request $request, array $params): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;
        $csrf = $this->verifyCsrfOrFail($request);
        if ($csrf !== null) return $csrf;

        $script = TrackingScript::find((int) ($params['id'] ?? 0));
        if ($script === null) {
            return Response::notFound(__('scripts.error_not_found'));
        }
        $script->delete();
        $this->app->session->flash('success', __('scripts.flash_deleted'));
        return Response::redirect(admin_url('scripts'));
    }

    /**
     * Endpoint do arrasto: recebe os ids na nova ordem de UM grupo.
     *
     * Responde JSON porque quem chama é o `fetch` do initSortableTable, não
     * um formulário — e ele recarrega a página se a resposta não for ok, para
     * a tela não ficar mostrando uma ordem que o banco não tem.
     */
    public function reorder(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;
        $csrf = $this->verifyCsrfOrFail($request);
        if ($csrf !== null) return $csrf;

        $ids = $request->post['ids'] ?? [];
        if (!is_array($ids)) {
            return Response::json(['error' => 'ids must be a list'], 422);
        }

        TrackingScript::applyOrder(array_map('intval', $ids));
        return Response::json(['ok' => true]);
    }
}
