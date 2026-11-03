<?php

declare(strict_types=1);

use Velk\ClientEvents;
use Velk\Session;

/**
 * v3.4 (conversion events): `Velk\ClientEvents` — a fila de eventos de
 * cliente e a construção do `user_data`.
 *
 * A fila resolve a ponte POST → redirect → GET: no instante em que a
 * conversão acontece não existe HTML nenhum, então o evento é guardado e
 * emitido no render seguinte, uma vez só. `Session` é no-op em CLI (ver o
 * construtor), então dá pra exercitar enfileirar/drenar de verdade aqui, não
 * só as partes puras.
 *
 * Interna nesta versão de propósito: um segundo produtor (cadastro) já
 * revelou que a fila tem dois drenos, e o terceiro (compra) tem modelo de
 * confiança diferente. Público é contrato; ainda é cedo.
 */

if (!function_exists('ce_session')) {
    /** Sessão limpa a cada teste — $_SESSION é global e vaza entre eles. */
    function ce_session(): Session
    {
        $_SESSION = [];
        return new Session();
    }
}

if (!function_exists('ce_build_user_data')) {
    function ce_build_user_data(array $mapping, array $values): array
    {
        return ClientEvents::buildUserData($mapping, $values);
    }
}

// ─── buildUserData: mapeia campo de origem → chave nomeada do Google ───────

test('buildUserData: mapeia email e telefone pras chaves nomeadas', function () {
    // A chave de SAÍDA é a do Google, nunca o nome do campo de origem — é
    // isso que o enhanced conversions consome.
    $out = ce_build_user_data(
        ['email' => 'seu_email', 'phone_number' => 'whatsapp'],
        ['seu_email' => 'a@b.com', 'whatsapp' => '11999998888', 'mensagem' => 'oi']
    );

    check_same(['email' => 'a@b.com', 'phone_number' => '11999998888'], $out);
});

test('buildUserData: nada mapeado devolve vazio', function () {
    $out = ce_build_user_data([], ['email' => 'a@b.com']);
    check_same([], $out);
});

test('buildUserData: campo mapeado que não existe é omitido, sem erro', function () {
    // Acontece de verdade: o formulário é editado no site.json depois que o
    // mapeamento foi salvo no painel.
    $out = ce_build_user_data(['email' => 'campo_removido'], ['nome' => 'Fulano']);
    check_same([], $out);
});

test('buildUserData: valor vazio é omitido', function () {
    // Um email vazio no user_data é pior que nenhum — o Google tentaria casar
    // contra string vazia e a taxa de correspondência cairia sem sintoma.
    $out = ce_build_user_data(['email' => 'email'], ['email' => '   ']);
    check_same([], $out);
});

test('buildUserData: valor não-escalar é omitido', function () {
    // Campo file guarda array de metadados (_file, path, name...). Nunca
    // stringificar isso pro DOM.
    $out = ce_build_user_data(
        ['email' => 'anexo'],
        ['anexo' => ['_file' => true, 'path' => '2026/08/x.pdf']]
    );
    check_same([], $out);
});

test('buildUserData: número vira string e espaço é aparado', function () {
    $out = ce_build_user_data(
        ['email' => 'e', 'phone_number' => 'p'],
        ['e' => '  a@b.com  ', 'p' => 5511999998888]
    );
    check_same(['email' => 'a@b.com', 'phone_number' => '5511999998888'], $out);
});

test('buildUserData: chave fora da whitelist é ignorada', function () {
    // Propriedade estrutural, não checagem: o laço percorre a WHITELIST, não
    // o mapeamento — mesmo padrão do ClickIds. Alguém que grave
    // {"cpf": "documento"} no banco não consegue empurrar CPF pro dataLayer.
    $out = ce_build_user_data(
        ['email' => 'e', 'cpf' => 'documento', 'address' => 'rua'],
        ['e' => 'a@b.com', 'documento' => '000.000.000-00', 'rua' => 'Rua X']
    );

    check_same(['email' => 'a@b.com'], $out);
});

// ─── queue / drain: ponte POST → redirect → GET, uma vez só ───────────────

test('queue + drain: devolve o evento enfileirado', function () {
    $s = ce_session();
    ClientEvents::queue($s, 'velk_form_success', ['form_id' => 'orcamento'], null, 1000);

    $out = ClientEvents::drain($s, 1000);
    check_same(1, count($out));
    check_same('velk_form_success', $out[0]['name']);
    check_same('orcamento', $out[0]['data']['form_id']);
});

test('drain: consome — o segundo render NÃO repete o evento', function () {
    // F5 na página de obrigado não pode contar conversão de novo.
    $s = ce_session();
    ClientEvents::queue($s, 'velk_form_success', ['form_id' => 'x'], null, 1000);

    check_same(1, count(ClientEvents::drain($s, 1000)));
    check_same([], ClientEvents::drain($s, 1000));
});

test('drain: fila vazia devolve vazio', function () {
    check_same([], ClientEvents::drain(ce_session(), 1000));
});

test('drain: entrada acima do TTL é descartada sem emitir', function () {
    // Um tema que não chame tracking_head() deixaria a flag parada; sem TTL
    // ela dispararia numa visita futura, reportando conversão no momento
    // errado. POST → redirect é sub-segundo, então 600s é folga generosa.
    $s = ce_session();
    ClientEvents::queue($s, 'velk_form_success', ['form_id' => 'x'], null, 1000);

    check_same([], ClientEvents::drain($s, 1000 + ClientEvents::TTL_SECONDS + 1));
});

test('drain: entrada exatamente no limite do TTL ainda vale', function () {
    $s = ce_session();
    ClientEvents::queue($s, 'velk_form_success', ['form_id' => 'x'], null, 1000);

    check_same(1, count(ClientEvents::drain($s, 1000 + ClientEvents::TTL_SECONDS)));
});

test('drain: entrada malformada na sessão é descartada, sem quebrar', function () {
    // A sessão é do usuário; nunca confie na forma do que está lá.
    $s = ce_session();
    $_SESSION['_velk_events'] = ['nao-e-array', ['sem' => 'queued_at'], 42];

    check_same([], ClientEvents::drain($s, 1000));
});

test('queue: preserva o snippet quando há, e null quando não há', function () {
    $s = ce_session();
    ClientEvents::queue($s, 'velk_form_success', [], 'gtag("event","conversion");', 1000);
    $out = ClientEvents::drain($s, 1000);
    check_same('gtag("event","conversion");', $out[0]['snippet']);

    $s2 = ce_session();
    ClientEvents::queue($s2, 'velk_form_success', [], null, 1000);
    check_same(null, ClientEvents::drain($s2, 1000)[0]['snippet']);
});

test('queue: dois eventos no mesmo request saem os dois, em ordem', function () {
    // Hoje nenhum request produz dois, mas a fila é lista pra que compra e
    // assinatura entrem depois sem remodelar nada.
    $s = ce_session();
    ClientEvents::queue($s, 'velk_form_success', ['form_id' => 'a'], null, 1000);
    ClientEvents::queue($s, 'velk_signup_success', ['member_id' => 7], null, 1000);

    $out = ClientEvents::drain($s, 1000);
    check_same(2, count($out));
    check_same('velk_form_success', $out[0]['name']);
    check_same('velk_signup_success', $out[1]['name']);
});
