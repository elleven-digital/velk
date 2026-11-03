<?php

declare(strict_types=1);

use Velk\Request;
use Velk\Response;
use Velk\Router;

/**
 * HEAD is GET without a body (RFC 9110 §9.3.2). Router::dispatch() must
 * match a HEAD request against routes registered as GET — running the
 * exact same handler, producing the same status/body — while never
 * running a POST/PUT/DELETE-only handler.
 *
 * Response::send() is what actually strips the body back out at emission
 * time; that half needs a real REQUEST_METHOD/output pipeline, so it's
 * covered by the functional curl checks in the hardening report rather
 * than here.
 */

test('dispatch: HEAD casa com rota registrada via get() e roda o mesmo handler', function () {
    $router = new Router();
    $router->get('/foo', fn(Request $req, array $params) => 'hello-get');

    $response = $router->dispatch(new Request('HEAD', '/foo', [], [], [], [], []));

    check($response instanceof Response, 'deveria retornar uma Response');
    check_same(200, $response->getStatus());
    check_same('hello-get', $response->getBody());
});

test('dispatch: HEAD não roda handler registrado só via post()', function () {
    $router = new Router();
    $ran = false;
    $router->post('/bar', function (Request $req, array $params) use (&$ran) {
        $ran = true;
        return 'should-not-run';
    });

    $response = $router->dispatch(new Request('HEAD', '/bar', [], [], [], [], []));

    check_same(false, $ran, 'handler de POST não deveria rodar para HEAD');
    check_same(404, $response->getStatus());
});

test('dispatch: GET e POST continuam funcionando normalmente (regressão)', function () {
    $router = new Router();
    $router->get('/baz', fn(Request $req, array $params) => 'get-body');
    $router->post('/baz', fn(Request $req, array $params) => 'post-body');

    $getResponse = $router->dispatch(new Request('GET', '/baz', [], [], [], [], []));
    $postResponse = $router->dispatch(new Request('POST', '/baz', [], [], [], [], []));

    check_same('get-body', $getResponse->getBody());
    check_same('post-body', $postResponse->getBody());
});

test('dispatch: HEAD ainda cai no fallback quando nenhuma rota GET casa', function () {
    $router = new Router();
    $router->fallback(function (Request $req) {
        return Response::html('fallback-hit', 200);
    });

    $response = $router->dispatch(new Request('HEAD', '/nao-existe', [], [], [], [], []));

    check_same(200, $response->getStatus());
    check_same('fallback-hit', $response->getBody());
});
