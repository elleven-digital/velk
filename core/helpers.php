<?php

declare(strict_types=1);

use Velk\App;
use Velk\Config;
use Velk\Models\Item;
use Velk\Models\Term;
use Velk\Response;
use Velk\TemplateContext;

if (!function_exists('e')) {
    /**
     * Escape HTML — the default for ALL output in templates.
     */
    function e(mixed $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('json_script')) {
    /**
     * Dados para o JavaScript da página, num bloco que o navegador guarda e
     * não executa: `<script type="application/json" id="…">`, lido com
     * `JSON.parse(document.getElementById(id).textContent)`. É o que deixa
     * o painel rodar sem `'unsafe-inline'` no CSP (o molde é o `json_script`
     * do Django).
     *
     * `<`, `>`, `&` e as aspas saem como `\u003C`…: um `</script>` num valor
     * não fecha o bloco antes da hora, e o JSON.parse devolve o texto original.
     * Um byte inválido de UTF-8 vira o caractere de substituição.
     */
    function json_script(string $id, mixed $data): string
    {
        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                | JSON_INVALID_UTF8_SUBSTITUTE
        );
        // Um valor que o JSON não representa (NAN, INF, um resource) deixaria
        // o bloco vazio, e o JSON.parse de quem o lê lançaria. Sai `null`, e
        // o log diz o porquê.
        if ($json === false) {
            error_log('[Velk] json_script("' . $id . '"): ' . json_last_error_msg());
            $json = 'null';
        }
        return '<script type="application/json" id="' . e($id) . '">' . $json . '</script>';
    }
}

if (!function_exists('csp_nonce')) {
    /**
     * A senha (nonce) desta página, para todo `<script>` do tema:
     * `<script nonce="<?= csp_nonce() ?>">`, inclusive os com `src=`. No modo
     * `strict` da CSP (`security.csp.mode` no site.json) o navegador só roda
     * o script que a leva; nos outros modos o atributo é inofensivo. Mesmo
     * valor na página inteira.
     */
    function csp_nonce(): string
    {
        return \Velk\PublicCsp::nonce();
    }
}

if (!function_exists('csp_nonce_scripts')) {
    /**
     * Põe a senha da página em toda abertura `<script` do HTML que ainda não
     * tem uma — para um trecho de HTML de terceiros que o tema imprime.
     */
    function csp_nonce_scripts(string $html): string
    {
        return \Velk\PublicCsp::nonceScripts($html);
    }
}

if (!function_exists('is_safe_url')) {
    /**
     * True quando a URL é segura para virar um href/src de conteúdo:
     * esquemas http/https/mailto/tel, ou qualquer URL SEM esquema
     * (relativa, âncora, query, path, protocol-relative //host).
     * Rejeita javascript:/data:/vbscript:/file:/etc — o vetor de XSS
     * dos campos de URL editáveis. Espelha o esquema-allowlist que o
     * sanitizador de SVG (MediaService) já aplica em href/xlink:href.
     *
     * Tab/newline/CR são removidos e o controle/espaço à esquerda é
     * trimado ANTES de extrair o esquema, porque os browsers ignoram
     * esses caracteres — sem isso, "java\tscript:" burlaria o filtro.
     */
    function is_safe_url(string $url): bool
    {
        $u = str_replace(["\t", "\n", "\r"], '', $url);
        $u = ltrim($u, " \x00..\x20");
        // Sem esquema (não há "esquema:" antes de /, ?, #) ⇒ relativa ⇒ segura.
        if (!preg_match('#^([a-z][a-z0-9+.\-]*):#i', $u, $m)) {
            return true;
        }
        return in_array(strtolower($m[1]), ['http', 'https', 'mailto', 'tel'], true);
    }
}

if (!function_exists('safe_url')) {
    /**
     * Devolve a URL quando ela é segura para virar um href/src (ver
     * is_safe_url), ou '' quando não é. Feito para o TEMA envolver
     * qualquer URL editável renderizada como atributo:
     *   <a href="<?= e(safe_url($cta_url)) ?>">
     * Um valor `javascript:` vira '', neutralizando o stored-XSS na
     * origem do render. Aceita mixed (option()/field() podem devolver
     * null); não-string ⇒ ''.
     */
    function safe_url(mixed $url): string
    {
        if (!is_string($url) || $url === '') {
            return '';
        }
        return is_safe_url($url) ? $url : '';
    }
}

if (!function_exists('app')) {
    function app(): App
    {
        return App::instance();
    }
}

if (!function_exists('config')) {
    function config(?string $key = null, mixed $default = null): mixed
    {
        $config = App::instance()->config;
        if ($key === null) {
            return $config;
        }
        return $config->get($key, $default);
    }
}

if (!function_exists('site')) {
    function site(?string $key = null, mixed $default = null): mixed
    {
        return App::instance()->config->site($key, $default);
    }
}

if (!function_exists('option')) {
    /**
     * Read a value from an options page (defined in `site.json → options`).
     * Supports dot paths into nested fields:
     *
     *   option('contact.email')                  // string
     *   option('contact.social.0.url')           // string from a repeater row
     *   option('footer.copyright', 'Default')    // with fallback
     */
    function option(string $path, mixed $default = null): mixed
    {
        $app = \Velk\App::instance();
        if (!$app->config->isI18nEnabled() || $app->i18n->current() === $app->config->defaultLanguage()) {
            return \Velk\Models\Option::getPath($path, $default);
        }
        return \Velk\Models\Option::getPathIn($path, $app->i18n->current(), $default);
    }
}

if (!function_exists('options')) {
    /**
     * Get the full values array for an options page key, in the active
     * language — each field falls back to the default language when it has
     * no translation, exactly like option(). Useful when you want to iterate
     * all fields:
     *
     *   foreach (options('footer') as $name => $value) { ... }
     */
    function options(string $key): array
    {
        $app = \Velk\App::instance();
        if (!$app->config->isI18nEnabled() || $app->i18n->current() === $app->config->defaultLanguage()) {
            return \Velk\Models\Option::getGroup($key);
        }
        return \Velk\Models\Option::getGroupIn($key, $app->i18n->current());
    }
}

if (!function_exists('base_path')) {
    /**
     * URL prefix for subdirectory installations. Returns "" when Velk is at host root.
     */
    function base_path(): string
    {
        return App::instance()->basePath;
    }
}

if (!function_exists('asset')) {
    /**
     * Root-relative URL for a static file (CSS/JS/images at the project
     * root or under `theme/`) or an engine-served resource like
     * `/theme/...` and `/uploads/...`. Adds the install base path.
     */
    function asset(string $path): string
    {
        return base_path() . '/' . ltrim($path, '/');
    }
}

if (!function_exists('url')) {
    /**
     * Root-relative URL for an internal route. Always includes the install
     * base path. Use `absolute_url()` when you need scheme + host included.
     *
     * When i18n is enabled and the current language has a URL prefix,
     * the prefix is automatically inserted between the base and the
     * path — so `url('/sobre')` renders as `/en/sobre` while viewing
     * in English. Pass an explicit `$language` to build URLs for a
     * specific language (used by language switchers).
     */
    function url(string $path = '/', ?string $language = null): string
    {
        $app = \Velk\App::instance();
        $base = base_path();
        $clean = '/' . ltrim($path, '/');

        if ($app->config->isI18nEnabled()) {
            $lang = $language ?? $app->i18n->current();
            if ($lang !== $app->config->defaultLanguage()) {
                $row = \Velk\Models\Language::find($lang);
                $prefix = $row !== null ? $row->urlPrefix : null;
                if ($prefix !== null && $prefix !== '') {
                    $base .= $prefix;
                }
            }
        }

        if ($clean === '/') {
            return $base === '' ? '/' : $base . '/';
        }
        return $base . $clean;
    }
}

if (!function_exists('absolute_url')) {
    /**
     * Absolute URL (scheme + host) for an internal route. Reads `app.url`
     * from env. Falls back to a relative URL when `app.url` is empty.
     */
    function absolute_url(string $path = '/'): string
    {
        $host = rtrim((string) App::instance()->config->get('app.url', ''), '/');
        return $host . url($path);
    }
}

