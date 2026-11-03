<?php

declare(strict_types=1);

namespace Velk;

/**
 * A política de conteúdo (CSP) do site público.
 *
 * Mora no `site.json` (`security.csp`), junto do tema: o que o site carrega
 * de fora — os embeds, os destinos dos formulários — é o tema que sabe, e a
 * política vai com ele de um ambiente para outro. Do `.env` só vem o coletor
 * de relatos (`CSP_REPORT_URI`), que muda por ambiente.
 *
 * - `open` (padrão): só as travas que não quebram nada — quem pode pôr o
 *   site num iframe, e `base-uri`. Scripts, imagens e conexões seguem
 *   livres, e o gestor de tráfego segue colando qualquer código no painel.
 * - `strict`: `script-src` com a senha (nonce) da página e
 *   `'strict-dynamic'`. Os scripts que o core e o tema imprimem levam a
 *   senha, e o que eles carregam depois (as tags do GTM) herda a confiança.
 *   É o que tira o `'unsafe-inline'` apontado pelos analisadores sem tirar a
 *   autonomia do painel — por isso não há lista de domínios de script. Um
 *   formulário só envia para o próprio site e para os destinos liberados.
 * - `report`: a política do `strict` em `…-Report-Only` — nada é bloqueado,
 *   o navegador só relata. É o ensaio antes de ligar o estrito.
 * - `off`: nenhum cabeçalho (o tema, ou o servidor, cuida disso).
 *
 * Nenhum modo tem `default-src`: o que não está listado fica sem restrição.
 * O painel tem a política dele (`Controller::ADMIN_CSP`) e não passa por
 * aqui.
 */
final class PublicCsp
{
    public const MODES = ['open', 'strict', 'report', 'off'];

    /** As chaves de `security.csp`. */
    public const KEYS = ['mode', 'allow_eval', 'frame_ancestors', 'allow'];

    /** As listas de `security.csp.allow`, na ordem em que as diretivas saem. */
    public const LISTS = ['connect', 'img', 'frame', 'form'];

    /**
     * As variáveis que o `.env` tinha da v3.8.1 à v3.8.3, com o lugar de cada
     * uma no `site.json`. A lista de scripts não tem: a senha cobre os
     * scripts.
     */
    private const LEGACY_ENV = [
        'CSP_MODE' => 'security.csp.mode',
        'CSP_ALLOW_EVAL' => 'security.csp.allow_eval',
        'CSP_FRAME_ANCESTORS' => 'security.csp.frame_ancestors',
        'CSP_SCRIPT_SRC' => null,
        'CSP_CONNECT_SRC' => 'security.csp.allow.connect',
        'CSP_IMG_SRC' => 'security.csp.allow.img',
        'CSP_FRAME_SRC' => 'security.csp.allow.frame',
        'CSP_FORM_ACTION' => 'security.csp.allow.form',
    ];

    /** Por que não há lista de scripts — no aviso do `allow.script` e do `CSP_SCRIPT_SRC`. */
    private const NO_SCRIPT_LIST = "there is no script list: strict runs on the page's nonce plus 'strict-dynamic'; remove it";

    /**
     * Um atributo `nonce` numa tag: no começo, ou depois de espaço, aspas ou
     * `/`. `data-nonce` e um `&nonce=` dentro de uma URL não contam. O
     * `csp:check` usa a mesma regra.
     */
    public const NONCE_ATTR = '/(?:^|[\s"\'\/])nonce\s*=/i';

    private static ?string $nonce = null;

    /**
     * Uma fonte aceita numa lista: `https:`, `'self'`, ou um host com esquema
     * opcional, `*.` opcional, porta e caminho opcionais. Tudo o mais cai —
     * em especial `;` e quebra de linha, que injetariam uma diretiva ou um
     * cabeçalho novo, e as aspas, que abririam `'unsafe-inline'`.
     */
    private const SOURCE = '#^(?:https:|\'self\'|(?:(?:https?|wss?)://)?(?:\*\.)?[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*(?::(?:\d{1,5}|\*))?(?:/[a-z0-9._~%/-]*)?)$#iD';

    /**
     * A senha desta requisição: 16 bytes aleatórios em base64, gerados uma
     * vez e iguais em toda a página. Existe em qualquer modo — fora do
     * estrito o atributo `nonce` é inofensivo, e o tema não precisa saber
     * em que modo o site está.
     */
    public static function nonce(): string
    {
        return self::$nonce ??= base64_encode(random_bytes(16));
    }

