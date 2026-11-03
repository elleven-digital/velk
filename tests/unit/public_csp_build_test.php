<?php

declare(strict_types=1);

use Velk\PublicCsp;

/**
 * A política de conteúdo do site público, montada a partir do `site.json`
 * (`security.csp`). Do `.env` só vem o coletor de relatos (`CSP_REPORT_URI`).
 *
 * `open` é o padrão e não pode quebrar nada: só `frame-ancestors` e
 * `base-uri`. `strict` troca o `'unsafe-inline'` pela senha da página mais
 * `'strict-dynamic'`, e restringe para onde um formulário envia. `report` é
 * o `strict` sem bloquear. As listas de domínios só restringem o recurso
 * quando declaradas, e cada fonte é conferida, porque um `;` ou uma quebra
 * de linha injetaria uma diretiva ou um cabeçalho novo.
 */

if (!function_exists('pcb_policy')) {
    /** O cabeçalho para o bloco `security.csp` dado. */
    function pcb_policy(array $csp = [], bool $https = true, array $env = [], array $core = []): ?array
    {
        return PublicCsp::build(PublicCsp::resolve($csp, $env, $core)['settings'], 'N0NCE', $https);
    }

    /** As diretivas de um valor de cabeçalho, indexadas pelo nome. */
    function pcb_directives(string $value): array
    {
        $out = [];
        foreach (explode('; ', $value) as $directive) {
            $parts = explode(' ', $directive, 2);
            $out[$parts[0]] = $parts[1] ?? '';
        }
        return $out;
    }
}

test('csp: sem o bloco no site.json, o modo é open e só trava iframe e <base>', function () {
    check_same(
        ['Content-Security-Policy', "frame-ancestors 'self'; base-uri 'self'; upgrade-insecure-requests"],
        pcb_policy([])
    );
    check_same('open', PublicCsp::resolve(null)['settings']['mode']);
    check_same([], PublicCsp::resolve(null)['problems']);
});

test('csp: open não restringe script, imagem, conexão, iframe nem formulário, mesmo com listas', function () {
    [, $value] = pcb_policy(['mode' => 'open', 'allow' => ['frame' => ['https://b.com'], 'form' => ['https://c.com']]]);
    foreach (['script-src', 'connect-src', 'img-src', 'frame-src', 'form-action', 'default-src', 'object-src'] as $name) {
        check(!array_key_exists($name, pcb_directives($value)), $name);
    }
});

test('csp: upgrade-insecure-requests só vai em https', function () {
    check_same("frame-ancestors 'self'; base-uri 'self'", pcb_policy([], false)[1]);
    check(!str_contains(pcb_policy(['mode' => 'strict'], false)[1], 'upgrade-insecure-requests'));
    check(str_contains(pcb_policy(['mode' => 'strict'], true)[1], 'upgrade-insecure-requests'));
});

test('csp: frame_ancestors soma ao self, nos dois modos', function () {
    foreach (['open', 'strict'] as $mode) {
        [, $value] = pcb_policy(['mode' => $mode, 'frame_ancestors' => ['https://parceiro.com', 'https://*.cliente.com']]);
        check_same("'self' https://parceiro.com https://*.cliente.com", pcb_directives($value)['frame-ancestors'], $mode);
    }
});

test('csp: strict usa senha + strict-dynamic, sem unsafe-inline, e restringe formulários', function () {
    [$name, $value] = pcb_policy(['mode' => 'strict']);
    check_same('Content-Security-Policy', $name);
    check_same(
        "script-src 'nonce-N0NCE' 'strict-dynamic'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'; upgrade-insecure-requests",
        $value
    );
    check(!str_contains($value, 'unsafe-inline'));
    check(!str_contains($value, 'default-src'));
});