if (!function_exists('app_origin')) {
    /**
     * Origin (scheme + host, no path) for absolute URLs that MUST be real
     * and absolute no matter what — payment-gateway redirect targets,
     * emailed links, XML feeds. Reads `app.url` (APP_URL — origin ONLY,
     * e.g. "https://example.com"; never a subdirectory, see
     * APP_BASE_PATH in .env.example for that, added separately by
     * url()/absolute_path_url()). Falls back to the current request's
     * scheme + Host header when APP_URL is unset, so these still work
     * before `.env` is configured.
     *
     * Deliberately NOT the same fallback as absolute_url(), which
     * degrades to a root-relative URL when APP_URL is empty — harmless
     * for an in-page template link, but wrong here: a Stripe redirect
     * URL or an emailed link needs a real host regardless of config.
     */
    function app_origin(): string
    {
        $configured = rtrim((string) App::instance()->config->get('app.url', ''), '/');
        if ($configured !== '') {
            return $configured;
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        return $scheme . '://' . $host;
    }
}

if (!function_exists('absolute_path_url')) {
    /**
     * Absolute URL for a FIXED route path — an infra endpoint registered
     * directly on the Router at exactly one path regardless of the
     * active language (Stripe success/cancel, member password-reset
     * callback, webhook endpoints). Same origin+base-path convention as
     * absolute_url()/url() for subdirectory installs (exactly one
     * APP_BASE_PATH segment, whether or not APP_URL is configured), but
     * — unlike url() — never inserts an i18n URL prefix: these routes
     * aren't registered per-language, so a prefixed URL would 404.
     * Mirrors how member_route()/store_checkout_url() already build the
     * root-relative form of these same paths (basePath, no i18n).
     */
    function absolute_path_url(string $path): string
    {
        $defaultLang = App::instance()->config->defaultLanguage();
        return app_origin() . url($path, $defaultLang);
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = '/'): string
    {
        $prefix = '/' . trim((string) App::instance()->config->get('admin.prefix', '/admin'), '/');
        return base_path() . $prefix . '/' . ltrim($path, '/');
    }
}

if (!function_exists('absolute_admin_url')) {
    /**
     * Absolute URL for an admin-panel route — same origin+base-path
     * convention as absolute_path_url(), with the configurable
     * ADMIN_PREFIX added on top (via admin_url()). For links that leave
     * the request/response cycle entirely (emailed password-reset
     * links), where a root-relative admin_url() would be meaningless.
     */
    function absolute_admin_url(string $path = '/'): string
    {
        return app_origin() . admin_url($path);
    }
}

if (!function_exists('admin_asset')) {
    /**
     * Versioned URL for an admin engine asset (`css/admin.css`, `js/admin.js`).
     * Appends `?v=<mtime>` so the browser never serves a stale admin bundle
     * after an engine upgrade — AssetController's long Cache-Control stays,
     * but the URL changes whenever the file changes (automatic cache-bust).
     */
    function admin_asset(string $path): string
    {
        $path = ltrim($path, '/');
        $url = admin_url('__static/' . $path);
        $file = App::instance()->config->path('core') . '/Admin/assets/' . $path;
        $mtime = is_file($file) ? filemtime($file) : false;
        return $mtime !== false ? $url . '?v=' . $mtime : $url;
    }
}

if (!function_exists('item')) {
    /**
     * Resolve o valor de um campo `item` (um id) para o objeto `Item`,
     * ou null se vazio/inválido/apagado. Espelha o idiom do image_url():
     * resolve aqui, e o tema checa antes de usar (e checa ->status se
     * quiser esconder não-publicados). Lookup só acontece para id > 0.
     */
    function item(mixed $id): ?\Velk\Models\Item
    {
        $id = is_numeric($id) ? (int) $id : 0;
        if ($id <= 0) {
            return null;
        }
        return \Velk\Models\Item::find($id);
    }
}

if (!function_exists('link_tag')) {
    /**
     * Renderiza um campo `link` (do FieldRenderer) como uma tag <a> segura.
     * Tudo escapado com e(). new_tab ⇒ target="_blank" rel="noopener noreferrer".
     *
     * @param array<string,mixed>|null $link  Valor salvo: ['title'=>..,'url'=>..,'new_tab'=>..] ou null.
     * @param string|null              $text  Texto override; cai pra title, depois pra url.
     * @param array<string,string>     $attrs Atributos extras (ex.: ['class'=>'btn']).
     */
    function link_tag(?array $link, ?string $text = null, array $attrs = []): string
    {
        $url = is_array($link) ? trim((string) ($link['url'] ?? '')) : '';
        if ($url === '' || !is_safe_url($url)) {
            return '';
        }

        $label = (string) ($text ?? '');
        if ($label === '') {
            $label = (string) ($link['title'] ?? '');
        }
        if ($label === '') {
            $label = $url;
        }

        $attrParts = '';
        foreach ($attrs as $key => $val) {
            $attrParts .= ' ' . e((string) $key) . '="' . e((string) $val) . '"';
        }

        $target = !empty($link['new_tab']) ? ' target="_blank" rel="noopener noreferrer"' : '';

        return sprintf('<a href="%s"%s%s>%s</a>', e($url), $target, $attrParts, e($label));
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return App::instance()->session->csrfToken();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('form_url')) {
    /**
     * Action URL for a public form. Use in templates:
     *   <form action="<?= e(form_url('contato')) ?>" method="post">
     */
    function form_url(string $id): string
    {
        return base_path() . '/forms/' . rawurlencode($id);
    }
}

if (!function_exists('form_status')) {
    /**
     * Read and clear the flash status for a form. Returns null when there's
     * no recent submission to report on.
     *
     * Shape:
     *   ['status' => 'success'|'error',
     *    'code'   => string,                     // what happened
     *    'params' => array<string,scalar>,       // only when the code needs data
     *    'message'=> string,                     // ONLY for `submitted`, and
     *                                            // only if the form declares
     *                                            // success_message in site.json
     *    'errors' => array<string,array{code:string,params?:array}>,
     *    'values' => array<string,string>]
     *
     * `code` and not a sentence: the engine reports WHAT happened, the theme
     * decides how to say it. See docs/messages.md for the full vocabulary.
     */
    function form_status(string $id): ?array
    {
        $key = '_form_status_' . $id;
        $app = \Velk\App::instance();
        $value = $app->session->get($key);
        if (!is_array($value)) return null;
        $app->session->forget($key);
        return $value;
    }
}

if (!function_exists('form_honeypot')) {
    /**
     * Convenience helper that emits a hidden honeypot input. Bots tend to
     * fill in every field; humans can't see this one.
     *
     * Junto do campo vai um script mínimo que ESVAZIA o `_hp` no navegador.
     * O motivo é que o honeypot sozinho passou a reprovar gente de verdade:
     * o Chrome ignora `autocomplete="off"` no preenchimento de perfil e,
     * como `_hp` não significa nada para ele, classifica o campo pela
     * POSIÇÃO dentro do formulário — um `_hp` renderizado antes de
     * nome/e-mail/telefone é preenchido como se fosse o primeiro campo real.
     * Gerenciadores de senha e extensões preenchem direto no DOM, onde
     * `autocomplete` nem é consultado.
     *
     * Com o script, quem roda um navegador de verdade nunca mais cai. O bot
     * que posta HTTP cru e preencheu o campo continua caindo — e esse era o
     * único caso que o honeypot realmente pegava, porque bot com navegador
     * já passava antes.
     *
     * O script sai UMA vez por request, quantos formulários a página tenha:
     * os listeners ficam no `document` e valem para todos.
     */
    function form_honeypot(): string
    {
        $field = '<input type="text" name="_hp" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;height:0;width:0;opacity:0">';

        static $scriptEmitted = false;
        if ($scriptEmitted) {
            return $field;
        }
        $scriptEmitted = true;

        // Sem dependência e sem estilo: o core resolve o mecanismo, o tema
        // continua dono da aparência.
        $script = <<<'JS'
(function () {
    var NAME = '_hp';

    // Atribuir .value por script NÃO dispara input/change, então limpar
    // aqui não realimenta este mesmo listener.
    function clean(el) {
        if (el && el.name === NAME && el.value !== '') {
            el.value = '';
        }
    }

    // Autofill dispara input/change no campo que preencheu: limpa na hora.
    // É o que cobre também quem envia por form.submit(), que não dispara
    // evento de submit nenhum.
    document.addEventListener('input', function (e) { clean(e.target); }, true);
    document.addEventListener('change', function (e) { clean(e.target); }, true);

    // Rede de segurança, em CAPTURA de propósito: roda antes de qualquer
    // handler do próprio formulário, então um tema que envia por fetch monta
    // o FormData com o campo já limpo.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.querySelectorAll) {
            return;
        }
        var fields = form.querySelectorAll('input[name="' + NAME + '"]');
        for (var i = 0; i < fields.length; i++) {
            fields[i].value = '';
        }
    }, true);
})();
JS;

        return $field . '<script nonce="' . csp_nonce() . '">' . $script . '</script>';
    }
}

