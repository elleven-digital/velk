<?php

declare(strict_types=1);

use Velk\Request;

/**
 * v3.4 (conversion events): `Request::wantsJson()` — o interruptor que decide
 * se um controller público responde JSON ou redireciona.
 *
 * Errar aqui é caro dos dois lados: matchar demais quebra TODO tema existente
 * (que espera redirect), matchar de menos deixa o suporte a AJAX inerte. Daí
 * a bateria de headers reais abaixo — navegador, curl, fetch e jQuery.
 *
 * Convenção escolhida: header `Accept`, não `X-Requested-With`. O
 * `Request::isAjax()` que já existia cobre o segundo (era jQuery), mas
 * `fetch()` não manda esse header sozinho, então ele não serve pra decidir
 * isso em código moderno.
 */

if (!function_exists('rwj_request')) {
    /** Monta um Request só com o header Accept que interessa. */
    function rwj_request(?string $accept): Request
    {
        $server = ['REQUEST_METHOD' => 'POST'];
        if ($accept !== null) {
            $server['HTTP_ACCEPT'] = $accept;
        }
        return new Request('POST', '/forms/contato', [], [], [], $server, []);
    }
}

test('wantsJson: Accept application/json explícito', function () {
    check(rwj_request('application/json')->wantsJson());
});

test('wantsJson: fetch com Accept json entre outros tipos', function () {
    // Shape que fetch produz quando o tema passa headers: {Accept: 'application/json'}
    check(rwj_request('application/json, text/plain, */*')->wantsJson());
});

test('wantsJson: jQuery com dataType json', function () {
    check(rwj_request('application/json, text/javascript, */*; q=0.01')->wantsJson());
});

test('wantsJson: navegador comum NÃO quer json', function () {
    // É este o caso que protege todo tema existente: um POST de <form> normal
    // precisa continuar caindo no redirect.
    check(!rwj_request('text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8')->wantsJson());
});

test('wantsJson: curl (*/*) NÃO quer json', function () {
    check(!rwj_request('*/*')->wantsJson());
});

test('wantsJson: header ausente ou vazio NÃO quer json', function () {
    check(!rwj_request(null)->wantsJson());
    check(!rwj_request('')->wantsJson());
});

test('wantsJson: case-insensitive', function () {
    // Valor de header não é garantidamente lowercase; não vale falhar por isso.
    check(rwj_request('Application/JSON')->wantsJson());
});

test('wantsJson: application/ld+json não conta como pedido de json', function () {
    // Sub-string ingênua casaria — e um Accept de JSON-LD não é um cliente
    // pedindo a resposta JSON deste controller.
    check(!rwj_request('application/ld+json')->wantsJson());
});