test('csp: allow_eval true libera o eval no strict; outro valor vira problema', function () {
    check_same("'nonce-N0NCE' 'strict-dynamic' 'unsafe-eval'", pcb_directives(pcb_policy(['mode' => 'strict', 'allow_eval' => true])[1])['script-src']);
    check_same("'nonce-N0NCE' 'strict-dynamic'", pcb_directives(pcb_policy(['mode' => 'strict', 'allow_eval' => false])[1])['script-src']);

    $r = PublicCsp::resolve(['mode' => 'strict', 'allow_eval' => 'sim']);
    check_same(false, $r['settings']['allow_eval']);
    check_same(1, count($r['problems']));
    check(str_contains($r['problems'][0], 'security.csp.allow_eval'), $r['problems'][0]);
    check_same([], $r['urgent'], 'o eval só pesa no strict e no report');
});

test('csp: uma lista declarada restringe o recurso ao site e aos domínios dela; ausente ou vazia, fica livre', function () {
    $d = pcb_directives(pcb_policy(['mode' => 'strict', 'allow' => [
        'connect' => ['https://*.google-analytics.com'],
        'img' => ['https://*.googleusercontent.com'],
        'frame' => ['https://www.youtube.com'],
    ]])[1]);
    check_same("'self' https://*.google-analytics.com", $d['connect-src']);
    check_same("'self' data: https://*.googleusercontent.com", $d['img-src']);
    check_same("'self' https://www.youtube.com", $d['frame-src']);

    $livre = pcb_directives(pcb_policy(['mode' => 'strict', 'allow' => ['img' => []]])[1]);
    foreach (['connect-src', 'img-src', 'frame-src'] as $name) {
        check(!array_key_exists($name, $livre), $name);
    }

    // Só o próprio site: 'self' na lista.
    check_same("'self' data:", pcb_directives(pcb_policy(['mode' => 'strict', 'allow' => ['img' => ["'self'"]]])[1])['img-src']);

    // Um texto no lugar da lista vale, separado por espaço, como no .env antigo.
    $r = PublicCsp::resolve(['mode' => 'strict', 'allow' => ['frame' => 'https://a.com https://b.com'], 'frame_ancestors' => 'https://c.com']);
    check_same([], $r['problems']);
    check_same(['https://a.com', 'https://b.com'], $r['settings']['lists']['frame']);
    check_same(['https://c.com'], $r['settings']['frame_ancestors']);
});

test('csp: report manda as travas do open aplicadas e o strict só relatado, sem o upgrade', function () {
    $csp = ['mode' => 'report', 'frame_ancestors' => ['https://parceiro.com'], 'allow' => ['frame' => ['https://www.youtube.com']]];
    $s = PublicCsp::resolve($csp, ['report_uri' => 'https://csp.example.com/r'])['settings'];
    [$aplicado, $relato] = PublicCsp::headers($s, 'N0NCE', true);
    check_same(['Content-Security-Policy', "frame-ancestors 'self' https://parceiro.com; base-uri 'self'; upgrade-insecure-requests"], $aplicado);
    check_same('Content-Security-Policy-Report-Only', $relato[0]);
    $strict = PublicCsp::build(['mode' => 'strict'] + $s, 'N0NCE', true)[1];
    check_same(str_replace('; upgrade-insecure-requests', '', $strict), $relato[1]);
    check(!str_contains($relato[1], 'upgrade-insecure-requests'));
});

test('csp: nos outros modos, o cabeçalho do build(), ou nenhum', function () {
    foreach (['open', 'strict'] as $mode) {
        $s = PublicCsp::resolve(['mode' => $mode])['settings'];
        check_same([PublicCsp::build($s, 'N', false)], PublicCsp::headers($s, 'N', false), $mode);
    }
    check_same(null, pcb_policy(['mode' => 'off', 'allow' => ['frame' => ['https://a.com']]]));
    check_same([], PublicCsp::headers(PublicCsp::resolve(['mode' => 'off'])['settings'], 'N', true));
});

