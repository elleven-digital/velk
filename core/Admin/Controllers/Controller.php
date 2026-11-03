<?php

declare(strict_types=1);

namespace Velk\Admin\Controllers;

use Velk\App;
use Velk\Request;
use Velk\Response;
use Velk\View;

abstract class Controller
{
    /**
     * Defense-in-depth backstop for the admin panel ONLY — never applied to
     * public/theme responses (core/Front/*), which don't extend this class
     * and get their own, configurable policy from Velk\PublicCsp.
     *
     * No 'unsafe-inline' in script-src: every admin script is a file
     * (admin.js, tiptap.js), and what a page hands to it goes in a JSON
     * data block (json_script(), e.g. the JS translations in
     * layout/_js_i18n.php), which the browser stores and never runs. So a
     * script injected through an escaping bug doesn't execute. No inline
     * <script>, no on…= attribute, no javascript: link in the admin —
     * tests/unit/admin_csp_script_test.php holds the line. style-src keeps
     * 'unsafe-inline' on purpose: ~170 inline `style=` attributes, and
     * injected CSS reaches far less than injected JS. Audited core/Admin/views + assets:
     * no CDN or external origin is ever loaded — admin.css/admin.js/
     * tiptap.js/fonts are all self-hosted via admin_asset(), every
     * fetch() in admin.js targets a same-origin admin_url() path, and the
     * media-picker iframe's src is a same-origin `${ADMIN_BASE}/media...`
     * URL. `img-src data:` is a conservative allowance (tiptap's image
     * extension ships with allowBase64 off, so it isn't exercised today).
     * `form-action 'self'`: with scripts blocked, the way left to capture a
     * typed password through injected HTML is a fake form ("your session
     * expired, sign in again") posting it off-site. form-action does NOT
     * fall back to default-src, so it has to be named. Every admin form
     * posts to the admin, and the app never answers an admin POST with an
     * off-site redirect — the browser applies form-action to that redirect
     * too, so a future admin flow that hands off to another origin (a
     * payment provider, OAuth) needs its origin listed here.
     * Escaping (e()/htmlspecialchars) remains the primary XSS defense;
     * this header is the second layer, so a script that slips past an
     * escaping bug still doesn't run.
     */
    private const ADMIN_CSP = "default-src 'self'; "
        . "script-src 'self'; "
        . "style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data:; "
        . "font-src 'self'; "
        . "object-src 'none'; "
        . "base-uri 'self'; "
        . "form-action 'self'; "
        . "frame-ancestors 'self'";

    protected App $app;
    protected View $view;

    public function __construct()
    {
        $this->app = App::instance();
        $this->view = new View($this->app->config->path('core') . '/Admin/views');
        $this->view->share('config', $this->app->config);
        $this->view->share('app', $this->app);

        $user = $this->app->auth->user();
        // Abrir uma tela do painel é usar o painel: renova o prazo de
        // inatividade do admin (ADMIN_SESSION_LIFETIME). O site público e a
        // prévia só perguntam se há admin — não contam.
        $this->app->auth->touch();
        // `authUser`, não `user`: renderFile() faz array_merge($shared, $data),
        // então o dado da página vence o compartilhado. Uma tela que passa
        // 'user' com o registro que ela edita — users/edit.php — sobrescrevia
        // o usuário logado e a barra lateral mostrava a pessoa errada, ou
        // nenhuma em /admin/users/new. Um nome que ninguém usa por acidente
        // resolve na raiz, sem depender de cada tela evitar a colisão.
        $this->view->share('authUser', $user);

        // Active admin locale for this request: authenticated user's
        // preference, else the configured default. Covers pre-login pages
        // too (user is null there → falls straight to the default). Before
        // the flashes: a flash stored as a code is translated below, in the
        // language of the screen that shows it.
        \Velk\App::instance()->adminI18n->setCurrent(
            $user?->locale ?: (string) \Velk\App::instance()->config->get('admin.locale', 'en')
        );

        $this->view->share('flash_success', $this->app->session->getFlash('success'));
        $flashError = $this->app->session->getFlash('error');
        $flashErrorCode = $this->app->session->getFlash('error_code');
        $this->view->share('flash_error', $flashError ?? self::flashErrorText($flashErrorCode));
        $this->view->share('flash_warnings', (array) $this->app->session->getFlash('warnings', []));
    }

