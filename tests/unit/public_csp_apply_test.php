<?php

declare(strict_types=1);

use Velk\Config;
use Velk\PublicCsp;
use Velk\Request;
use Velk\Response;

/**
 * Onde a política do site público entra: toda resposta HTML fora do painel,
 * com a senha (nonce) da página. O painel tem a política dele, uma resposta
 * que já trouxe CSP (o SVG de /uploads, com `sandbox`) fica como está, e
 * JSON, XML e redirect não precisam de uma. A política vem do `site.json`
 * (`security.csp`); do `.env`, só o coletor de relatos.
 */

require_once dirname(__DIR__, 2) . '/core/helpers.php';

if (!function_exists('pca_config')) {
    /**
     * @param array $csp  o `security.csp` do site.json
     * @param array $env  o `config('csp')`: o report_uri
     */
    function pca_config(array $csp = [], array $site = [], ?string $prefix = null, array $env = []): Config
    {
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        $envConfig = ['csp' => $env];
        if ($prefix !== null) {
            $envConfig['admin'] = ['prefix' => $prefix];
        }
        if ($csp !== []) {
            $site['security']['csp'] = $csp;
        }
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, $envConfig);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, $site);
        return $config;
    }

    /**
     * As variáveis da CSP para o ambiente: as de até a v3.8.3 vazias, mais as
     * dadas — o teste não depende do .env de quem roda.
     */
    function pca_vars(array $vars = []): array
    {
        $antigas = ['CSP_MODE', 'CSP_ALLOW_EVAL', 'CSP_FRAME_ANCESTORS', 'CSP_SCRIPT_SRC', 'CSP_CONNECT_SRC', 'CSP_IMG_SRC', 'CSP_FRAME_SRC', 'CSP_FORM_ACTION'];
        return array_merge(array_fill_keys($antigas, ''), $vars);
    }

    /** @param array $vars  variáveis de ambiente (CSP_REPORT_URI, as antigas) */
    function pca_apply(string $path, Response $response, array $csp = [], array $server = [], ?string $prefix = null, array $site = [], array $sent = [], array $vars = []): Response
    {
        $config = pca_config($csp, $site, $prefix, ['report_uri' => $vars['CSP_REPORT_URI'] ?? '']);
        return pca_with_env(pca_vars($vars), fn() => PublicCsp::apply(new Request('GET', $path, [], [], [], $server, []), $response, $config, $sent));
    }

    /** `$fn` com as variáveis dadas no ambiente; devolve o resultado. */
    function pca_with_env(array $vars, callable $fn): mixed
    {
        $old = [];
        foreach ($vars as $key => $value) {
            $old[$key] = $_ENV[$key] ?? null;
            $_ENV[$key] = $value;
        }
        try {
            return $fn();
        } finally {
            foreach ($old as $key => $value) {
                if ($value === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $value;
                }
            }
        }
    }

    /** `$fn` com as chaves do Turnstile no ambiente; devolve o resultado. */
    function pca_with_turnstile(callable $fn): mixed
    {
        $old = [$_ENV['TURNSTILE_SITE_KEY'] ?? null, $_ENV['TURNSTILE_SECRET_KEY'] ?? null];
        $_ENV['TURNSTILE_SITE_KEY'] = 'site';
        $_ENV['TURNSTILE_SECRET_KEY'] = 'secret';
        try {
            return $fn();
        } finally {
            foreach (['TURNSTILE_SITE_KEY', 'TURNSTILE_SECRET_KEY'] as $i => $key) {
                if ($old[$i] === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $old[$i];
                }
            }
        }
    }

    /** `$fn` com o error_log do PHP num arquivo; devolve o que foi registrado. */
    function pca_log(callable $fn): string
    {
        $file = tempnam(sys_get_temp_dir(), 'velk_csp_log_');
        $old = ini_set('error_log', $file);
        try {
            $fn();
        } finally {
            ini_set('error_log', $old === false ? '' : $old);
        }
        $out = (string) file_get_contents($file);
        @unlink($file);
        return $out;
    }
}

