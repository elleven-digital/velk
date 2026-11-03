<?php

declare(strict_types=1);

use Velk\ClientEvents;

/**
 * v3.4 (conversion events): `ClientEvents::render()` — o HTML emitido dentro
 * do `tracking_head()`.
 *
 * Pura: recebe entradas, devolve string. O que importa provar aqui é a
 * ORDEM e o ISOLAMENTO dos blocos, mais o escape — os três pontos onde errar
 * quebra em silêncio.
 */

if (!function_exists('cer_render')) {
    function cer_render(array $entries): string
    {
        return ClientEvents::render($entries);
    }
}

if (!function_exists('cer_entry')) {
    function cer_entry(array $data = [], ?string $snippet = null, string $name = 'velk_form_success'): array
    {
        return ['name' => $name, 'data' => $data, 'snippet' => $snippet, 'queued_at' => 1000];
    }
}

test('render: fila vazia não emite nada', function () {
    // Nem um <script> vazio — toda página do site passa por aqui.
    check_same('', cer_render([]));
});

test('render: declara a variável e empurra pro dataLayer', function () {
    $html = cer_render([cer_entry(['form_id' => 'orcamento', 'submission_id' => 12])]);

    check(str_contains($html, 'window.velkFormSuccess'), 'deveria declarar velkFormSuccess');
    check(str_contains($html, '"form_id":"orcamento"'), 'deveria carregar o form_id');
    check(str_contains($html, 'velk_form_success'), 'deveria nomear o evento no push');
});

test('render: o guard do dataLayer vem ANTES do push', function () {
    // É o que faz a ordem em relação ao snippet do GTM não importar: se o
    // push chegar primeiro, o GTM processa a fila ao inicializar.
    $html = cer_render([cer_entry(['form_id' => 'x'])]);

    $guard = strpos($html, 'window.dataLayer = window.dataLayer || []');
    $push  = strpos($html, '.push(');
    check($guard !== false, 'guard do dataLayer deveria existir');
    check($push !== false, 'push deveria existir');
    check($guard < $push, 'o guard tem que vir antes do push');
});

test('render: o snippet sai num <script> SEPARADO, depois do bloco do core', function () {
    // Requisito, não estilo: se o gestor colar JS com erro de sintaxe, o
    // navegador descarta o bloco INTEIRO. No mesmo bloco, levaria o push do
    // core junto. try/catch não salva — erro de sintaxe acontece na leitura.
    $html = cer_render([cer_entry(['form_id' => 'x'], 'gtag("event","conversion");')]);

    check_same(2, substr_count($html, '<script'), 'deveria haver exatamente 2 blocos');
    $core    = strpos($html, 'window.velkFormSuccess');
    $snippet = strpos($html, 'gtag("event","conversion")');
    check($core < $snippet, 'o core declara a variável antes do snippet lê-la');
});

test('render: sem snippet, só um bloco', function () {
    $html = cer_render([cer_entry(['form_id' => 'x'], null)]);
    check_same(1, substr_count($html, '<script'));
});

test('render: </script> nos dados NÃO escapa do bloco', function () {
    // Vetor real: valores de utm vêm da query string, ou seja, são
    // controlados por quem monta o link. Sem JSON_HEX_TAG isto fecharia o
    // <script> e o resto viraria HTML executável.
    $html = cer_render([cer_entry([
        'params' => ['utm_source' => '</script><img src=x onerror=alert(1)>'],
    ])]);

    check(!str_contains($html, '</script><img'), 'não pode haver breakout do bloco');
    check(str_contains($html, '<'), 'o < deveria sair escapado como \\u003C');
});

test('render: aspas e & nos dados saem escapados', function () {
    $html = cer_render([cer_entry(['user_data' => ['email' => 'a"b&c@x.com']])]);

    check(!str_contains($html, 'a"b&c@x.com'), 'não deveria aparecer cru');
});

test('render: o nome da variável deriva do nome do evento', function () {
    // velk_signup_success → velkSignupSuccess. Mecânico e previsível, pra o
    // contrato do snippet valer igual em todo produtor.
    $html = cer_render([cer_entry(['member_id' => 7], null, 'velk_signup_success')]);

    check(str_contains($html, 'window.velkSignupSuccess'), 'deveria declarar velkSignupSuccess');
    check(str_contains($html, 'velk_signup_success'), 'deveria nomear o evento');
});

test('render: dois eventos emitem os dois', function () {
    $html = cer_render([
        cer_entry(['form_id' => 'a'], null, 'velk_form_success'),
        cer_entry(['member_id' => 7], null, 'velk_signup_success'),
    ]);

    check(str_contains($html, 'window.velkFormSuccess'));
    check(str_contains($html, 'window.velkSignupSuccess'));
});

test('render: UTF-8 inválido nos dados não emite JS quebrado', function () {
    // json_encode devolve false com bytes inválidos, e um cast cego pra string
    // emitiria `window.velkFormSuccess = ;` — erro de sintaxe que faz o
    // navegador descartar o bloco INTEIRO, perdendo a conversão em silêncio.
    // Via real: user_data vem cru do POST (buildUserData só apara espaço), então
    // um campo `tel` mapeado com byte inválido chega aqui. O utm não chega —
    // Utm/ClickIds já passam por mb_substr, que substitui os bytes.
    $html = cer_render([cer_entry(['user_data' => ['phone_number' => "119\xFF\xFE"]])]);

    check(!str_contains($html, '= ;'), 'nunca pode emitir atribuição vazia');
    check(str_contains($html, 'window.velkFormSuccess ='), 'o evento ainda deve ser emitido');
    check(str_contains($html, 'window.dataLayer.push'), 'e o push deve continuar lá');
});

test('render: payload sem atribuição alguma é preferível a JS quebrado', function () {
    // Cinto-e-suspensório: se mesmo com substituição o encode falhar, a entrada
    // é pulada por inteiro. Melhor não emitir do que emitir bloco que quebra.
    $html = cer_render([cer_entry(['x' => fopen('php://memory', 'r')])]);

    check(!str_contains($html, '= ;'), 'nada de atribuição vazia');
});

test('render: mapa vazio vindo como objeto sai {}; lista de verdade sai []', function () {
    // Quem sabe que utm/click_ids/user_data são MAPAS é o produtor, e é lá
    // que eles viram (object) — sem isso um vazio sairia `[]` e o tipo
    // mudaria entre uma conversão com campanha e uma direta, quebrando a
    // estabilidade em que um container GTM reusável se apoia.
    //
    // O render NÃO força objeto em tudo de propósito: um produtor futuro com
    // uma lista real (itens de uma compra) precisa que ela continue lista.
    $html = cer_render([cer_entry([
        'params' => (object) [],
        'items' => ['a', 'b'],
    ])]);

    check(str_contains($html, '"params":{}'), 'mapa vazio deveria sair {}');
    check(str_contains($html, '"items":["a","b"]'), 'lista deveria continuar lista');
});

test('render: entrada com nome de evento inválido é ignorada', function () {
    // O nome vira identificador JS. Nunca interpole algo que não case com o
    // formato esperado — a sessão é do usuário.
    check_same('', cer_render([cer_entry(['x' => 1], null, 'nao;valido()')]));
    check_same('', cer_render([cer_entry(['x' => 1], null, '')]));
});