if (!function_exists('form_captcha')) {
    /**
     * Widget do Cloudflare Turnstile, para imprimir DENTRO do `<form>`,
     * ao lado do `form_honeypot()`.
     *
     * Devolve string vazia quando `TURNSTILE_SITE_KEY`/`TURNSTILE_SECRET_KEY`
     * não estão no `.env` — então um tema pode chamar isto sempre, e o
     * recurso liga e desliga só pela configuração do site.
     *
     * Nenhum JavaScript do tema é necessário: o script da Cloudflare
     * renderiza o widget e injeta o token num input oculto dentro do
     * formulário sozinho, então um POST comum (ou um `FormData` de
     * `fetch()`) já leva o campo junto.
     *
     * O modo do widget — Managed, Non-interactive ou Invisible — é
     * escolhido no painel da Cloudflare ao criar a chave, não aqui. Por
     * isso não há opção alguma no Velk além das duas variáveis.
     */
    function form_captcha(): string
    {
        if (!\Velk\Services\TurnstileService::isConfigured()) {
            return '';
        }

        // O script é o mesmo para quantos formulários existirem na página;
        // emite uma vez só por request.
        static $scriptEmitted = false;
        $script = '';
        if (!$scriptEmitted) {
            $scriptEmitted = true;
            $script = '<script nonce="' . csp_nonce() . '" src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
        }

        return $script . '<div class="cf-turnstile" data-sitekey="'
            . e(\Velk\Services\TurnstileService::siteKey()) . '"></div>';
    }
}

if (!function_exists('tracking_params')) {
    /**
     * Parâmetros de rastreamento atribuídos à sessão — UTM e click IDs de
     * anúncio —, capturados automaticamente da query string e preservados
     * durante a navegação.
     *
     * Só as chaves de fato capturadas; array vazio numa visita direta. Não
     * devolve mais as 5 UTM com null, como o antigo `utm_params()` fazia:
     * o conjunto agora é aberto (a whitelist de `Velk\Params` cresce sem
     * migration), então um formato fixo deixaria de fazer sentido.
     *
     *   $p = tracking_params();
     *   if (!empty($p['utm_source'])) { ... }
     *   if (!empty($p['gclid'])) { ... }
     *
     * @return array<string, string>
     */
    function tracking_params(): array
    {
        return \Velk\Params::fromSession(\Velk\App::instance()->session);
    }
}

/*
 * Tracking script injection — for GTM, Meta Pixel, GA, chat widgets, etc.
 *
 * Themes call these three helpers at the standard placement points:
 *   - tracking_head()        → just before </head>
 *   - tracking_body_start()  → just after <body ...>
 *   - tracking_body_end()    → just before </body>
 *
 * Content comes from the "Scripts & rastreamento" options page in the admin
 * (a built-in group). Output is intentionally raw — admins paste the exact
 * snippet provided by GTM/Pixel/etc. Empty options produce no output.
 */
if (!function_exists('velk_tracking_emit')) {
    /**
     * Saída de UMA posição, nos dois formatos possíveis.
     *
     * Sem `consent.categories` no site.json, lê o campo único de sempre — os
     * temas existentes não mudam em nada. Com categorias, percorre o
     * repetidor aplicando o gate por linha.
     *
     * Interna (prefixo velk_) porque é detalhe de implementação dos três
     * helpers públicos abaixo, não contrato de tema.
     */
    function velk_tracking_emit(string $location): void
    {
        $config = \Velk\App::instance()->config;
        $rows = \Velk\Models\TrackingScript::forOutput($location);

        // Sem mecanismo de consentimento no tema, TODO script é necessário.
        //
        // Zerar a categoria aqui, e não tratar isso dentro do gate, mantém o
        // Consent puro — ele continua respondendo só "esta categoria está
        // concedida?". E é o que evita o pior caso: um script que ficou com
        // categoria de quando o tema tinha banner sumiria da página como
        // "categoria órfã", em vez de carregar.
        if (!$config->isConsentEnabled()) {
            foreach ($rows as $i => $row) {
                $rows[$i]['category'] = '';
            }
        }

        echo \Velk\Consent::renderScripts(
            $rows,
            $location,
            $config->effectiveConsentCategories(),
            \Velk\Consent::parse($_COOKIE[\Velk\Consent::COOKIE] ?? null)
        );
    }
}
if (!function_exists('tracking_head')) {
    function tracking_head(): void
    {
        // O runtime vem ANTES dos scripts: ele precisa existir quando o
        // primeiro placeholder inerte aparecer, senão a ativação da mesma
        // página depende da ordem de parse.
        //
        // A condição é "existe script sob consentimento", e não "existem
        // categorias": as efetivas nunca são vazias (as implícitas existem
        // sempre), então checar categorias faria todo site carregar o runtime
        // para gatear coisa nenhuma.
        // Duas condições, e a ordem importa: sem mecanismo no tema não há
        // banner para chamar velkConsent, então o runtime seria peso morto —
        // e um script com categoria RESIDUAL (de quando o tema tinha banner)
        // não deve ressuscitá-lo.
        if (\Velk\App::instance()->config->isConsentEnabled()
            && \Velk\Models\TrackingScript::hasConsentGated()) {
            echo \Velk\Consent::renderRuntime(
                \Velk\App::instance()->config->effectiveConsentCategories(),
                \Velk\Consent::parse($_COOKIE[\Velk\Consent::COOKIE] ?? null)
            );
        }

        velk_tracking_emit('head');

        // Conversion events queued by the previous request (form submitted,
        // member registered). Emitted HERE, appended to the helper every
        // theme already calls, so the feature works on existing installs
        // with no theme change at all. Consumed one-shot — a refresh on the
        // thank-you page can't fire the conversion twice.
        //
        // Fora do gate de consentimento de propósito: é o próprio site
        // empurrando dado pro dataLayer, e o que ESCUTA esse push (GTM) é que
        // está sob consentimento. Bloquear aqui esconderia a conversão até de
        // quem aceitou tudo.
        echo \Velk\ClientEvents::flush(\Velk\App::instance()->session);
    }
}
if (!function_exists('tracking_body_start')) {
    function tracking_body_start(): void
    {
        velk_tracking_emit('body_start');
    }
}
if (!function_exists('tracking_body_end')) {
    function tracking_body_end(): void
    {
        velk_tracking_emit('body_end');
    }
}

if (!function_exists('redirect')) {
    function redirect(string $url, int $status = 302): Response
    {
        return Response::redirect($url, $status);
    }
}

if (!function_exists('field')) {
    /**
     * Get a custom field value from the current template context.
     * Pass an explicit context to override the inferred one.
     *
     * i18n-aware: when the active language differs from default and
     * the record has a translation for the field, the translated
     * value is returned. Falls back to the default-language value
     * when no translation exists.
     */
    function field(string $name, mixed $default = null, ?TemplateContext $context = null): mixed
    {
        $context = $context ?? current_context();
        if ($context === null || $context->record === null) {
            return $default;
        }

        $record = $context->record;
        $app = \Velk\App::instance();

        // Hot path: i18n disabled or current is default → direct read
        // from the model's default-language `field()` method. Existing
        // behavior, zero overhead for mono-language sites.
        if (!$app->config->isI18nEnabled() || $app->i18n->current() === $app->config->defaultLanguage()) {
            if (is_object($record) && method_exists($record, 'field')) {
                $value = $record->field($name);
                return $value ?? $default;
            }
            return $default;
        }

        // i18n + non-default: prefer fieldIn() which handles fallback.
        // All translatable models (Item, Term, Page) implement it.
        if (is_object($record) && method_exists($record, 'fieldIn')) {
            $value = $record->fieldIn($name, $app->i18n->current());
            return $value ?? $default;
        }
        // Older model without fieldIn — read default-language field.
        if (is_object($record) && method_exists($record, 'field')) {
            $value = $record->field($name);
            return $value ?? $default;
        }
        return $default;
    }
}