test('csp: CSP_REPORT_URI vem do .env, vai no fim, e só aceita uma URL https', function () {
    check(str_ends_with(pcb_policy(['mode' => 'strict'], true, ['report_uri' => 'https://csp.example.com/r'])[1], '; report-uri https://csp.example.com/r'));
    foreach (['http://csp.example.com/r', 'https://a.com/r; script-src *', '/r', 'https://a.com/r https://b.com/r'] as $uri) {
        $r = PublicCsp::resolve(['mode' => 'strict'], ['report_uri' => $uri]);
        check_same('', $r['settings']['report_uri'], $uri);
        check(!str_contains((string) PublicCsp::build($r['settings'], 'N', true)[1], 'report-uri'), $uri);
        check_same(1, count($r['problems']), $uri);
    }
});

test('csp: modo desconhecido cai no open e é um problema urgente; maiúscula e espaço são tolerados', function () {
    $r = PublicCsp::resolve(['mode' => 'estrito']);
    check_same('open', $r['settings']['mode']);
    check_same(1, count($r['problems']));
    check(str_contains($r['problems'][0], 'security.csp.mode'), $r['problems'][0]);
    check_same($r['problems'], $r['urgent'], 'um modo errado desliga a política que o autor quis: vai para o log em qualquer modo');

    check_same('strict', PublicCsp::resolve(['mode' => ' Strict '])['settings']['mode']);
    check_same([], PublicCsp::resolve(['mode' => ' Strict '])['problems']);

    // Um modo que não é texto também cai no open — sem derrubar a página.
    foreach ([true, 1, ['strict']] as $mode) {
        $r = PublicCsp::resolve(['mode' => $mode]);
        check_same('open', $r['settings']['mode'], var_export($mode, true));
        check_same(1, count($r['urgent']), var_export($mode, true));
    }
});

test('csp: o bloco security.csp tem de ser um objeto, com as chaves conhecidas', function () {
    foreach ([['strict'], 'strict'] as $csp) {
        $r = PublicCsp::resolve($csp);
        check_same('open', $r['settings']['mode']);
        check_same(1, count($r['problems']));
        check(str_contains($r['problems'][0], 'security.csp must be an object'), $r['problems'][0]);
        check_same($r['problems'], $r['urgent']);
    }

    // Um "modo" digitado errado deixaria o site no open sem ninguém perceber.
    $r = PublicCsp::resolve(['modo' => 'strict']);
    check_same(1, count($r['problems']));
    check(str_contains($r['problems'][0], 'unknown key "modo"'), $r['problems'][0]);
    check_same($r['problems'], $r['urgent']);

    // O coletor de relatos segue no .env, que muda por ambiente.
    $r = PublicCsp::resolve(['mode' => 'strict', 'report_uri' => 'https://csp.example.com/r']);
    check_same('', $r['settings']['report_uri']);
    check_same(1, count($r['problems']));
    check(str_contains($r['problems'][0], 'CSP_REPORT_URI'), $r['problems'][0]);
    check_same($r['problems'], $r['urgent']);
});

test('csp: security.csp.allow tem de ser um objeto por recurso, com as chaves conhecidas', function () {
    foreach ([['https://a.com'], 'https://a.com'] as $allow) {
        $r = PublicCsp::resolve(['mode' => 'strict', 'allow' => $allow]);
        check_same(1, count($r['problems']), var_export($allow, true));
        check(str_contains($r['problems'][0], 'security.csp.allow must be an object'), $r['problems'][0]);
    }

    $r = PublicCsp::resolve(['mode' => 'strict', 'allow' => ['frames' => ['https://a.com'], 'form' => ['https://b.com']]]);
    check_same(1, count($r['problems']));
    check(str_contains($r['problems'][0], 'unknown key "frames"'), $r['problems'][0]);
    check_same([], $r['urgent'], 'a lista só pesa no strict e no report');

    $r = PublicCsp::resolve(['mode' => 'strict', 'allow' => ['script' => ['https://www.googletagmanager.com']]]);
    check_same(1, count($r['problems']));
    check(str_contains($r['problems'][0], "page's nonce"), 'a lista de scripts saiu: a senha cobre os scripts');
    check(str_contains(pcb_directives(pcb_policy(['mode' => 'strict', 'allow' => ['script' => ['https://a.com']]])[1])['script-src'], "'strict-dynamic'"));
});

