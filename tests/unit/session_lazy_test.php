<?php

declare(strict_types=1);

use Velk\Session;

/**
 * Sessão sob demanda.
 *
 * A propriedade que interessa: uma requisição que não lê nem escreve não
 * abre sessão, e portanto não emite `Set-Cookie` — que é o que permite cache
 * de borda. Perder isso não quebra nada visivelmente; só faz o site inteiro
 * parar de cachear, em silêncio.
 *
 * Em CLI não há `session_start()`, mas `$_SESSION` é array comum e usável —
 * é assim que o resto da suíte exercita fila de eventos e flash. Por isso
 * `isStateful()` existe separado de `started()`: quem decide cacheabilidade
 * não pode confundir "array funcionando em teste" com "sessão HTTP aberta".
 */

if (!function_exists('sl_session')) {
    function sl_session(): Session
    {
        $_SESSION = [];
        return new Session(['name' => 'velk_session']);
    }
}

test('em CLI a sessão funciona como array, sem se declarar stateful', function () {
    $s = sl_session();
    $s->put('a', 1);
    check_same(1, $s->get('a'));
    check_same(true, $s->has('a'));

    // O ponto: nada disso pode marcar a resposta como não-cacheável, porque
    // em CLI não existe cookie nem header para negociar.
    check_same(false, $s->isStateful(), 'CLI não pode contar como sessão HTTP');
});

test('leitura devolve o default sem explodir quando não há nada', function () {
    $s = sl_session();
    check_same(null, $s->get('inexistente'));
    check_same('padrão', $s->get('inexistente', 'padrão'));
    check_same(false, $s->has('inexistente'));
    check_same(null, $s->getFlash('inexistente'));
});

test('flash continua sendo consumido uma vez só', function () {
    $s = sl_session();
    $s->flash('msg', 'oi');
    check_same('oi', $s->getFlash('msg'));
    check_same(null, $s->getFlash('msg'), 'segunda leitura não pode repetir');
});

test('csrfToken gera uma vez e mantém', function () {
    $s = sl_session();
    $t1 = $s->csrfToken();
    check(strlen($t1) === 64, 'token deveria ter 64 hex');
    check_same($t1, $s->csrfToken(), 'não pode regerar a cada chamada');
    check_same(true, $s->verifyCsrf($t1));
    check_same(false, $s->verifyCsrf('errado'));
    check_same(false, $s->verifyCsrf(null));
});

test('verifyCsrf não cria sessão para descobrir que não há token', function () {
    // Se criasse, um POST forjado sem cookie geraria sessão e cookie — e
    // pior, toda checagem falha viraria resposta não-cacheável.
    $s = sl_session();
    check_same(false, $s->verifyCsrf('qualquer'));
    check_same(false, $s->isStateful());
});

test('forget de chave inexistente não cria sessão', function () {
    $s = sl_session();
    $s->forget('nunca-existiu');
    check_same(false, $s->isStateful());
});

test('cookieName vem da config, com padrão', function () {
    check_same('velk_session', (new Session())->cookieName());
    check_same('outro', (new Session(['name' => 'outro']))->cookieName());
});