if (!function_exists('the_title')) {
    /**
     * Title for the current record. i18n-aware: prefers titleIn() /
     * nameIn() (per-language with fallback) over the raw title / name
     * properties. Items/Pages have `titleIn`, Terms have `nameIn`.
     */
    function the_title(?TemplateContext $context = null): string
    {
        $context = $context ?? current_context();
        if ($context === null || $context->record === null) {
            return '';
        }
        $record = $context->record;
        $app = \Velk\App::instance();
        $lang = $app->config->isI18nEnabled() ? $app->i18n->current() : $app->config->defaultLanguage();

        if (is_object($record)) {
            if (method_exists($record, 'titleIn')) {
                return $record->titleIn($lang);
            }
            if (method_exists($record, 'nameIn')) {
                return $record->nameIn($lang);
            }
            if (property_exists($record, 'title')) {
                return (string) $record->title;
            }
            if (property_exists($record, 'name')) {
                return (string) $record->name;
            }
        }
        return '';
    }
}

if (!function_exists('the_slug')) {
    function the_slug(?TemplateContext $context = null): string
    {
        $context = $context ?? current_context();
        if ($context === null || $context->record === null) {
            return '';
        }
        $record = $context->record;
        if (is_object($record) && property_exists($record, 'slug')) {
            return (string) $record->slug;
        }
        return '';
    }
}

if (!function_exists('the_url')) {
    function the_url(?TemplateContext $context = null): string
    {
        $context = $context ?? current_context();
        if ($context === null || $context->record === null) {
            return '/';
        }
        $record = $context->record;
        if ($record instanceof Item) {
            return $record->url();
        }
        if ($record instanceof Term) {
            return $record->url();
        }
        return '/';
    }
}

if (!function_exists('items')) {
    /**
     * Query items for a given type. Returns array of Item objects.
     *
     * @param array{limit?:int, offset?:int, status?:string, term?:int, order?:string} $args
     * @return list<Item>
     */
    function items(string $type, array $args = []): array
    {
        return Item::query($type, $args);
    }
}

if (!function_exists('terms')) {
    /**
     * Get all terms for a taxonomy.
     *
     * @return list<Term>
     */
    function terms(string $taxonomy): array
    {
        return Term::all($taxonomy);
    }
}

if (!function_exists('the_terms')) {
    /**
     * Get terms for the current item (or a given item).
     *
     * @return list<Term>
     */
    function the_terms(string $taxonomy, ?TemplateContext $context = null): array
    {
        $context = $context ?? current_context();
        if ($context === null || !($context->record instanceof Item)) {
            return [];
        }
        return $context->record->terms($taxonomy);
    }
}

if (!function_exists('current_context')) {
    function current_context(): ?TemplateContext
    {
        $stack = $GLOBALS['_ELLEV_CONTEXT_STACK'] ?? [];
        return end($stack) ?: null;
    }
}

if (!function_exists('with_context')) {
    /**
     * Push a template context for the duration of a callable.
     * Used internally by the front renderer when looping over items.
     */
    function with_context(TemplateContext $context, callable $callback): mixed
    {
        $GLOBALS['_ELLEV_CONTEXT_STACK'][] = $context;
        try {
            return $callback();
        } finally {
            array_pop($GLOBALS['_ELLEV_CONTEXT_STACK']);
        }
    }
}

if (!function_exists('partial')) {
    function partial(string $name, array $data = []): void
    {
        $config = App::instance()->config;
        $base = $config->path('theme') . '/partials';
        $file = $base . '/' . ltrim($name, '/');
        if (!str_ends_with($file, '.php')) {
            $file .= '.php';
        }
        if (!is_file($file)) {
            throw new \RuntimeException("Partial not found: {$name}");
        }
        $merged = $data;
        (function (array $__data, string $__file): void {
            extract($__data, EXTR_SKIP);
            include $__file;
        })($merged, $file);
    }
}

if (!function_exists('richtext')) {
    /**
     * O HTML de um campo richtext, com cada bloco do tema trocado pela saída
     * do partial dele: `<?= richtext(field('content')) ?>` — saída sem
     * escape, como a de field() num richtext (os exemplos do AGENTS.md
     * marcam com o comentário trusted).
     *
     * O partial é o arquivo que o site.json declara para o bloco (`file`), em
     * `theme/partials/richtext/`, e recebe cada campo do bloco como variável,
     * igual ao `partial()`. O dado gravado passa pela normalização do
     * formulário (FieldRenderer::collect): um campo que
     * falta — o bloco, ou um repetidor dele, ganhou um campo depois — chega
     * vazio (texto `null`, marcação `false`, lista vazia), e uma lista
     * (repeater, flexible, gallery) nunca chega como outra coisa: o
     * `foreach` do partial não quebra num post antigo.
     *
     * No lugar de um marcador que não dá para imprimir — tipo fora do
     * site.json, chave inválida, dados quebrados, partial ausente — não sai
     * nada; com APP_DEBUG ligado, o motivo vai para o log. Sem este helper o
     * marcador é só uma `<div>` vazia: o bloco não aparece, e nada quebra.
     *
     * Um valor que não é texto — o dado antigo de um campo que mudou de tipo,
     * um repetidor que virou richtext — sai vazio, sem derrubar a página.
     */
    function richtext(mixed $html): string
    {
        if (!is_string($html)) {
            return '';
        }
        if (!\Velk\RichtextBlocks::has($html)) {
            return $html;
        }
        $config = App::instance()->config;

        return \Velk\RichtextBlocks::replace($html, static function (string $type, ?array $data) use ($config): string {
            $def = \Velk\RichtextBlocks::validKey($type) ? $config->richtextBlock($type) : null;
            $file = $def !== null ? \Velk\RichtextBlocks::file($def) : null;
            $problem = match (true) {
                !\Velk\RichtextBlocks::validKey($type) => 'invalid block key',
                $data === null => 'data is not a JSON object',
                $def === null => 'not declared in site.json',
                $file === null => 'no valid "file" in site.json',
                !is_file($config->path('theme') . '/partials/' . \Velk\RichtextBlocks::PARTIALS . '/' . $file) => 'partial not found',
                default => null,
            };
            if ($problem !== null) {
                if ($config->get('app.debug')) {
                    // A chave vem do conteúdo: sem quebra de linha no log.
                    $name = substr((string) preg_replace('/[^A-Za-z0-9_-]/', '?', $type), 0, 60);
                    error_log('[Velk] richtext: block "' . $name . '" skipped — ' . $problem . '.');
                }
                return '';
            }

            // O dado gravado passa pela normalização do formulário: só os
            // campos declarados, e o que falta chega vazio em qualquer
            // profundidade (o subcampo novo de um repetidor).
            $vars = \Velk\FieldRenderer::collect($config->richtextBlockFields($type), $data);

            $level = ob_get_level();
            ob_start();
            try {
                partial(\Velk\RichtextBlocks::PARTIALS . '/' . $file, $vars);
                return (string) ob_get_clean();
            } finally {
                // Um partial que lança erro não deixa buffer aberto.
                while (ob_get_level() > $level) {
                    ob_end_clean();
                }
            }
        });
    }
}

if (!function_exists('get_header')) {
    function get_header(): void
    {
        $themePath = App::instance()->config->path('theme');
        $file = $themePath . '/partials/header.php';
        if (is_file($file)) {
            include $file;
        }
    }
}

if (!function_exists('get_footer')) {
    function get_footer(): void
    {
        $themePath = App::instance()->config->path('theme');
        $file = $themePath . '/partials/footer.php';
        if (is_file($file)) {
            include $file;
        }
    }
}