test('csp apply: uma página do site sai com a política do modo configurado', function () {
    $open = pca_apply('/', Response::html('ok'));
    check_same("frame-ancestors 'self'; base-uri 'self'", $open->getHeader('Content-Security-Policy'));

    $strict = pca_apply('/contato', Response::html('ok'), ['mode' => 'strict']);
    check(str_starts_with((string) $strict->getHeader('Content-Security-Policy'), "script-src 'nonce-"));

    $report = pca_apply('/contato', Response::html('ok', 404), ['mode' => 'report']);
    check_same("frame-ancestors 'self'; base-uri 'self'", $report->getHeader('Content-Security-Policy'));
    check(str_starts_with((string) $report->getHeader('Content-Security-Policy-Report-Only'), "script-src 'nonce-"));

    $off = pca_apply('/', Response::html('ok'), ['mode' => 'off']);
    check_same(null, $off->getHeader('Content-Security-Policy'));
    check_same(null, $off->getHeader('Content-Security-Policy-Report-Only'));
});

test('csp apply: a senha do cabeçalho é a mesma que o csp_nonce() entrega à página', function () {
    $value = (string) pca_apply('/', Response::html('ok'), ['mode' => 'strict'])->getHeader('Content-Security-Policy');
    check(str_contains($value, "'nonce-" . csp_nonce() . "'"), $value);
    check_same(PublicCsp::nonce(), csp_nonce());
});

test('csp apply: o painel fica de fora, com o prefixo configurado', function () {
    foreach (['/admin', '/admin/', '/admin/login', '/admin/scripts'] as $path) {
        check_same(null, pca_apply($path, Response::html('ok'), ['mode' => 'strict'])->getHeader('Content-Security-Policy'), $path);
    }
    check_same(null, pca_apply('/painel-x7/login', Response::html('ok'), ['mode' => 'strict'], [], '/painel-x7')->getHeader('Content-Security-Policy'));
    // Com o prefixo trocado, /admin é uma página comum do site.
    check(pca_apply('/admin/login', Response::html('ok'), ['mode' => 'strict'], [], '/painel-x7')->getHeader('Content-Security-Policy') !== null);
    // Caminho que só começa igual não é o painel.
    check(pca_apply('/administracao', Response::html('ok'), ['mode' => 'strict'])->getHeader('Content-Security-Policy') !== null);
});

test('csp apply: resposta que já tem política fica como está', function () {
    $svg = Response::html('<svg/>')->header('Content-Security-Policy', 'sandbox');
    check_same('sandbox', pca_apply('/uploads/a.svg', $svg, ['mode' => 'strict'])->getHeader('Content-Security-Policy'));

    $tema = Response::html('ok')->header('content-security-policy-report-only', "default-src 'self'");
    $out = pca_apply('/', $tema, ['mode' => 'strict']);
    check_same(null, $out->getHeader('Content-Security-Policy'));
    check_same("default-src 'self'", $out->getHeader('Content-Security-Policy-Report-Only'));
});

test('csp apply: a política que o tema mandou com header() fica, e o core não manda a sua', function () {
    // O send() substituiria a do tema pela do core: um site com política
    // própria perderia as restrições dela no upgrade, calado.
    foreach (['Content-Security-Policy: default-src \'self\'', 'content-security-policy-report-only:default-src \'self\''] as $line) {
        $out = pca_apply('/', Response::html('ok'), ['mode' => 'strict'], [], null, [], ['X-Frame-Options: SAMEORIGIN', $line]);
        check_same(null, $out->getHeader('Content-Security-Policy'), $line);
        check_same(null, $out->getHeader('Content-Security-Policy-Report-Only'), $line);
    }
    // Outro cabeçalho não conta, nem o X-Content-Security-Policy do IE antigo.
    $out = pca_apply('/', Response::html('ok'), ['mode' => 'strict'], [], null, [], ['X-Content-Security-Policy: sandbox']);
    check($out->getHeader('Content-Security-Policy') !== null);
});