    /**
     * Põe a senha em toda abertura `<script` do HTML que ainda não tem uma.
     *
     * É para o código colado no painel (scripts de rastreamento), que não
     * tem como chamar `csp_nonce()`. Um `nonce` que já está lá é do autor
     * e fica; `data-nonce` não conta como senha.
     *
     * Cada elemento é tomado inteiro, da abertura ao `</script>`, e só a
     * abertura muda: um `<script` dentro do JavaScript (o
     * `document.write("<script …><\/script>")` dos snippets antigos) fica
     * como está, porque as aspas da senha fechariam a string dele.
     */
    public static function nonceScripts(string $html): string
    {
        $attr = ' nonce="' . self::nonce() . '"';
        $out = preg_replace_callback(
            '#<(script)(?=[\s>/])([^>]*>)(.*?)(</script\s*>|\z)#is',
            static fn(array $m): string => preg_match(self::NONCE_ATTR, $m[2]) === 1
                ? $m[0]
                : '<' . $m[1] . $attr . $m[2] . $m[3] . $m[4],
            $html
        );
        if ($out === null) {
            // Um corpo de script grande demais estoura o backtrack_limit do
            // PCRE. O HTML sai como veio, sem senha — no strict, esses
            // scripts não rodam —, e o log diz por quê.
            error_log('[Velk] CSP: could not add the nonce to a script (' . preg_last_error_msg() . ').');
            return $html;
        }
        return $out;
    }

    /**
     * Manda a política numa resposta do site público.
     *
     * Fica de fora: o painel (tem a própria), a resposta que já trouxe uma
     * política (o SVG de /uploads sai com `sandbox`, por exemplo), a que o
     * tema mandou com `header()` e o que não é HTML — JSON, XML, arquivo.
     *
     * @param list<string>|null $sent  os cabeçalhos já mandados com `header()`
     *                                 (padrão: `headers_list()`)
     */
    public static function apply(Request $request, Response $response, Config $config, ?array $sent = null): Response
    {
        $prefix = '/' . trim((string) $config->get('admin.prefix', '/admin'), '/');
        if ($request->path === $prefix || str_starts_with($request->path, $prefix . '/')) {
            return $response;
        }
        if ($response->getHeader('Content-Security-Policy') !== null
            || $response->getHeader('Content-Security-Policy-Report-Only') !== null) {
            return $response;
        }
        if (!str_starts_with(strtolower((string) $response->getHeader('Content-Type')), 'text/html')) {
            return $response;
        }
        // A política que o tema mandou com header() no functions.php fica, e
        // o core não manda a sua: o send() substituiria a do tema, e um site
        // com política própria perderia as restrições dela no upgrade.
        foreach ($sent ?? headers_list() as $line) {
            if (preg_match('/^Content-Security-Policy(?:-Report-Only)?\s*:/i', $line) === 1) {
                return $response;
            }
        }

        foreach (self::headers(self::settings($config), self::nonce(), self::isHttps($request)) as [$name, $value]) {
            $response->header($name, $value);
        }
        return $response;
    }

    /**
     * O `resolve()` com a configuração do site, e cada problema no log do
     * PHP — o site segue no ar com o que sobrou válido.
     *
     * No `open` e no `off` só vai para o log o que muda o site nesses modos
     * (os `urgent`): um erro numa lista não muda o cabeçalho ali, e ir para o
     * log a cada página seria só barulho (o `csp:check` o aponta de qualquer
     * jeito).
     */
    public static function settings(Config $config): array
    {
        $resolved = self::resolve($config->site('security.csp'), self::envSettings($config), self::coreSources($config));
        $counts = in_array($resolved['settings']['mode'], ['strict', 'report'], true);
        foreach ($counts ? $resolved['problems'] : $resolved['urgent'] as $problem) {
            error_log('[Velk] CSP: ' . $problem);
        }
        return $resolved['settings'];
    }

    /**
     * O que vem do `.env`, no formato do `resolve()`: o coletor de relatos e
     * as variáveis antigas ainda definidas. As antigas são lidas aqui, não no
     * `config/env.php`: um upgrade por FTP que suba o `config/` antes do
     * `core/` não pode derrubar o site chamando um método que ainda não
     * chegou.
     *
     * @return array{report_uri: mixed, legacy: list<string>}
     */
    public static function envSettings(Config $config): array
    {
        return ['report_uri' => $config->get('csp.report_uri', ''), 'legacy' => self::legacyEnv()];
    }