test('csp: fontes válidas passam', function () {
    $validas = [
        'https:', "'self'", 'example.com', '*.example.com', 'https://example.com', 'https://*.example.com',
        'http://example.com', 'wss://live.example.com', 'https://example.com:8443', 'https://example.com:*',
        'https://example.com/js/', 'https://www.google-analytics.com/g/collect', 'localhost:8000',
    ];
    [$ok, $bad] = PublicCsp::parseSources(implode(' ', $validas));
    check_same($validas, $ok);
    check_same([], $bad);
});

test('csp: ponto e vírgula, vírgula, aspas e quebra de linha derrubam a fonte', function () {
    $invalidas = [
        'https://a.com;', 'script-src;*', 'https://a.com,https://b.com', "'unsafe-inline'", "'unsafe-eval'",
        '"https://a.com"', "https://a.com\r\nSet-Cookie:x", '*', 'data:', 'javascript:', "'nonce-abc'",
    ];
    [$ok, $bad] = PublicCsp::parseSources(implode(' ', $invalidas));
    check_same([], $ok);
    check_same($invalidas, $bad);
});

test('csp: a fonte inválida cai da diretiva e vira problema, com o caminho no site.json', function () {
    $r = PublicCsp::resolve(['mode' => 'strict', 'allow' => ['frame' => ['https://www.youtube.com', 'https://x.com;script-src', 42]]]);
    check_same(['https://www.youtube.com'], $r['settings']['lists']['frame']);
    check_same(2, count($r['problems']));
    foreach ($r['problems'] as $problem) {
        check(str_contains($problem, 'security.csp.allow.frame'), $problem);
    }
    check_same([], $r['urgent'], 'a lista só pesa no strict e no report');
    [, $value] = PublicCsp::build($r['settings'], 'N', true);
    check(!str_contains($value, 'x.com'));

    $r = PublicCsp::resolve(['mode' => 'strict', 'frame_ancestors' => ['https://a.com;x', 42]]);
    check_same([], $r['settings']['frame_ancestors']);
    check_same(2, count($r['problems']));
    check(str_contains($r['problems'][0], 'security.csp.frame_ancestors'), $r['problems'][0]);
    check_same($r['problems'], $r['urgent'], 'o frame-ancestors vale até no open');
});

test('csp: um objeto no lugar de uma lista é problema, e a lista fica de fora', function () {
    $r = PublicCsp::resolve(['mode' => 'strict', 'frame_ancestors' => ['a' => 'https://x.com'], 'allow' => ['frame' => ['yt' => 'https://www.youtube.com']]]);
    check_same([], $r['settings']['frame_ancestors']);
    check_same([], $r['settings']['lists']['frame']);
    check_same(2, count($r['problems']));
    check(str_contains($r['problems'][0], 'security.csp.frame_ancestors must be a list'), $r['problems'][0]);
    check(str_contains($r['problems'][1], 'security.csp.allow.frame must be a list'), $r['problems'][1]);
    check_same([$r['problems'][0]], $r['urgent']);
});

test('csp form-action: no strict e no report sempre; o open, não', function () {
    check_same("'self'", pcb_directives(pcb_policy(['mode' => 'strict'])[1])['form-action']);

    $s = PublicCsp::resolve(['mode' => 'report'])['settings'];
    [$aplicado, $relato] = PublicCsp::headers($s, 'N', true);
    check(!array_key_exists('form-action', pcb_directives($aplicado[1])), 'o open aplicado do report não restringe formulário');
    check_same("'self'", pcb_directives($relato[1])['form-action']);

    check(!array_key_exists('form-action', pcb_directives(pcb_policy(['mode' => 'open'])[1])));
});