    /**
     * Texto de um flash de erro gravado como CÓDIGO (`error_code`), no idioma
     * da tela que o mostra. Um código e não a frase porque quem grava nem
     * sempre é quem mostra: a falha de CSRF de um admin deslogado redireciona
     * para o login, que fala o idioma padrão, não o do usuário de antes.
     */
    private static function flashErrorText(mixed $code): ?string
    {
        return match ($code) {
            'session_expired' => __('flash.session_expired_resubmit'),
            'session_idle' => __('flash.session_idle'),
            default => null,
        };
    }

    /**
     * Avisa, na próxima tela, das colisões de endereço que o save criou ou
     * encontrou num idioma (Velk\Admin\SlugConflicts): quem ficou sem
     * endereço e quem o tem. Não impede o save.
     *
     * @param list<array{label: string, url: string, lost: bool, others: list<string>}> $conflicts
     */
    protected function flashSlugConflicts(array $conflicts): void
    {
        if ($conflicts === []) return;
        $messages = [];
        foreach ($conflicts as $c) {
            // __raw: a view escapa a mensagem ao imprimir; __() escaparia os
            // títulos antes, e "Q&A" sairia "Q&amp;A".
            $messages[] = __raw($c['lost'] ? 'slugs.conflict_lost' : 'slugs.conflict_taken', [
                'language' => $c['label'],
                'url' => $c['url'],
                'others' => implode('”, “', $c['others']),
            ]);
        }
        $this->app->session->flash('warnings', $messages);
    }

    protected function render(string $template, array $data = [], string $layout = 'layout/main'): Response
    {
        $content = $this->view->render($template, $data);
        $body = $this->view->render($layout, array_merge($data, ['_content' => $content]));
        return Response::html($body)->header('Content-Security-Policy', self::ADMIN_CSP);
    }

    protected function requireAuth(Request $request): ?Response
    {
        if (!$this->app->auth->check()) {
            // A query string de um GET volta junto depois do login: é ela que
            // carrega o recorte de uma lista filtrada e as escolhas de uma
            // exportação salva — sem ela, o link reaberto com a sessão vencida
            // exportava todas as colunas de todas as datas. Só GET: um POST sem
            // sessão não se refaz por redirect. O caminho já vem normalizado e
            // http_build_query() reescreve cada par (CR/LF viram %0D%0A), então
            // o destino continua sendo um endereço deste site.
            $intended = $request->path;
            if ($request->isGet() && $request->query !== []) {
                $intended .= '?' . http_build_query($request->query);
            }
            $this->app->session->put('intended', $intended);
            // Caiu agora por inatividade: o login diz por quê, em vez de só
            // aparecer do nada.
            if ($this->app->auth->expiredThisRequest()) {
                $this->app->session->flash('error_code', 'session_idle');
            }
            return Response::redirect(admin_url('login'));
        }
        return null;
    }

    /**
     * Merge freshly-submitted per-language translation values into the
     * EXISTING stored map, touching ONLY the languages present in
     * $updates. Shared by Option/Item/Term/Page controllers so a save
     * never wipes a language's translation just because that language's
     * panel wasn't part of THIS particular submission (disabled at
     * render time, or a trimmed/tampered POST missing that language's
     * fields entirely).
     *
     * Callers build $updates by iterating the enabled languages and
     * including ONLY the ones actually present in the POST (via
     * array_key_exists on the relevant $request->post[...] array) —
     * that's what distinguishes "this language's tab wasn't submitted,
     * leave its stored value alone" from "this language's tab was
     * submitted blank, clear it". A language absent from $updates is
     * left byte-for-byte as it was in $existing; one present with value
     * `null` is REMOVED from the result (the established "empty tab =
     * untranslated, no row at all" contract — see AGENTS.md, "i18n Phase 3"
     * section); any other value replaces that language's entry.
     *
     * @param array<string,mixed> $existing
     * @param array<string,mixed|null> $updates
     * @return array<string,mixed>
     */
    protected static function mergeTranslations(array $existing, array $updates): array
    {
        $merged = $existing;
        foreach ($updates as $code => $value) {
            if ($value === null) {
                unset($merged[$code]);
            } else {
                $merged[$code] = $value;
            }
        }
        return $merged;
    }

