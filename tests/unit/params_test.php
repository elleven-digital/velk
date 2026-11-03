<?php

declare(strict_types=1);

use Velk\Params;
use Velk\Request;
use Velk\Session;

/**
 * v4 (params): `Velk\Params` — captura de parâmetros de rastreamento.
 *
 * Funde as antigas Velk\Utm e Velk\ClickIds, que eram a mesma classe com
 * constantes diferentes. O que os testes precisam travar aqui são as duas
 * propriedades que não são óbvias: a whitelist ser fechada (percorrida, não
 * consultada) e o last-touch valer POR GRUPO.
 */

if (!function_exists('pm_capture')) {
    /** Simula um GET com a query dada e devolve o que ficou atribuído. */
    function pm_capture(array $query, ?Session $session = null): array
    {
        $s = $session ?? new Session();
        $req = new Request('GET', '/', $query, [], [], ['REQUEST_METHOD' => 'GET'], []);
        Params::captureFromRequest($req, $s);
        return Params::fromSession($s);
    }
}

if (!function_exists('pm_session')) {
    function pm_session(): Session
    {
        $_SESSION = [];
        return new Session();
    }
}

// ─── whitelist fechada ────────────────────────────────────────────────────

test('captura os parâmetros da whitelist', function () {
    $_SESSION = [];
    check_same(
        ['utm_source' => 'google', 'utm_medium' => 'cpc', 'gclid' => 'abc123'],
        pm_capture(['utm_source' => 'google', 'utm_medium' => 'cpc', 'gclid' => 'abc123'])
    );
});

test('parâmetro fora da whitelist é ignorado como se não existisse', function () {
    // Propriedade estrutural: o laço percorre a WHITELIST, nunca a query
    // string. Capturar chave arbitrária é impossível, não "rejeitado" —
    // é o que impede ?cpf=... virar dado persistido.
    $_SESSION = [];
    check_same(
        ['utm_source' => 'google'],
        pm_capture(['utm_source' => 'google', 'cpf' => '000.000.000-00', 'ref' => 'x'])
    );
});

test('valor vazio ou não-string não é capturado', function () {
    $_SESSION = [];
    check_same([], pm_capture(['utm_source' => '   ', 'gclid' => '']));
    check_same([], pm_capture(['utm_source' => ['array']]));
});

test('valor é truncado em 255 caracteres', function () {
    $_SESSION = [];
    $out = pm_capture(['utm_campaign' => str_repeat('a', 400)]);
    check_same(255, strlen($out['utm_campaign']));
});

test('POST não captura — só GET', function () {
    $_SESSION = [];
    $s = new Session();
    $req = new Request('POST', '/', ['utm_source' => 'google'], [], [], ['REQUEST_METHOD' => 'POST'], []);
    Params::captureFromRequest($req, $s);
    check_same([], Params::fromSession($s));
});

// ─── last-touch POR GRUPO ─────────────────────────────────────────────────

test('last-touch: nova UTM substitui a UTM inteira, não mescla', function () {
    // Não existe "source de segunda + campaign de sexta": atribuição dupla
    // é atribuição errada.
    $s = pm_session();
    pm_capture(['utm_source' => 'newsletter', 'utm_campaign' => 'antiga'], $s);
    $out = pm_capture(['utm_source' => 'google'], $s);

    check_same(['utm_source' => 'google'], $out);
});

test('chegar só com click ID NÃO apaga a UTM já atribuída', function () {
    // O caso que decide o desenho de grupos. UTM diz a campanha, click ID
    // diz o clique — vidas independentes. Um last-touch único zeraria a
    // origem e o lead sairia com gclid sem campanha.
    $s = pm_session();
    pm_capture(['utm_source' => 'newsletter', 'utm_medium' => 'email'], $s);
    $out = pm_capture(['gclid' => 'abc123'], $s);

    check_same(
        ['utm_source' => 'newsletter', 'utm_medium' => 'email', 'gclid' => 'abc123'],
        $out
    );
});

test('chegar só com UTM NÃO apaga o click ID já atribuído', function () {
    $s = pm_session();
    pm_capture(['gclid' => 'abc123'], $s);
    $out = pm_capture(['utm_source' => 'newsletter'], $s);

    check_same(['utm_source' => 'newsletter', 'gclid' => 'abc123'], $out);
});

test('novo click ID substitui o grupo inteiro de click IDs', function () {
    $s = pm_session();
    pm_capture(['gclid' => 'antigo', 'fbclid' => 'meta'], $s);
    $out = pm_capture(['msclkid' => 'novo'], $s);

    check_same(['msclkid' => 'novo'], $out);
});

test('visita sem parâmetro nenhum preserva a atribuição', function () {
    // Sobrevive à navegação: cai na landing com utm, preenche o form três
    // páginas depois.
    $s = pm_session();
    pm_capture(['utm_source' => 'google', 'gclid' => 'abc'], $s);
    $out = pm_capture([], $s);

    check_same(['utm_source' => 'google', 'gclid' => 'abc'], $out);
});

// ─── leitura ──────────────────────────────────────────────────────────────

test('fromSession devolve vazio quando nada foi capturado', function () {
    check_same([], Params::fromSession(pm_session()));
});

test('fromSession ignora lixo na sessão', function () {
    // A sessão é do usuário; nunca confie na forma do que está lá.
    $_SESSION = [];
    $s = new Session();
    $_SESSION['velk_params'] = ['utm_source' => ['array'], 'cpf' => 'x', 'gclid' => 'ok'];

    check_same(['gclid' => 'ok'], Params::fromSession($s));
});

test('hasAny e clear', function () {
    $s = pm_session();
    check(!Params::hasAny($s));
    pm_capture(['utm_source' => 'google'], $s);
    check(Params::hasAny($s));
    Params::clear($s);
    check(!Params::hasAny($s));
});

test('WHITELIST é a união dos dois grupos, sem duplicata', function () {
    $expected = count(Params::UTM_KEYS) + count(Params::CLICK_ID_KEYS);
    check_same($expected, count(Params::WHITELIST));
    check_same($expected, count(array_unique(Params::WHITELIST)));
});