if (!function_exists('image_url')) {
    /**
     * Resolve any image-field value to a URL.
     *
     * Accepts:
     *   - integer / numeric string → media ID, looked up in the media table
     *   - absolute URL or root-relative path → returned as-is
     *   - bare filename → prefixed with /uploads/
     *   - array with 'url' or 'filename' key → legacy fallback
     *   - null/empty → ''
     *
     * $size names an image variant (e.g. 'thumb', 'card') and only has an
     * effect when $value resolves to a media id — see Media::url(), which
     * this delegates to. It's a pure existence check: when that variant
     * was generated (at upload/save time) and its file is still on disk,
     * its URL comes back; otherwise this falls back to the URL of the
     * original file. Raw URLs, root-relative paths, and bare filenames
     * don't go through media at all, so $size has no effect on them.
     */
    function image_url(mixed $value, string $size = 'full'): string
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return '';
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $media = \Velk\Models\Media::find((int) $value);
            return $media === null ? '' : $media->url($size);
        }

        if (is_string($value)) {
            if (
                str_starts_with($value, 'http://')
                || str_starts_with($value, 'https://')
                || str_starts_with($value, 'data:')
            ) {
                return $value;
            }
            if (str_starts_with($value, '/')) {
                $base = base_path();
                if ($base !== '' && !str_starts_with($value, $base . '/') && $value !== $base) {
                    return $base . $value;
                }
                return $value;
            }
            return base_path() . '/uploads/' . ltrim($value, '/');
        }

        if (is_array($value)) {
            if (isset($value['id']) && is_numeric($value['id'])) {
                return image_url((int) $value['id'], $size);
            }
            if (isset($value['url']) && is_string($value['url'])) {
                return $value['url'];
            }
            if (isset($value['filename']) && is_string($value['filename'])) {
                return '/uploads/' . ltrim($value['filename'], '/');
            }
        }

        return '';
    }
}

if (!function_exists('media')) {
    /**
     * Look up a Media record by id. Returns null when not found.
     */
    function media(mixed $id): ?\Velk\Models\Media
    {
        if ($id === null || $id === '' || $id === 0 || $id === '0') return null;
        if (!is_numeric($id)) return null;
        return \Velk\Models\Media::find((int) $id);
    }
}

if (!function_exists('image_alt')) {
    /**
     * Resolve an image-field value to its stored alt text. Empty string when
     * the value is not a media id or no alt was saved.
     *
     * i18n-aware: when the active language differs from the default
     * and the media has an alt translation for it, returns the
     * translated value. Falls back to the default-language alt,
     * then to the supplied $fallback, then to ''.
     */
    function image_alt(mixed $value, string $fallback = ''): string
    {
        $m = media($value);
        if ($m === null) return $fallback;

        $app = \Velk\App::instance();
        if ($app->config->isI18nEnabled() && $app->i18n->current() !== $app->config->defaultLanguage()) {
            $translated = $m->altIn($app->i18n->current());
            if ($translated !== '') return $translated;
        }

        return $m->alt !== null && $m->alt !== '' ? $m->alt : $fallback;
    }
}

if (!function_exists('ini_bytes')) {
    /**
     * Converte um shorthand do php.ini ("2M", "512K", "1G", "100") em bytes.
     * Valores <= 0 (post_max_size=0, -1 = ilimitado) voltam como estão —
     * o chamador decide o que "sem limite" significa.
     */
    function ini_bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') return 0;
        $unit = strtolower(substr($value, -1));
        return (int) match ($unit) {
            'g' => ((float) $value) * 1024 ** 3,
            'm' => ((float) $value) * 1024 ** 2,
            'k' => ((float) $value) * 1024,
            default => (float) $value,
        };
    }
}

if (!function_exists('file_url')) {
    /**
     * URL pública de um valor de campo `file` (id de mídia da biblioteca).
     * Mesma resolução do image_url — que já lida com id, filename e URLs
     * absolutas; o nome existe pra o tema ler bem com anexos não-imagem.
     * Metadados (nome, mime, tamanho): use media(field('anexo')).
     */
    function file_url(mixed $value): string
    {
        return image_url($value);
    }
}

if (!function_exists('human_filesize')) {
    /**
     * Tamanho legível (B/KB/MB) a partir de bytes — inverso de ini_bytes().
     * Fina wrapper sobre FormUploadService::humanSize() (mesma convenção de
     * Media::humanSize()), usada pelos pontos de renderização de campos de
     * arquivo de FORMULÁRIOS (admin + template de email) — não confundir
     * com Media::humanSize(), que é um método de instância da biblioteca
     * de mídia.
     */
    function human_filesize(int $bytes): string
    {
        return \Velk\Services\FormUploadService::humanSize($bytes);
    }
}

if (!function_exists('slugify')) {
    function slugify(string $value): string
    {
        $value = trim($value);
        $value = mb_strtolower($value, 'UTF-8');
        $map = [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ];
        $value = strtr($value, $map);
        $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? '';
        $value = trim($value, '-');
        return $value === '' ? 'item' : $value;
    }
}

if (!function_exists('dd')) {
    function dd(mixed ...$values): never
    {
        echo '<pre style="background:#000;color:#0f0;padding:1rem;font-family:monospace">';
        foreach ($values as $value) {
            var_dump($value);
        }
        echo '</pre>';
        exit;
    }
}

// ─── Member helpers ────────────────────────────────────────────────
//
// These give the theme a tiny, intentional surface for working with
// site members. The point is that theme code should be able to gate
// pages and personalize output without reaching into App::instance()
// or dealing with the auth class directly.

if (!function_exists('member_enabled')) {
    /** True if the site is configured with members at all. */
    function member_enabled(): bool
    {
        return \Velk\App::instance()->config->isMembersEnabled();
    }
}

if (!function_exists('member_check')) {
    /** True if a member is logged in. */
    function member_check(): bool
    {
        return \Velk\App::instance()->memberAuth->check();
    }
}

if (!function_exists('member')) {
    /** Returns the logged-in Member or null. */
    function member(): ?\Velk\Models\Member
    {
        return \Velk\App::instance()->memberAuth->member();
    }
}

if (!function_exists('member_field')) {
    /** Shortcut for the logged-in member's custom-field value. */
    function member_field(string $name, mixed $default = null): mixed
    {
        $m = member();
        return $m === null ? $default : $m->field($name, $default);
    }
}

if (!function_exists('member_is')) {
    /** True if the logged-in member matches the given type. */
    function member_is(string $type): bool
    {
        $m = member();
        return $m !== null && $m->isType($type);
    }
}

if (!function_exists('member_route')) {
    /**
     * Resolve a member auth path (with install base prefix) for a form
     * action attribute. Keys: login, logout, register, password_reset,
     * password_new. Mirrors the config-defined member.routes map.
     */
    function member_route(string $key): string
    {
        $app = \Velk\App::instance();
        $routes = $app->config->memberRoutes();
        $path = $routes[$key] ?? '/';
        return $app->basePath . $path;
    }
}

if (!function_exists('member_require')) {
    /**
     * Gate the current request: if no member is logged in, save the
     * current path as "intended" and redirect to the login route.
     * Call at the top of restricted templates; the redirect halts
     * execution so the rest of the template never runs.
     */
    function member_require(?string $loginUrl = null): void
    {
        if (member_check()) return;
        $app = \Velk\App::instance();
        $app->session->put('_member_intended', $_SERVER['REQUEST_URI'] ?? '/');
        $target = $loginUrl ?? member_route('login');
        header('Location: ' . $target, true, 302);
        exit;
    }
}

if (!function_exists('member_require_type')) {
    /**
     * Restrict the page to specific member types. Pass one or more
     * type slugs. If the visitor isn't a matching type (including
     * "not logged in"), they get redirected to the fallback URL
     * (defaults to "/" — root).
     */
    function member_require_type(string|array $types, string $fallback = '/'): void
    {
        $types = is_array($types) ? $types : [$types];
        $m = member();
        if ($m === null || !in_array($m->type, $types, true)) {
            $app = \Velk\App::instance();
            header('Location: ' . $app->basePath . $fallback, true, 302);
            exit;
        }
    }
}

if (!function_exists('member_plans')) {
    /**
     * Planos ativos com preços, prontos para a tabela de preços do tema.
     * Cada plano: key, name, description, features[], currency,
     * prices['month'|'year'] => ['amount' => centavos, 'formatted' => 'R$ ...'].
     */
    function member_plans(): array
    {
        if (!\Velk\App::instance()->config->isSubscriptionsEnabled()) {
            return [];
        }
        return \Velk\Services\SubscriptionService::make()->plans(true);
    }
}

if (!function_exists('member_subscription')) {
    /**
     * A assinatura ativa MAIS RECENTE do membro logado (objeto cru) ou null.
     * Com members.multiple_plans pode haver várias — ver member_subscriptions().
     */
    function member_subscription(): ?\Velk\Models\MemberSubscription
    {
        $m = member();
        if ($m === null) {
            return null;
        }
        return \Velk\Models\MemberSubscription::activeForMember($m->id);
    }
}