    /**
     * As variáveis antigas que o `.env` ainda define com um valor que mudava
     * a política. O padrão do `.env.example` antigo (`open`, `0`, vazio) não
     * conta: é o que já vale.
     *
     * @return list<string>
     */
    public static function legacyEnv(): array
    {
        $set = [];
        foreach (array_keys(self::LEGACY_ENV) as $var) {
            $value = Env::get($var);
            $changes = match ($var) {
                'CSP_MODE' => !in_array(strtolower(trim((string) $value)), ['', 'open'], true),
                'CSP_ALLOW_EVAL' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                default => trim((string) $value) !== '',
            };
            if ($changes) {
                $set[] = $var;
            }
        }
        return $set;
    }

    /**
     * O que os recursos do próprio core usam de fora.
     *
     * - O widget do Turnstile (`form_captcha()`) é um iframe da Cloudflare.
     *   Entra quando o tema declara a lista de iframes: sem isto, declará-la
     *   derrubaria o captcha, e todo envio de formulário. O script dele leva
     *   a senha e não precisa de nada.
     * - Os botões de assinar, do portal e de comprar são formulários que
     *   postam no próprio site, que redireciona para o Stripe — e o navegador
     *   aplica o `form-action` também a esse redirecionamento. Entram sempre
     *   que a assinatura ou a loja estão ligadas.
     *
     * @return array<string, list<string>>
     */
    public static function coreSources(Config $config): array
    {
        $sources = [];
        if (Services\TurnstileService::isConfigured()) {
            $sources['frame'][] = 'https://challenges.cloudflare.com';
        }
        if ($config->isSubscriptionsEnabled()) {
            $sources['form'][] = 'https://checkout.stripe.com';
            $sources['form'][] = 'https://billing.stripe.com';
        }
        if ($config->isStoreEnabled()) {
            $sources['form'][] = 'https://checkout.stripe.com';
        }
        return $sources;
    }