    /**
     * Normaliza a lista de ids de uma exclusão em massa (`$_POST['ids']`).
     * Público, estático, puro, nunca lança. Aceita array (qualquer outra coisa vira
     * []); descarta valor não-numérico, zero e negativo; converte pra int;
     * deduplica; corta em $max preservando a ordem de primeira ocorrência.
     *
     * O teto não é uma defesa de segurança — a checagem de escopo no laço
     * do controller já garante que só dá pra apagar o que o usuário
     * poderia apagar um a um. É só robustez contra um POST absurdo.
     *
     * @param int $max
     * @return list<int>
     */
    public static function parseBulkIds(mixed $raw, int $max = 200): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $part) {
            if (!is_numeric($part)) {
                continue;
            }
            $id = (int) $part;
            if ($id <= 0 || in_array($id, $ids, true)) {
                continue;
            }
            $ids[] = $id;
            if (count($ids) >= $max) {
                break;
            }
        }
        return $ids;
    }

    protected function verifyCsrfOrFail(Request $request): ?Response
    {
        if (!$request->isPost()) {
            return null;
        }
        $token = (string) ($request->post['_csrf'] ?? '');
        if (!$this->app->session->verifyCsrf($token)) {
            // AJAX endpoints consume res.json() — HTML here would become
            // an "Unexpected token" error on the frontend instead of a
            // useful message.
            //
            // Status 403, not the non-standard 419: Apache + mod_php
            // downgrades http_response_code(419) to a raw 500 (it's not in
            // Apache's status table), turning a routine "please reload"
            // into an ugly server error. 403 is registered, Apache serves
            // it correctly, and it's semantically right for a rejected
            // CSRF token.
            //
            // "AJAX" is more than X-Requested-With: a plain fetch() doesn't
            // send it, but browsers mark it Sec-Fetch-Dest: empty. A redirect
            // there would be followed by fetch itself, land on an HTML page
            // with 200, and the script would read it as a success.
            $dest = (string) ($request->headers['sec-fetch-dest'] ?? '');
            if ($request->isAjax() || $request->wantsJson() || $dest === 'empty') {
                return Response::json(['error' => __('flash.session_expired')], 403)
                    ->header('Content-Security-Policy', self::ADMIN_CSP);
            }
            // A form posted into an iframe (the block window of the text
            // editor): redirecting to the Referer would load the whole editor
            // inside the window. A short page there asks to close and reload.
            if ($dest === 'iframe') {
                return $this->render('auth/session-expired-window', [], 'layout/embed')->status(403);
            }
            return $this->sessionExpiredRedirect($request);
        }
        return null;
    }

    /**
     * Falha de CSRF num POST comum: volta para a tela de onde veio, com o
     * flash do código `session_expired` e um token novo — o redirect do
     * CodeIgniter 4, a página 419 do Laravel. Antes era uma página crua
     * "CSRF token mismatch." (403), e o caso real é quase sempre a sessão que
     * morreu com o formulário aberto: o login deixado aberto, o editor de uma
     * página. Recarregar resolvia; agora o servidor recarrega.
     *
     * Destino:
     *  - sem ninguém logado, o login — é lá que a pessoa precisa ir;
     *  - logado, o Referer, só se for do próprio site (refererSameHost: um
     *    header que o cliente controla não vira redirect para fora);
     *  - logado sem Referer válido, o painel — o login redirecionaria quem
     *    está logado para o painel, e o flash se perderia no caminho.
     *
     * 303: depois de um POST, o navegador segue com GET. O token novo já fica
     * na sessão; a tela de destino o imprime no formulário. O que foi digitado
     * se perde — o mesmo que recarregar, só que sem a tela de erro no meio.
     */
    private function sessionExpiredRedirect(Request $request): Response
    {
        $session = $this->app->session;
        $session->flash('error_code', 'session_expired');
        $session->csrfToken();

        $target = !$this->app->auth->check()
            ? admin_url('login')
            : ($request->refererSameHost() ?? admin_url(''));

        return Response::redirect($target, 303)
            ->header('Content-Security-Policy', self::ADMIN_CSP);
    }
}