if (!function_exists('member_subscribed')) {
    /** True se o membro logado tem um plano ativo. */
    function member_subscribed(): bool
    {
        return member_subscription() !== null;
    }
}

if (!function_exists('member_plan')) {
    /**
     * O plano ativo do membro (mesma forma de member_plans()) ou null.
     * Útil para mostrar o nome do plano e fazer gating por tier.
     */
    function member_plan(): ?array
    {
        $sub = member_subscription();
        if ($sub === null) {
            return null;
        }
        foreach (\Velk\Services\SubscriptionService::make()->plans(false) as $p) {
            if ($p['key'] === $sub->planKey) {
                return $p;
            }
        }
        // Plano removido do site.json mas assinatura ainda ativa: forma mínima.
        return ['key' => $sub->planKey, 'name' => $sub->planKey, 'prices' => []];
    }
}

if (!function_exists('member_subscriptions')) {
    /**
     * Assinaturas ATIVAS do membro logado, mais recente primeiro. Sem
     * members.multiple_plans há no máximo uma; com, uma por plano.
     * @return list<\Velk\Models\MemberSubscription>
     */
    function member_subscriptions(): array
    {
        $m = member();
        if ($m === null) {
            return [];
        }
        return \Velk\Models\MemberSubscription::activeListForMember($m->id);
    }
}

if (!function_exists('member_subscribed_plans')) {
    /**
     * Os planos das assinaturas ativas (mesma forma de member_plans()), um
     * por assinatura, na ordem de member_subscriptions(). Plano removido do
     * site.json vira a forma mínima, como em member_plan().
     * @return list<array>
     */
    function member_subscribed_plans(): array
    {
        $subs = member_subscriptions();
        if ($subs === []) {
            return [];
        }
        $catalog = [];
        foreach (\Velk\Services\SubscriptionService::make()->plans(false) as $p) {
            $catalog[$p['key']] = $p;
        }
        $out = [];
        foreach ($subs as $sub) {
            $out[] = $catalog[$sub->planKey] ?? ['key' => $sub->planKey, 'name' => $sub->planKey, 'prices' => []];
        }
        return $out;
    }
}

if (!function_exists('member_can_subscribe')) {
    /**
     * A resposta da guarda de subscribe(), para o tema esconder o botão
     * antes do clique: false quando "ativas + $planKey" viola a regra de
     * cardinalidade, ou sem login. Não confere se o plano existe ou está
     * ativo — isso é member_plans().
     */
    function member_can_subscribe(string $planKey): bool
    {
        if (member() === null) {
            return false;
        }
        $keys = array_map(fn(\Velk\Models\MemberSubscription $s) => $s->planKey, member_subscriptions());
        $keys[] = $planKey;
        return !\Velk\Models\MemberSubscription::violatesCardinality(
            $keys,
            \Velk\App::instance()->config->allowsMultiplePlans()
        );
    }
}

