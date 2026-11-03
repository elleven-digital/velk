<?php

declare(strict_types=1);

use Velk\PublicFormController;
use Velk\Request;
use Velk\Response;

/**
 * v3.4 (conversion events): `PublicFormController::respond()` — o ramo JSON.
 *
 * Só o ramo JSON é exercitado aqui, e de propósito: ele não toca sessão nem
 * App (mesmo motivo que variant_service_test isola as peças puras). O ramo
 * de redirect é o comportamento que já existia, preservado por extração
 * mecânica — a suíte inteira passando é a prova de que nada mudou lá.
 *
 * O que importa provar: o código de status, porque é nele que um tema faz
 * `if (!res.ok)`. Errar isso faz o tema tratar sucesso como falha (ou pior,
 * o contrário) sem nenhum sintoma do lado do servidor.
 */

if (!function_exists('frj_respond')) {
    function frj_respond(array $payload, bool $wantsJson = true): Response
    {
        $server = ['REQUEST_METHOD' => 'POST'];
        if ($wantsJson) {
            $server['HTTP_ACCEPT'] = 'application/json';
        }
        $request = new Request('POST', '/forms/contato', [], [], [], $server, []);

        $method = new ReflectionMethod(PublicFormController::class, 'respond');
        return $method->invoke(new PublicFormController(), $request, 'contato', '/', $payload);
    }
}

test('respond JSON: sucesso vira 200 com o payload no corpo', function () {
    $res = frj_respond(['status' => 'success', 'code' => 'submitted']);

    check_same(200, $res->getStatus());
    $body = json_decode($res->getBody(), true);
    check_same('success', $body['status']);
    check_same('submitted', $body['code']);
});

test('respond JSON: erro vira 422', function () {
    // 422 e não 400: é falha de validação/estado, não requisição malformada —
    // e é o status que o tema usa pra ramificar sem ler o corpo.
    $res = frj_respond(['status' => 'error', 'code' => 'session_expired']);

    check_same(422, $res->getStatus());
});

test('respond JSON: erro de validação carrega errors e values no corpo', function () {
    // No modo redirect isso ia pra sessão pro re-render server-side; em JSON
    // vai no corpo, mesma estrutura, e o tema redesenha do lado do cliente.
    $res = frj_respond([
        'status' => 'error',
        'code'   => 'validation_failed',
        'errors' => ['email' => ['code' => 'invalid_email']],
        'values' => ['nome' => 'Fulano'],
    ]);

    check_same(422, $res->getStatus());
    $body = json_decode($res->getBody(), true);
    check_same('invalid_email', $body['errors']['email']['code']);
    check_same('Fulano', $body['values']['nome']);
});

test('respond JSON: o sucesso falso do honeypot também é 200', function () {
    // O honeypot mente por design — pro bot, a resposta tem que ser
    // indistinguível de sucesso. Um 422 aqui entregaria que ele foi pego.
    $res = frj_respond(['status' => 'success', 'code' => 'submitted']);

    check_same(200, $res->getStatus());
});

test('respond JSON: o corpo é JSON parseável, não HTML', function () {
    // `Response` não expõe headers, então o Content-Type em si fica coberto
    // por Response::json() (que o define) — aqui prova-se o que dá pra
    // provar deste lado: que o corpo decodifica como JSON.
    $res = frj_respond(['status' => 'success', 'code' => 'submitted']);

    $body = json_decode($res->getBody(), true);
    check(is_array($body), 'corpo deveria decodificar como JSON');
    check_same('submitted', $body['code']);
});
