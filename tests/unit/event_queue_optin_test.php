<?php

declare(strict_types=1);

use Velk\ClientEvents;
use Velk\Request;

/**
 * `_event=queue` (spec 2026-09-09 §6).
 *
 * Em modo JSON o `respond()` devolve o evento no CORPO e não enfileira — o
 * comentário no código explica: enfileirar nos dois dispararia duas vezes, e
 * só enfileirar dispararia tarde ou nunca num tema que não re-renderiza.
 *
 * Só que isso deixa um furo real no arranjo mais comum de conversão: tema
 * AJAX que navega para uma página de obrigado. Empurrar o evento e navegar no
 * mesmo instante corre contra o unload — o gtag do GA4 aguenta (sendBeacon),
 * uma tag HTML customizada do GTM pode não —, e a perda é silenciosa.
 *
 * Com `_event=queue` a decisão passa a ser do tema: a resposta OMITE o evento
 * do corpo e enfileira, e a próxima página dispara pelo `tracking_head()` que
 * todo tema já chama. Ou um, ou outro — nunca os dois, então o disparo duplo
 * que o desenho evita continua fechado.
 *
 * Vai nos dois controllers que têm o mesmo `respond()`: consertar metade
 * criaria dois comportamentos para o mesmo problema.
 */

require_once dirname(__DIR__) . '/fixtures/form_step_boot.php';

if (!function_exists('eqo_request')) {
    function eqo_request(array $post = []): Request
    {
        return new Request('POST', '/x', [], $post, [], [
            'REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json',
        ], []);
    }
}

test('requestedQueue: só o valor exato "queue" liga', function () {
    check_same(true, ClientEvents::requestedQueue(eqo_request(['_event' => 'queue'])));
    check_same(false, ClientEvents::requestedQueue(eqo_request()));
    check_same(false, ClientEvents::requestedQueue(eqo_request(['_event' => '1'])));
    check_same(false, ClientEvents::requestedQueue(eqo_request(['_event' => 'QUEUE'])));
    check_same(false, ClientEvents::requestedQueue(eqo_request(['_event' => ''])));
});

test('formulário: sem o campo, o evento vem no corpo e a fila fica vazia', function () {
    $app = fst_boot([], [
        ['name' => 'nome', 'type' => 'text', 'required' => true],
    ]);
    $r = fst_post($app, ['nome' => 'Ana']);

    check(isset($r['body']['event']), 'o comportamento de hoje: evento no corpo');
    check_same([], ClientEvents::drain($app->session), 'e nada na fila');
});

test('formulário: com _event=queue, o evento sai do corpo e vai para a fila', function () {
    $app = fst_boot([], [
        ['name' => 'nome', 'type' => 'text', 'required' => true],
    ]);
    $r = fst_post($app, ['nome' => 'Ana', '_event' => 'queue']);

    check_same('submitted', $r['body']['code'], 'o resto da resposta não muda');
    check(!isset($r['body']['event']), 'ou um, ou outro — nunca os dois');

    $fila = ClientEvents::drain($app->session);
    check_same(1, count($fila), 'a página de obrigado precisa achar o evento na fila');
    check_same('velk_form_success', $fila[0]['name']);
});

test('formulário: com _event=queue numa etapa intermediária não há evento nenhum', function () {
    // A conversão só existe no envio final; a etapa intermediária não tem o
    // que enfileirar nem o que devolver.
    $app = fst_boot([['nome'], ['cep']], [
        ['name' => 'nome', 'type' => 'text', 'required' => true],
        ['name' => 'cep', 'type' => 'text', 'required' => true],
    ]);
    $r = fst_post($app, ['_step' => 1, 'nome' => 'Ana', '_event' => 'queue']);

    check_same('step_saved', $r['body']['code']);
    check(!isset($r['body']['event']));
    check_same([], ClientEvents::drain($app->session));
});

test('membro: respond() com _event=queue enfileira em vez de devolver no corpo', function () {
    $app = fst_boot([], [['name' => 'nome', 'type' => 'text']]);
    $evento = ['name' => 'velk_signup_success', 'data' => ['member_id' => 7], 'snippet' => null];

    $m = new ReflectionMethod(Velk\Front\MemberController::class, 'respond');
    $res = $m->invoke(
        new Velk\Front\MemberController(),
        eqo_request(['_event' => 'queue']),
        '/',
        'success',
        'registered',
        [],
        $evento
    );

    $body = (array) json_decode($res->getBody(), true);
    check_same('registered', $body['code']);
    check(!isset($body['event']), 'saiu do corpo');

    $fila = ClientEvents::drain($app->session);
    check_same('velk_signup_success', $fila[0]['name'] ?? null, 'e foi para a fila');
});

test('membro: sem o campo, o evento segue no corpo', function () {
    $app = fst_boot([], [['name' => 'nome', 'type' => 'text']]);
    $m = new ReflectionMethod(Velk\Front\MemberController::class, 'respond');
    $res = $m->invoke(
        new Velk\Front\MemberController(),
        eqo_request(),
        '/',
        'success',
        'registered',
        [],
        ['name' => 'velk_signup_success', 'data' => [], 'snippet' => null]
    );

    $body = (array) json_decode($res->getBody(), true);
    check(isset($body['event']), 'o comportamento de hoje');
    check_same([], ClientEvents::drain($app->session));
});