if (!function_exists('member_on_plan')) {
    /** True se ALGUMA assinatura ativa do membro é exatamente $key. */
    function member_on_plan(string $key): bool
    {
        foreach (member_subscriptions() as $sub) {
            if ($sub->planKey === $key) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('member_subscribe_button')) {
    /**
     * Form POST (com CSRF) que dispara o checkout de um plano/intervalo.
     * $attrs vira atributos extras no <button> (ex.: ['class' => 'btn']).
     */
    function member_subscribe_button(string $planKey, string $interval = 'month', ?string $label = null, array $attrs = []): string
    {
        $label ??= t('Subscribe');
        $action = member_route('subscribe');
        $attrHtml = '';
        foreach ($attrs as $k => $v) {
            $attrHtml .= ' ' . e((string) $k) . '="' . e((string) $v) . '"';
        }
        return '<form method="post" action="' . e($action) . '" class="member-subscribe-form">'
            . csrf_field()
            . '<input type="hidden" name="plan" value="' . e($planKey) . '">'
            . '<input type="hidden" name="interval" value="' . e($interval) . '">'
            . '<button type="submit"' . $attrHtml . '>' . e($label) . '</button>'
            . '</form>';
    }
}

if (!function_exists('member_portal_button')) {
    /** Form POST (com CSRF) que abre o Customer Portal do Stripe. */
    function member_portal_button(?string $label = null, array $attrs = []): string
    {
        $label ??= t('Manage subscription');
        $action = member_route('subscription_portal');
        $attrHtml = '';
        foreach ($attrs as $k => $v) {
            $attrHtml .= ' ' . e((string) $k) . '="' . e((string) $v) . '"';
        }
        return '<form method="post" action="' . e($action) . '" class="member-portal-form">'
            . csrf_field()
            . '<button type="submit"' . $attrHtml . '>' . e($label) . '</button>'
            . '</form>';
    }
}

if (!function_exists('member_require_plan')) {
    /**
     * Gate de página premium. Sem login → manda pro login (guardando o
     * destino). Logado mas sem plano ativo (ou sem um dos $keys) →
     * redireciona pro $fallback (default: raiz do site). O redirect
     * encerra a execução, então o resto do template não roda.
     */
    function member_require_plan(string|array|null $keys = null, ?string $fallback = null): void
    {
        $app = \Velk\App::instance();

        if (!member_check()) {
            $app->session->put('_member_intended', $_SERVER['REQUEST_URI'] ?? '/');
            header('Location: ' . member_route('login'), true, 302);
            exit;
        }

        // Qualquer ativa serve: quem paga pro e basic tem que passar em
        // member_require_plan('pro') — antes só a mais recente contava.
        $subs = member_subscriptions();
        $ok = $subs !== [];
        if ($ok && $keys !== null) {
            $allowed = is_array($keys) ? $keys : [$keys];
            $ok = false;
            foreach ($subs as $sub) {
                if (in_array($sub->planKey, $allowed, true)) {
                    $ok = true;
                    break;
                }
            }
        }

        if (!$ok) {
            $target = $fallback ?? ($app->basePath === '' ? '/' : $app->basePath . '/');
            header('Location: ' . $target, true, 302);
            exit;
        }
    }
}

if (!function_exists('item_sellable')) {
    /** True se o tipo do item é vendável e a loja está ligada. */
    function item_sellable(\Velk\Models\Item $item): bool
    {
        $config = \Velk\App::instance()->config;
        return $config->isStoreEnabled() && $config->isItemTypeSellable($item->type);
    }
}

if (!function_exists('item_purchasable')) {
    /**
     * "Mostro o botão de comprar?" — vendável E (o tipo permite repetir OU
     * o visitante não é membro OU o membro ainda não comprou). É a pergunta
     * antes do clique; depois dele o checkout responde already_purchased.
     */
    function item_purchasable(\Velk\Models\Item $item): bool
    {
        if (!item_sellable($item)) {
            return false;
        }
        if (\Velk\App::instance()->config->isItemTypeRepeatPurchase($item->type)) {
            return true;
        }
        $m = member();
        return $m === null || !\Velk\Models\Order::paidForMemberContainingItem($m->id, $item->id);
    }
}

if (!function_exists('item_price')) {
    /** Preço do item: ['amount'=>centavos, 'formatted'=>'R$ ...'] ou null. */
    function item_price(\Velk\Models\Item $item): ?array
    {
        $cents = \Velk\Services\Money::toCents($item->field('price'));
        if ($cents === null || $cents <= 0) {
            return null;
        }
        $currency = \Velk\App::instance()->config->storeCurrency();
        return ['amount' => $cents, 'formatted' => \Velk\Services\Money::format($cents, $currency)];
    }
}

if (!function_exists('store_checkout_url')) {
    /** URL (com base) do endpoint de checkout, para forms de carrinho do tema. */
    function store_checkout_url(): string
    {
        $app = \Velk\App::instance();
        return $app->basePath . ($app->config->storeRoutes()['checkout'] ?? '/store/checkout');
    }
}

if (!function_exists('store_checkout_button')) {
    /**
     * Form POST (com CSRF) que envia o carrinho ao checkout.
     * $cart = [['id'=>5,'qty'=>2], ['id'=>8,'qty'=>1]].
     */
    function store_checkout_button(array $cart, ?string $label = null, array $attrs = []): string
    {
        $label ??= t('Buy');
        $action = store_checkout_url();
        $hidden = '';
        $i = 0;
        foreach ($cart as $line) {
            $id = (int) ($line['id'] ?? 0);
            $qty = max(1, (int) ($line['qty'] ?? 1));
            if ($id <= 0) {
                continue;
            }
            $hidden .= '<input type="hidden" name="items[' . $i . '][id]" value="' . e((string) $id) . '">';
            $hidden .= '<input type="hidden" name="items[' . $i . '][qty]" value="' . e((string) $qty) . '">';
            $i++;
        }
        $attrHtml = '';
        foreach ($attrs as $k => $v) {
            $attrHtml .= ' ' . e((string) $k) . '="' . e((string) $v) . '"';
        }
        return '<form method="post" action="' . e($action) . '" class="store-checkout-form">'
            . csrf_field()
            . $hidden
            . '<button type="submit"' . $attrHtml . '>' . e($label) . '</button>'
            . '</form>';
    }
}

if (!function_exists('member_orders')) {
    /** Pedidos PAGOS do membro logado, recentes primeiro, com linhas. */
    function member_orders(): array
    {
        $m = member();
        if ($m === null) {
            return [];
        }
        $currencyFmt = fn(int $cents, string $cur) => \Velk\Services\Money::format($cents, $cur);
        $out = [];
        foreach (\Velk\Models\Order::forMember($m->id, 'paid') as $o) {
            $items = [];
            foreach ($o->items() as $li) {
                $items[] = [
                    'item_id' => $li->itemId,
                    'item_type' => $li->itemType,
                    'title' => $li->title,
                    'quantity' => $li->quantity,
                    'unit_amount' => $li->unitAmount,
                    'unit_formatted' => $currencyFmt($li->unitAmount, $o->currency),
                ];
            }
            $out[] = [
                'id' => $o->id,
                'status' => $o->status,
                'total_amount' => $o->totalAmount,
                'total_formatted' => $currencyFmt($o->totalAmount, $o->currency),
                'created_at' => $o->createdAt,
                'items' => $items,
            ];
        }
        return $out;
    }
}

if (!function_exists('member_purchased')) {
    /** True se o membro logado tem pedido pago contendo este item. */
    function member_purchased(\Velk\Models\Item $item): bool
    {
        $m = member();
        return $m !== null && \Velk\Models\Order::paidForMemberContainingItem($m->id, $item->id);
    }
}

if (!function_exists('store_status')) {
    /**
     * Lê (e consome) o flash de status da loja, para o tema exibir.
     *
     * Shape: ['status' => 'success'|'error'|'info', 'code' => string]
     * Códigos: cart_empty, cart_invalid, checkout_failed, session_expired,
     * order_paid, purchase_cancelled, already_purchased.
     * Descrição de cada código em docs/messages.md.
     */
    function store_status(): ?array
    {
        $app = \Velk\App::instance();
        $status = $app->session->get('_store_status');
        if (!is_array($status)) {
            return null;
        }
        $app->session->forget('_store_status');
        return $status;
    }
}

// ─── i18n helpers ──────────────────────────────────────────────────
//
// Translation runtime entry points for theme + engine code. `t()` looks
// the source string up in the DB catalog and falls back to the source
// itself when there is no translation — which is what the site's default
// language always gets, since the panel refuses to register the default
// as a translatable language.

if (!function_exists('t')) {
    /**
     * Translate a source string. Write it in whatever language the theme is
     * written in — that is the language the site's default visitors see, and
     * translations for the other languages live in the DB.
     *
     * Usage:
     *   t('Read more')
     *   t('Hello, {name}!', ['name' => 'World'])
     *   t('Posts', context: 'noun')   // disambiguate from verb
     */
    function t(string $original, array $params = [], ?string $context = null): string
    {
        return \Velk\App::instance()->i18n->translate($original, $params, $context);
    }
}

if (!function_exists('__')) {
    /**
     * Translate an ADMIN-PANEL symbolic key (file-based catalog).
     * Distinct from t() (site content, DB catalog).
     */
    function __(string $key, array $params = []): string
    {
        return \Velk\App::instance()->adminI18n->translate($key, $params);
    }
}

if (!function_exists('__raw')) {
    /**
     * I10: like __(), but interpolated params are NOT HTML-escaped. For
     * PLAIN-TEXT sinks ONLY — e.g. an email Subject header, which isn't an
     * HTML context ("Joe's & Co" would otherwise render as literal
     * "Joe&#039;s &amp; Co" in the header). Never use this for HTML body
     * output — use __() there so params stay escaped.
     */
    function __raw(string $key, array $params = []): string
    {
        return \Velk\App::instance()->adminI18n->translateRaw($key, $params);
    }
}

if (!function_exists('lang')) {
    /** Code of the language active for this request. */
    function lang(): string
    {
        return \Velk\App::instance()->i18n->current();
    }
}

if (!function_exists('lang_is')) {
    function lang_is(string $code): bool
    {
        return lang() === $code;
    }
}

if (!function_exists('default_language')) {
    function default_language(): string
    {
        return \Velk\App::instance()->i18n->defaultLanguage();
    }
}

if (!function_exists('available_languages')) {
    /**
     * All languages the site supports (default first). Each entry:
     *   ['code', 'label', 'url_prefix', 'is_default']
     */
    function available_languages(): array
    {
        return \Velk\App::instance()->i18n->available();
    }
}

if (!function_exists('i18n_enabled')) {
    function i18n_enabled(): bool
    {
        return \Velk\App::instance()->i18n->isEnabled();
    }
}

if (!function_exists('lang_url')) {
    /**
     * URL for the SAME page in a different language. When called on
     * /sobre while viewing in pt-BR, `lang_url('en')` returns
     * /en/about (assuming pages.sobre.slug_translations.en = "about"),
     * or /en/sobre when the page has no English slug. A path the
     * translator doesn't recognize keeps its segments under the
     * language prefix. Content with no URL in that language (it lost a
     * slug conflict there) gets the language's home.
     *
     * Used by lang_switcher() — theme can call directly to build
     * a custom-styled switcher.
     */
    function lang_url(string $language, string $path = ''): string
    {
        // When caller passes a specific path, just build the URL in
        // the target language. This is the simple case.
        if ($path !== '') {
            return url($path, $language);
        }
        // Default: the CURRENT page in the target language. Content with
        // no URL there (it lost a slug conflict) sends the visitor to the
        // language's home.
        return url(_velk_lang_path($language) ?? '/', $language);
    }
}

if (!function_exists('_velk_request_path')) {
    /**
     * @internal Route path of the current request: no query string, no
     * install base path, no language prefix — e.g. `/post/my-post` on
     * `/velk/en/post/my-post?x=1`.
     */
    function _velk_request_path(): string
    {
        $app = \Velk\App::instance();
        $rawPath = explode('?', $_SERVER['REQUEST_URI'] ?? '/', 2)[0];
        $base = $app->basePath;
        if ($base !== '' && str_starts_with($rawPath, $base)) {
            $rawPath = substr($rawPath, strlen($base));
        }
        $currentLang = $app->i18n->current();
        if ($currentLang !== $app->config->defaultLanguage()) {
            $currentRow = \Velk\Models\Language::find($currentLang);
            if ($currentRow !== null && $currentRow->urlPrefix !== null
                && str_starts_with($rawPath, $currentRow->urlPrefix)) {
                $rawPath = substr($rawPath, strlen($currentRow->urlPrefix));
            }
        }
        return '/' . trim($rawPath, '/');
    }
}

if (!function_exists('_velk_lang_path')) {
    /**
     * @internal Route path of the current page in $language — the page,
     * item or term the request points at, with its slug in that language
     * (translated, or the default one). Null when that content has no URL
     * in $language: it lost a slug conflict there. No base path and no
     * language prefix — url() adds them.
     */
    function _velk_lang_path(string $language): ?string
    {
        return \_VelkPathTranslator::translate(_velk_request_path(), $language, \Velk\App::instance());
    }
}

if (!function_exists('_velk_absolute')) {
    /**
     * @internal Prefixes the site's origin to a URL url() already built —
     * base path and language prefix included. absolute_url() would run url()
     * again and repeat them. app_origin(): APP_URL, or the request's scheme
     * and host when it's unset — canonical and hreflang must be absolute.
     */
    function _velk_absolute(string $builtUrl): string
    {
        return app_origin() . $builtUrl;
    }
}

if (!function_exists('lang_switcher')) {
    /**
     * Returns an array of items the theme uses to render a language
     * switcher. Each item is:
     *   ['code', 'label', 'url', 'is_current']
     *
     * Usage in theme:
     *   foreach (lang_switcher() as $opt) {
     *       echo '<a href="' . e($opt['url']) . '">' . e($opt['label']) . '</a>';
     *   }
     */
    function lang_switcher(): array
    {
        $app = \Velk\App::instance();
        $current = $app->i18n->current();
        $out = [];
        foreach ($app->i18n->available() as $code => $info) {
            $out[] = [
                'code' => $code,
                'label' => $info['label'],
                'url' => lang_url($code),
                'is_current' => $code === $current,
            ];
        }
        return $out;
    }
}

if (!function_exists('the_html_lang')) {
    /**
     * Returns the BCP 47 language tag for the `<html lang="...">`
     * attribute. Falls back to "pt-BR" when i18n is off and no site
     * language is set.
     */
    function the_html_lang(): string
    {
        $app = \Velk\App::instance();
        if ($app->config->isI18nEnabled()) {
            return $app->i18n->current();
        }
        return (string) ($app->config->site('site.language') ?? 'pt-BR');
    }
}

if (!function_exists('the_hreflangs')) {
    /**
     * Emit `<link rel="alternate" hreflang="..." href="...">` tags
     * for every available language, plus an x-default pointing at
     * the default-language version. Insert in the theme's <head>.
     */
    function the_hreflangs(): string
    {
        $app = \Velk\App::instance();
        // Na página de 404 não há versões a anunciar.
        if (!$app->config->isI18nEnabled() || \Velk\FrontRouter::renderingNotFound()) return '';

        $defaultLang = $app->config->defaultLanguage();
        $out = '';
        // A language where the content has no URL (it lost a slug
        // conflict there) gets no alternate.
        foreach ($app->i18n->available() as $code => $info) {
            $path = _velk_lang_path((string) $code);
            if ($path === null) continue;
            $out .= sprintf(
                '<link rel="alternate" hreflang="%s" href="%s">' . "\n",
                e((string) $code),
                e(_velk_absolute(url($path, (string) $code)))
            );
        }
        // x-default points at the default language version
        $default = _velk_lang_path($defaultLang);
        if ($default !== null) {
            $out .= sprintf(
                '<link rel="alternate" hreflang="x-default" href="%s">' . "\n",
                e(_velk_absolute(url($default, $defaultLang)))
            );
        }
        return $out;
    }
}

if (!function_exists('the_canonical')) {
    /** Canonical URL for the current page (current language version). */
    function the_canonical(): string
    {
        $lang = lang();
        return _velk_absolute(url(_velk_lang_path($lang) ?? _velk_request_path(), $lang));
    }
}

// Internal helper used by lang_url() — not exported publicly. Lives
// here because it's tightly coupled to the URL helpers above.
// M2: guard must name the class actually declared below (global
// _VelkPathTranslator) — it never lived under Velk\Internal, so that
// check was always true and a double-include of this file would fatal
// with "Cannot redeclare class _VelkPathTranslator".
if (!class_exists('_VelkPathTranslator')) {
    /** @internal */
    class _VelkPathTranslator {
        /**
         * The route path $path (current language) rebuilt for $targetLang,
         * or null when the content it points at has no URL there — it lost
         * a slug conflict in that language (Item::ownsUrlIn()).
         */
        public static function translate(string $path, string $targetLang, \Velk\App $app): ?string {
            // Empty/root path: just root in target language.
            $path = '/' . trim($path, '/');
            if ($path === '/') return '/';

            // Try to resolve current path to a page → use its
            // translation if any.
            $currentLang = $app->i18n->current();
            $resolved = \Velk\Models\Page::resolveByUrl($path, $app->config, $currentLang);
            if ($resolved !== null) {
                $row = \Velk\Models\Page::findByKey($resolved['key']);
                if ($row !== null) {
                    // fullPath monta a cadeia de pais (subpáginas) e resolve
                    // JSON/custom + o idioma-alvo internamente — cada nível
                    // com o slug traduzido, ou o padrão se não tiver.
                    return \Velk\Models\Page::ownsUrlIn($row->key, $app->config, $targetLang)
                        ? \Velk\Models\Page::fullPath($row->key, $app->config, $targetLang)
                        : null;
                }
            }

            // Item ou termo: acha QUAL registro o path aponta no idioma
            // atual e remonta o endereço dele no idioma-alvo — prefixo
            // traduzido e, em cada nível, o slug traduzido ou o padrão.
            $segments = explode('/', trim($path, '/'));
            if (count($segments) >= 2) {
                $defaultLang = $app->config->defaultLanguage();
                $rest = array_slice($segments, 1);

                $type = $app->config->itemTypeByLocalizedSlug($segments[0], $currentLang);
                if ($type !== null) {
                    if ($app->config->isItemTypeHierarchical($type)) {
                        $item = \Velk\Models\Item::findByPath($type, $rest, $currentLang, $defaultLang);
                        if ($item !== null) {
                            return $item->ownsUrlIn($targetLang) ? \Velk\Models\Item::fullPath($item, $app->config, $targetLang) : null;
                        }
                    } elseif (count($rest) === 1) {
                        $item = \Velk\Models\Item::findBySlugIn($type, $rest[0], $currentLang, $defaultLang);
                        if ($item !== null) {
                            if (!$item->ownsUrlIn($targetLang)) return null;
                            $slug = $targetLang === $defaultLang ? $item->slug : $item->slugIn($targetLang);
                            return '/' . trim($app->config->itemTypeSlugIn($type, $targetLang), '/') . '/' . $slug;
                        }
                    }
                    // Item not found — translate the prefix only (will
                    // 404 gracefully).
                    return '/' . trim($app->config->itemTypeSlugIn($type, $targetLang), '/') . '/' . implode('/', $rest);
                }

                $tax = $app->config->taxonomyByLocalizedSlug($segments[0], $currentLang);
                if ($tax !== null) {
                    if ($app->config->isTaxonomyHierarchical($tax)) {
                        $term = \Velk\Models\Term::findByPath($tax, $rest, $currentLang, $defaultLang);
                        if ($term !== null) {
                            return $term->ownsUrlIn($targetLang) ? \Velk\Models\Term::fullPath($term, $app->config, $targetLang) : null;
                        }
                    } elseif (count($rest) === 1) {
                        $term = \Velk\Models\Term::findBySlugIn($tax, $rest[0], $currentLang, $defaultLang);
                        if ($term !== null) {
                            if (!$term->ownsUrlIn($targetLang)) return null;
                            $slug = $targetLang === $defaultLang ? $term->slug : $term->slugIn($targetLang);
                            return '/' . trim($app->config->taxonomySlugIn($tax, $targetLang), '/') . '/' . $slug;
                        }
                    }
                    return '/' . trim($app->config->taxonomySlugIn($tax, $targetLang), '/') . '/' . implode('/', $rest);
                }
            }

            // Couldn't translate — return as-is. The url() helper will
            // still prefix the target language, so /unknown becomes
            // /en/unknown.
            return $path;
        }
    }
}

// Backward-compat alias, kept for themes that called it. Content with no
// URL in $targetLang comes back as the root path.
if (!function_exists('_velk_translate_path')) {
    function _velk_translate_path(string $path, string $targetLang, \Velk\App $app): string {
        return \_VelkPathTranslator::translate($path, $targetLang, $app) ?? '/';
    }
}

if (!function_exists('member_status')) {
    /**
     * Read-and-clear the last status flash from a member auth action.
     * Use in the theme's login/register/reset templates to turn the code
     * into whatever this theme says for it. Returns null when there's
     * nothing to show.
     *
     * Shape: ['status' => 'success'|'error'|'two_factor',
     *         'code' => string, 'params' => array<string,scalar>]
     *
     * The engine never picks the words — `invalid_credentials` is the fact,
     * "Email ou senha incorretos." is one theme's phrasing of it. See
     * docs/messages.md for the full vocabulary.
     */
    function member_status(): ?array
    {
        $session = \Velk\App::instance()->session;
        $value = $session->get('_member_status');
        if ($value === null) return null;
        $session->forget('_member_status');
        return is_array($value) ? $value : null;
    }
}