test('csp form-action: o allow.form soma destinos, e o que o core usa (o Stripe) entra uma vez só', function () {
    $core = ['form' => ['https://checkout.stripe.com', 'https://billing.stripe.com', 'https://checkout.stripe.com']];
    $r = PublicCsp::resolve(['mode' => 'strict', 'allow' => ['form' => ['https://*.list-manage.com']]], [], $core);
    check_same(['https://*.list-manage.com', 'https://checkout.stripe.com', 'https://billing.stripe.com'], $r['settings']['lists']['form']);
    check_same(
        "'self' https://*.list-manage.com https://checkout.stripe.com https://billing.stripe.com",
        pcb_directives(PublicCsp::build($r['settings'], 'N', true)[1])['form-action']
    );
    check_same(['https://checkout.stripe.com', 'https://billing.stripe.com'], PublicCsp::resolve(['mode' => 'strict'], [], $core)['settings']['lists']['form']);
});

test('csp: o que o core carrega (o Turnstile) só entra na lista de iframes quando ela está declarada', function () {
    $core = ['frame' => ['https://challenges.cloudflare.com']];
    check_same(
        ['https://www.youtube.com', 'https://challenges.cloudflare.com'],
        PublicCsp::resolve(['mode' => 'strict', 'allow' => ['frame' => ['https://www.youtube.com']]], [], $core)['settings']['lists']['frame']
    );
    check_same([], PublicCsp::resolve(['mode' => 'strict'], [], $core)['settings']['lists']['frame'], 'sem a lista, o iframe já é livre');
    // Uma lista vazia é o mesmo que nenhuma: o Turnstile sozinho nela
    // bloquearia todos os outros iframes.
    check_same([], PublicCsp::resolve(['mode' => 'strict', 'allow' => ['frame' => []]], [], $core)['settings']['lists']['frame']);
});

test('csp: uma variável antiga do .env (v3.8.1–v3.8.3) vira aviso urgente, com o lugar novo dela', function () {
    $r = PublicCsp::resolve(null, ['legacy' => ['CSP_MODE', 'CSP_FRAME_SRC', 'CSP_SCRIPT_SRC']]);
    check_same('open', $r['settings']['mode'], 'o .env não é mais lido');
    check_same(3, count($r['problems']));
    check(str_contains($r['problems'][0], 'CSP_MODE') && str_contains($r['problems'][0], 'security.csp.mode'), $r['problems'][0]);
    check(str_contains($r['problems'][1], 'CSP_FRAME_SRC') && str_contains($r['problems'][1], 'security.csp.allow.frame'), $r['problems'][1]);
    check(str_contains($r['problems'][2], 'CSP_SCRIPT_SRC') && str_contains($r['problems'][2], "page's nonce"), $r['problems'][2]);
    check_same($r['problems'], $r['urgent']);
});

test('csp sourceMatches: a fonte da CSP cobre o destino de um formulário', function () {
    check(PublicCsp::sourceMatches('https:', 'https://qualquer.com/x'));
    check(!PublicCsp::sourceMatches('https:', 'http://qualquer.com/x'));
    check(PublicCsp::sourceMatches('https://www.rdstation.com', 'https://www.rdstation.com/form'));
    check(PublicCsp::sourceMatches('www.rdstation.com', 'https://WWW.RDSTATION.COM/form'));
    check(!PublicCsp::sourceMatches('https://www.rdstation.com', 'https://rdstation.com/form'));
    check(PublicCsp::sourceMatches('*.list-manage.com', 'https://us1.list-manage.com/subscribe'));
    check(!PublicCsp::sourceMatches('*.list-manage.com', 'https://list-manage.com/subscribe'));
    check(PublicCsp::sourceMatches('http://a.com', 'https://a.com/x'), 'uma fonte http cobre a mesma origem em https');
    check(!PublicCsp::sourceMatches('https://a.com', 'http://a.com/x'));
    check(!PublicCsp::sourceMatches("'self'", 'https://a.com/x'), "'self' depende do site, não se resolve aqui");
});