test('csp apply: com o Turnstile configurado, a lista de iframes declarada já libera a Cloudflare', function () {
    $comLista = ['mode' => 'strict', 'allow' => ['frame' => ['https://www.youtube.com']]];
    $value = (string) pca_with_turnstile(fn() => pca_apply('/', Response::html('ok'), $comLista))
        ->getHeader('Content-Security-Policy');
    check(str_contains($value, "frame-src 'self' https://www.youtube.com https://challenges.cloudflare.com"), $value);
    preg_match('/script-src ([^;]*)/', $value, $m);
    check(!str_contains($m[1] ?? '', 'challenges.cloudflare.com'), 'o script do Turnstile entra pela senha e pelo strict-dynamic');

    $semLista = (string) pca_with_turnstile(fn() => pca_apply('/', Response::html('ok'), ['mode' => 'strict']))
        ->getHeader('Content-Security-Policy');
    check(!str_contains($semLista, 'challenges.cloudflare.com'), $semLista);

    $semTurnstile = (string) pca_apply('/', Response::html('ok'), $comLista)->getHeader('Content-Security-Policy');
    check(!str_contains($semTurnstile, 'challenges.cloudflare.com'), $semTurnstile);
});

test('csp apply: com assinatura ou loja ligadas, o form-action já libera o Stripe', function () {
    // Os botões de assinar, do portal e de comprar postam no próprio site,
    // que redireciona para o Stripe — e o form-action vale também para esse
    // redirecionamento.
    $formAction = function (array $site, array $env): string {
        $value = (string) pca_with_env($env, fn() => pca_apply('/', Response::html('ok'), ['mode' => 'strict'], [], null, $site)
            ->getHeader('Content-Security-Policy'));
        preg_match('/form-action ([^;]*)/', $value, $m);
        return $m[1] ?? '';
    };
    $assinatura = ['members' => ['enabled' => true, 'plans' => ['basico' => ['name' => 'Básico']]]];
    $loja = ['item_types' => ['curso' => ['sellable' => true]]];
    $stripe = ['STRIPE_SECRET_KEY' => 'sk_test_x', 'STRIPE_WEBHOOK_SECRET' => 'whsec_a', 'STRIPE_STORE_WEBHOOK_SECRET' => 'whsec_b'];

    check_same("'self' https://checkout.stripe.com https://billing.stripe.com", $formAction($assinatura, $stripe));
    check_same("'self' https://checkout.stripe.com", $formAction($loja, $stripe));
    check_same("'self' https://checkout.stripe.com https://billing.stripe.com", $formAction($assinatura + $loja, $stripe));
    // Sem o secret do webhook a assinatura fica desligada — e o Stripe fora.
    check_same("'self'", $formAction($assinatura, ['STRIPE_SECRET_KEY' => 'sk_test_x', 'STRIPE_WEBHOOK_SECRET' => '', 'STRIPE_STORE_WEBHOOK_SECRET' => '']));
    check_same("'self'", $formAction([], $stripe));
});

test('csp apply: só HTML leva a política — JSON, XML e redirect não', function () {
    foreach ([Response::json(['ok' => true]), Response::xml('<x/>'), Response::redirect('/obrigado')] as $response) {
        check_same(null, pca_apply('/', $response, ['mode' => 'strict'])->getHeader('Content-Security-Policy'));
    }
    $maiuscula = (new Response())->header('Content-Type', 'TEXT/HTML');
    check(pca_apply('/', $maiuscula, ['mode' => 'strict'])->getHeader('Content-Security-Policy') !== null);
});

test('csp apply: upgrade-insecure-requests com HTTPS direto ou atrás de proxy', function () {
    $upgrade = fn(array $server): bool => str_contains(
        (string) pca_apply('/', Response::html('ok'), [], $server)->getHeader('Content-Security-Policy'),
        'upgrade-insecure-requests'
    );
    check($upgrade(['HTTPS' => 'on']));
    check($upgrade(['HTTP_X_FORWARDED_PROTO' => 'https']));
    check(!$upgrade([]));
    check(!$upgrade(['HTTPS' => 'off']));
    check(!$upgrade(['HTTP_X_FORWARDED_PROTO' => 'http']));
});

test('csp apply: a política e as listas vêm do site.json, e o CSP_REPORT_URI do .env', function () {
    $value = (string) pca_apply('/', Response::html('ok'), ['mode' => 'strict', 'allow' => ['frame' => ['https://maps.google.com']]],
        [], null, [], [], ['CSP_REPORT_URI' => 'https://csp.example.com/r'])->getHeader('Content-Security-Policy');
    check(str_contains($value, "frame-src 'self' https://maps.google.com"), $value);
    check(str_ends_with($value, '; report-uri https://csp.example.com/r'), $value);
});

