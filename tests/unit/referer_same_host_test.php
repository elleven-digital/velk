<?php

declare(strict_types=1);

use Velk\Request;

/**
 * Revisão de 2026-09: Request::refererSameHost().
 *
 * Os dois backUrl() (PublicFormController e MemberController) devolviam o
 * HTTP_REFERER cru como destino de redirect. Via navegador não vira open
 * redirect — o Referer reflete a página que hospedou o formulário — mas
 * era o único ponto do engine que confiava num header sem checar, e um
 * cliente não-navegador manda o que quiser. A política agora mora aqui.
 */

if (!function_exists('rsh_req')) {
    function rsh_req(?string $referer, ?string $host = 'site.com'): Request
    {
        $server = [];
        if ($host !== null) $server['HTTP_HOST'] = $host;
        if ($referer !== null) $server['HTTP_REFERER'] = $referer;
        return new Request('POST', '/forms/x', [], [], [], $server, []);
    }
}

test('refererSameHost: mesmo host devolve o referer intacto', function () {
    check_same('https://site.com/contato?x=1', rsh_req('https://site.com/contato?x=1')->refererSameHost());
});

test('refererSameHost: outro host devolve null', function () {
    check_same(null, rsh_req('https://evil.com/phish')->refererSameHost());
});

test('refererSameHost: subdomínio é outro host', function () {
    check_same(null, rsh_req('https://app.site.com/')->refererSameHost());
});

test('refererSameHost: porta e caixa não separam', function () {
    // Proxy reescreve porta; navegador não normaliza caixa do host.
    check_same('https://Site.com:443/p', rsh_req('https://Site.com:443/p', 'site.com')->refererSameHost());
    check_same('http://site.com/p', rsh_req('http://site.com/p', 'site.com:8080')->refererSameHost());
});

test('refererSameHost: ausente, vazio, lixo e sem HTTP_HOST devolvem null', function () {
    check_same(null, rsh_req(null)->refererSameHost());
    check_same(null, rsh_req('')->refererSameHost());
    check_same(null, rsh_req('   ')->refererSameHost());
    check_same(null, rsh_req('not a url')->refererSameHost());
    check_same(null, rsh_req('javascript:alert(1)')->refererSameHost());
    check_same(null, rsh_req('https://site.com/', null)->refererSameHost(), 'sem HTTP_HOST não há com o que comparar');
});