    /** A mesma regra do `Session::isHttps()`: HTTPS direto ou atrás de proxy. */
    public static function isHttps(Request $request): bool
    {
        $server = $request->server;
        return (!empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off')
            || ($server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    /**
     * O `security.csp` do `site.json` e o que vem do `.env`
     * (`envSettings()`), conferidos e prontos para o `build()`.
     *
     * Pura: não registra nada, devolve os problemas para quem chamou decidir
     * (o `settings()` manda para o log, o `csp:check` e o `schema:validate`
     * imprimem). `urgent` são os que mudam o site em qualquer modo: o bloco
     * ou uma chave dele errados (um `"modo"` deixaria o site no open sem
     * ninguém perceber), o `frame_ancestors`, que o open também usa, e uma
     * variável antiga no `.env`, que não é mais lida.
     *
     * Uma lista declarada em `allow` liga a restrição daquele recurso ao
     * próprio site e aos domínios dela; ausente ou vazia, o recurso fica
     * livre. As fontes do próprio core (`coreSources()`) entram junto. O
     * `form-action` é diferente: no estrito vale sempre, como o `base-uri`,
     * e a lista só acrescenta destinos.
     *
     * @param mixed $csp  `site.json → security.csp`
     * @param array{report_uri?: mixed, legacy?: list<string>} $env  `envSettings()`
     * @param array<string, list<string>> $core  `coreSources()`
     * @return array{settings: array{mode: string, allow_eval: bool, frame_ancestors: list<string>, report_uri: string, lists: array<string, list<string>>}, problems: list<string>, urgent: list<string>}
     */
    public static function resolve(mixed $csp, array $env = [], array $core = []): array
    {
        /** @var list<array{0: string, 1: bool}> $found  [mensagem, urgente] */
        $found = [];

        foreach ((array) ($env['legacy'] ?? []) as $var) {
            $place = self::LEGACY_ENV[$var] ?? null;
            $found[] = [$var . ' in .env is no longer read (since v3.8.4) — '
                . ($place === null ? self::NO_SCRIPT_LIST . '.' : 'move it to site.json → ' . $place . '.'), true];
        }

        if ($csp === null || $csp === []) {
            $csp = [];
        } elseif (!is_array($csp) || array_is_list($csp)) {
            $found[] = ['site.json → security.csp must be an object (' . implode(', ', self::KEYS) . ') — ignored, using open.', true];
            $csp = [];
        }
        foreach (array_keys($csp) as $key) {
            if ($key === 'report_uri') {
                $found[] = ['site.json → security.csp: "report_uri" is read from .env (CSP_REPORT_URI), where it can differ per environment — ignored.', true];
            } elseif (!in_array($key, self::KEYS, true)) {
                $found[] = ['site.json → security.csp: unknown key "' . $key . '" — use ' . implode(', ', self::KEYS) . '.', true];
            }
        }

        $mode = $csp['mode'] ?? '';
        if ($mode === '') {
            $mode = 'open';
        } elseif (!is_string($mode) || !in_array(strtolower(trim($mode)), self::MODES, true)) {
            // Cair no estrito derrubaria o site por um erro de digitação.
            $found[] = ['site.json → security.csp.mode ' . json_encode($mode, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . ' is not one of ' . implode(', ', self::MODES) . ' — using open.', true];
            $mode = 'open';
        } else {
            $mode = strtolower(trim($mode));
        }

        $eval = $csp['allow_eval'] ?? false;
        if (!is_bool($eval)) {
            $found[] = ['site.json → security.csp.allow_eval must be true or false — using false.', false];
            $eval = false;
        }

        $ancestors = self::sources($csp['frame_ancestors'] ?? [], 'security.csp.frame_ancestors', true, $found);

        $reportUri = trim((string) ($env['report_uri'] ?? ''));
        if ($reportUri !== '' && preg_match('~^https://[^\s;,\'"]+$~D', $reportUri) !== 1) {
            $found[] = ['CSP_REPORT_URI in .env must be one https:// URL — ignored.', false];
            $reportUri = '';
        }

        $allow = $csp['allow'] ?? [];
        if (!is_array($allow) || ($allow !== [] && array_is_list($allow))) {
            $found[] = ['site.json → security.csp.allow must be an object keyed by resource (' . implode(', ', self::LISTS) . ') — ignored.', false];
            $allow = [];
        }
        foreach (array_keys($allow) as $key) {
            if ($key === 'script') {
                $found[] = ['site.json → security.csp.allow.script is not used — ' . self::NO_SCRIPT_LIST . '.', false];
            } elseif (!in_array($key, self::LISTS, true)) {
                $found[] = ['site.json → security.csp.allow: unknown key "' . $key . '" — use ' . implode(', ', self::LISTS) . '.', false];
            }
        }

        $lists = [];
        foreach (self::LISTS as $kind) {
            $sources = self::sources($allow[$kind] ?? [], 'security.csp.allow.' . $kind, false, $found);
            if ($sources !== [] || $kind === 'form') {
                $sources = array_merge($sources, $core[$kind] ?? []);
            }
            $lists[$kind] = array_values(array_unique($sources));
        }

        return [
            'settings' => [
                'mode' => $mode,
                'allow_eval' => $eval,
                'frame_ancestors' => $ancestors,
                'report_uri' => $reportUri,
                'lists' => $lists,
            ],
            'problems' => array_column($found, 0),
            'urgent' => array_column(array_filter($found, static fn(array $p): bool => $p[1]), 0),
        ];
    }

    /**
     * As fontes de uma lista do `site.json`, conferidas: entrada que não é
     * texto e fonte inválida viram problema e ficam de fora. Um texto só,
     * no lugar da lista, também vale, separado por espaço; um objeto, não.
     *
     * @param list<array{0: string, 1: bool}> $found
     * @return list<string>
     */
    private static function sources(mixed $value, string $where, bool $urgent, array &$found): array
    {
        if (is_array($value) && !array_is_list($value)) {
            $found[] = ['site.json → ' . $where . ' must be a list of sources — ignored.', $urgent];
            return [];
        }
        $sources = [];
        foreach (is_array($value) ? $value : [$value] as $entry) {
            if (!is_string($entry)) {
                $found[] = ['site.json → ' . $where . ': every entry must be a string.', $urgent];
                continue;
            }
            [$valid, $bad] = self::parseSources($entry);
            foreach ($bad as $token) {
                $found[] = ['site.json → ' . $where . ': dropped invalid source "' . $token . '".', $urgent];
            }
            array_push($sources, ...$valid);
        }
        return $sources;
    }

    /**
     * A fonte cobre esse endereço? Uma aproximação da regra do navegador,
     * pelo esquema e pelo host: `https:` cobre qualquer https, `*.a.com`
     * cobre `b.a.com` mas não `a.com`, uma fonte `http://` cobre a mesma
     * origem em https, e uma sem esquema cobre os dois. Porta e caminho não
     * entram. É o que o `csp:check` precisa para apontar um formulário que
     * envia para fora, não uma reimplementação da CSP. `'self'` depende do
     * site e fica com quem chama.
     */
    public static function sourceMatches(string $source, string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $source = strtolower($source);
        if ($host === '') {
            return false;
        }
        if ($source === 'https:') {
            return $scheme === 'https';
        }
        if (preg_match('#^(?:([a-z][a-z0-9+.-]*)://)?(\*\.)?([a-z0-9.-]+)#', $source, $m) !== 1) {
            return false;
        }
        if ($m[1] !== '' && $m[1] !== $scheme && !($m[1] === 'http' && $scheme === 'https')) {
            return false;
        }
        return $m[2] === '' ? $host === $m[3] : str_ends_with($host, '.' . $m[3]);
    }

    /**
     * Uma lista no formato da própria CSP, separada por espaço.
     *
     * Só espaço e tab separam: uma quebra de linha fica dentro do token e o
     * derruba inteiro, em vez de virar um separador que esconderia a
     * tentativa.
     *
     * @return array{0: list<string>, 1: list<string>}  [válidas, descartadas]
     */
    public static function parseSources(string $raw): array
    {
        $valid = [];
        $invalid = [];
        foreach (preg_split('/[ \t]+/', trim($raw, " \t")) ?: [] as $token) {
            if ($token === '') {
                continue;
            }
            if (preg_match(self::SOURCE, $token) === 1) {
                $valid[] = $token;
            } else {
                $invalid[] = $token;
            }
        }
        return [$valid, $invalid];
    }

    /**
     * Os cabeçalhos da resposta, como [nome, valor].
     *
     * No `report` são dois: as travas do `open`, aplicadas, e a política do
     * `strict` só para relato. O ensaio não pode deixar o site menos
     * protegido que o padrão, nem quebrar o iframe de um parceiro que o
     * `open` libera — sem um `frame-ancestors` aplicado, vale o
     * `X-Frame-Options` do servidor.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function headers(array $s, string $nonce, bool $https): array
    {
        if ($s['mode'] === 'report') {
            return [self::build(['mode' => 'open'] + $s, $nonce, $https), self::build($s, $nonce, $https)];
        }
        $policy = self::build($s, $nonce, $https);
        return $policy === null ? [] : [$policy];
    }

    /**
     * Um cabeçalho, como [nome, valor], ou null no modo `off`.
     *
     * `upgrade-insecure-requests` só vai em https: no `http://localhost` ele
     * mandaria o navegador buscar os arquivos do próprio site em https, que
     * não existe ali. Também fica fora do `report`: o navegador ignora essa
     * diretiva num Report-Only, e reclama no console.
     *
     * @param array{mode: string, allow_eval: bool, frame_ancestors: list<string>, report_uri: string, lists: array<string, list<string>>} $s
     * @return array{0: string, 1: string}|null
     */
    public static function build(array $s, string $nonce, bool $https): ?array
    {
        if ($s['mode'] === 'off') {
            return null;
        }

        $ancestors = 'frame-ancestors ' . implode(' ', array_unique(array_merge(["'self'"], $s['frame_ancestors'])));

        if ($s['mode'] === 'open') {
            $d = [$ancestors, "base-uri 'self'"];
            if ($https) {
                $d[] = 'upgrade-insecure-requests';
            }
            return ['Content-Security-Policy', implode('; ', $d)];
        }

        $lists = $s['lists'];

        $script = ["'nonce-{$nonce}'", "'strict-dynamic'"];
        if ($s['allow_eval']) {
            $script[] = "'unsafe-eval'";
        }

        $d = [
            'script-src ' . implode(' ', $script),
            "object-src 'none'",
            "base-uri 'self'",
            // Sempre no estrito, como o base-uri: um formulário injetado não
            // manda para fora o que o visitante digitar (a senha de membro).
            'form-action ' . implode(' ', array_unique(array_merge(["'self'"], $lists['form']))),
            $ancestors,
        ];
        $prefixes = ['connect' => ["'self'"], 'img' => ["'self'", 'data:'], 'frame' => ["'self'"]];
        foreach ($prefixes as $kind => $prefix) {
            if ($lists[$kind] !== []) {
                $d[] = $kind . '-src ' . implode(' ', array_unique(array_merge($prefix, $lists[$kind])));
            }
        }
        if ($https && $s['mode'] === 'strict') {
            $d[] = 'upgrade-insecure-requests';
        }
        if ($s['report_uri'] !== '') {
            $d[] = 'report-uri ' . $s['report_uri'];
        }

        $name = $s['mode'] === 'report' ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
        return [$name, implode('; ', $d)];
    }
}