test('csp apply: configuração inválida vai para o log e o site segue no ar', function () {
    $response = null;
    $log = pca_log(function () use (&$response) {
        $response = pca_apply('/', Response::html('ok'), ['mode' => 'estrito', 'frame_ancestors' => ['https://a.com;x']]);
    });
    check_same("frame-ancestors 'self'; base-uri 'self'", $response->getHeader('Content-Security-Policy'));
    check(str_contains($log, '[Velk] CSP: site.json → security.csp.mode "estrito"'), $log);
    check(str_contains($log, '[Velk] CSP: site.json → security.csp.frame_ancestors: dropped invalid source "https://a.com;x"'), $log);
});

test('csp apply: erro numa lista só vai para o log quando pesa (strict e report); o urgente, sempre', function () {
    // No open e no off as listas não mudam o cabeçalho: um erro ali não deve
    // encher o log a cada página.
    foreach (['open', 'off'] as $mode) {
        $log = pca_log(fn() => pca_apply('/', Response::html('ok'), ['mode' => $mode, 'allow' => ['frames' => ['https://a.com']]]));
        check(!str_contains($log, 'security.csp.allow'), "{$mode}: {$log}");
    }
    $log = pca_log(fn() => pca_apply('/', Response::html('ok'), ['mode' => 'strict', 'allow' => ['frames' => ['https://a.com']]]));
    check(str_contains($log, '[Velk] CSP: site.json → security.csp.allow: unknown key "frames"'), $log);
    // Uma variável antiga no .env (um site que estava em strict pelo .env e
    // voltaria calado ao open) vai para o log em qualquer modo, off incluído.
    foreach ([[], ['mode' => 'off']] as $csp) {
        $log = pca_log(fn() => pca_apply('/', Response::html('ok'), $csp, [], null, [], [], ['CSP_MODE' => 'strict']));
        check(str_contains($log, 'CSP_MODE') && str_contains($log, 'security.csp.mode'), $log);
    }
});

test('csp apply: o config/env.php só lê o CSP_REPORT_URI; as variáveis antigas com valor, o PublicCsp lê do ambiente', function () {
    $csp = pca_with_env(pca_vars(['CSP_MODE' => 'strict', 'CSP_REPORT_URI' => 'https://csp.example.com/r']),
        fn() => (require dirname(__DIR__, 2) . '/config/env.php')['csp']);
    // Nada além do coletor: um upgrade por FTP que suba o config/ antes do
    // core/ não pode chamar um método que ainda não chegou.
    check_same(['report_uri' => 'https://csp.example.com/r'], $csp);

    $antigas = fn(array $vars): array => pca_with_env(pca_vars($vars), fn() => PublicCsp::legacyEnv());
    check_same(['CSP_MODE', 'CSP_FRAME_SRC'], $antigas(['CSP_MODE' => 'strict', 'CSP_ALLOW_EVAL' => '0', 'CSP_FRAME_SRC' => 'https://a.com']));
    // off e o frame_ancestors mudam o site em qualquer modo.
    check_same(['CSP_MODE', 'CSP_FRAME_ANCESTORS'], $antigas(['CSP_MODE' => 'off', 'CSP_FRAME_ANCESTORS' => 'https://parceiro.com']));
    // "true" chega do Env::get() como booleano.
    check_same(['CSP_ALLOW_EVAL'], $antigas(['CSP_ALLOW_EVAL' => 'true']));
    // O padrão do .env.example antigo (open, 0, vazio) não é aviso: é o que já valia.
    check_same([], $antigas(['CSP_MODE' => ' Open ', 'CSP_ALLOW_EVAL' => '0']));
});

test('csp nonce: 16 bytes em base64, o mesmo na requisição inteira, outro na seguinte', function () {
    $nonce = csp_nonce();
    check_same(24, strlen($nonce));
    check_same(16, strlen((string) base64_decode($nonce, true)));
    check_same($nonce, csp_nonce());

    $root = dirname(__DIR__, 2);
    $code = 'require ' . var_export($root . '/core/Autoloader.php', true) . '; Velk\\Autoloader::register(' . var_export($root, true) . '); '
        . 'require ' . var_export($root . '/core/helpers.php', true) . '; echo csp_nonce();';
    $outra = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code));
    check_same(24, strlen($outra));
    check($outra !== $nonce, 'cada requisição tem a sua senha');
});

test('csp_nonce_scripts: põe a senha em toda abertura <script sem uma, e preserva o resto', function () {
    $n = csp_nonce();
    check_same(
        '<p>oi</p><script nonce="' . $n . '">a()</script>'
            . '<script nonce="' . $n . '" async src="https://x.com/t.js"></script>'
            . '<SCRIPT nonce="' . $n . '">b()</SCRIPT>'
            . "<script nonce=\"{$n}\"\ntype=\"text/javascript\">c()</script>"
            . '<script nonce="' . $n . '"/>',
        csp_nonce_scripts(
            '<p>oi</p><script>a()</script>'
            . '<script async src="https://x.com/t.js"></script>'
            . '<SCRIPT>b()</SCRIPT>'
            . "<script\ntype=\"text/javascript\">c()</script>"
            . '<script/>'
        )
    );
});

test('csp_nonce_scripts: não duplica a senha do autor e não confunde data-nonce', function () {
    $n = csp_nonce();
    check_same('<script nonce="outra">a()</script>', csp_nonce_scripts('<script nonce="outra">a()</script>'));
    check_same('<script async NONCE=x>a()</script>', csp_nonce_scripts('<script async NONCE=x>a()</script>'));
    check_same(
        '<script nonce="' . $n . '" data-nonce="x">a()</script>',
        csp_nonce_scripts('<script data-nonce="x">a()</script>')
    );
});

test('csp_nonce_scripts: um <script dentro do JavaScript fica como está', function () {
    // O snippet antigo que escreve outra tag: as aspas da senha fechariam a
    // string do document.write e o bloco inteiro viraria erro de sintaxe.
    $n = csp_nonce();
    check_same(
        '<script nonce="' . $n . '">document.write("<script src=\"x.js\"><\/script>")</script>'
            . '<script nonce="' . $n . '">b("<script>")</script>',
        csp_nonce_scripts(
            '<script>document.write("<script src=\"x.js\"><\/script>")</script>'
            . '<script>b("<script>")</script>'
        )
    );
});

test('csp_nonce_scripts: nonce= dentro de uma URL não é a senha do autor', function () {
    $n = csp_nonce();
    check_same(
        '<script nonce="' . $n . '" src="https://x.com/a.js?v=1&nonce=abc"></script>',
        csp_nonce_scripts('<script src="https://x.com/a.js?v=1&nonce=abc"></script>')
    );
});

test('csp_nonce_scripts: um corpo grande demais para o PCRE sai como veio, e vai para o log', function () {
    $html = '<script>' . str_repeat('a', 2000000) . '</script>';
    $out = null;
    $log = pca_log(function () use ($html, &$out) {
        $out = csp_nonce_scripts($html);
    });
    check($out === $html, 'o HTML volta intacto');
    check(str_contains($log, '[Velk] CSP: '), $log);
});

test('csp_nonce_scripts: <noscript> e texto sem script ficam intactos', function () {
    $html = '<noscript><img src="x.gif"></noscript><div data-script="1">scripts</div>';
    check_same($html, csp_nonce_scripts($html));
});

test('csp apply: o App passa toda resposta pelo PublicCsp::apply() depois do noIndex()', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/core/App.php');
    $noIndex = strpos($src, 'AdminRouter::noIndex($this->request, $this->router->dispatch($this->request), $this->config)');
    $apply = strpos($src, '$response = PublicCsp::apply($this->request, $response, $this->config);');
    $send = strpos($src, '$response->send();');
    check($noIndex !== false && $apply !== false && $send !== false, 'App::run() não passa a resposta pelo PublicCsp::apply()');
    check($noIndex < $apply && $apply < $send, 'a política entra entre o noIndex() e o send()');
});
