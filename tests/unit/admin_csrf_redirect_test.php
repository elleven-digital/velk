<?php

declare(strict_types=1);

use Velk\Admin\Controllers\AuthController;
use Velk\Admin\Controllers\BlockController;
use Velk\Request;

/**
 * Falha de CSRF num POST comum do painel: em vez da página crua "CSRF token
 * mismatch." (403), volta para a tela de onde veio — com o flash do código
 * session_expired e um token novo —, como o redirect do CodeIgniter 4 e a
 * página 419 do Laravel.
 *
 * O caso de produção (um site em produção): a sessão morre com o painel aberto;
 * a tela de login (ou o editor) deixada aberta envia um token de uma sessão
 * que não existe mais. Recarregar resolvia; agora o próprio servidor recarrega.
 *
 * Destino: o Referer, só se for do próprio site (Request::refererSameHost) e
 * só com alguém logado; sem login, o login; logado sem Referer válido, o
 * painel — não o login, que redirecionaria quem está logado para o painel e
 * perderia a mensagem no caminho. AJAX continua com o JSON 403 de antes.
 */

require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__) . '/fixtures/block_boot.php';

if (!function_exists('csr_post')) {
    /** POST não-AJAX com token errado, do host `painel.test`. */
    function csr_post(string $path, ?string $referer, bool $ajax = false): Request
    {
        $server = ['HTTP_HOST' => 'painel.test'];
        if ($referer !== null) {
            $server['HTTP_REFERER'] = $referer;
        }
        if ($ajax) {
            $server['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        }
        return new Request('POST', $path, [], ['_csrf' => 'token-de-uma-sessao-morta'], [], $server, []);
    }

    /** Roda o verifyCsrfOrFail() de um controller do painel. */
    function csr_verify(object $controller, Request $request): ?\Velk\Response
    {
        return (new ReflectionMethod($controller, 'verifyCsrfOrFail'))->invoke($controller, $request);
    }

    function csr_location(\Velk\Response $res): ?string
    {
        return $res->getHeader('Location');
    }
}

test('CSRF: login enviado com token de sessão morta volta para o login, com aviso e token novo', function () {
    $app = blkb_boot([], false);
    $res = (new AuthController())->login(csr_post('/admin/login', 'http://painel.test/admin/login'));

    check_same(303, $res->getStatus());
    check_same(admin_url('login'), csr_location($res));
    check_same('session_expired', $app->session->getFlash('error_code'));
    $novo = $app->session->get('_csrf');
    check(is_string($novo) && $novo !== '' && $novo !== 'token-de-uma-sessao-morta', 'token novo na sessão');
});

test('CSRF: logado, com Referer do próprio site, volta para a mesma tela', function () {
    $app = blkb_boot([]);
    $res = csr_verify(new BlockController(), csr_post('/admin/pages/home', 'http://painel.test/admin/pages/home?tab=seo'));
    check_same(303, $res->getStatus());
    check_same('http://painel.test/admin/pages/home?tab=seo', csr_location($res));
    check_same('session_expired', $app->session->getFlash('error_code'));
});

test('CSRF: Referer de outro site nunca vira destino', function () {
    blkb_boot([]);
    $res = csr_verify(new BlockController(), csr_post('/admin/pages/home', 'https://outro-site.com/phishing'));
    check_same(303, $res->getStatus());
    check_same(admin_url(''), csr_location($res));
});

test('CSRF: logado e sem Referer, vai para o painel (não para o login, que perderia o aviso)', function () {
    blkb_boot([]);
    $res = csr_verify(new BlockController(), csr_post('/admin/pages/home', null));
    check_same(admin_url(''), csr_location($res));
});

test('CSRF: sem login, vai para o login mesmo com Referer válido', function () {
    $app = blkb_boot([], false);
    $res = csr_verify(new BlockController(), csr_post('/admin/pages/home', 'http://painel.test/admin/pages/home'));
    check_same(admin_url('login'), csr_location($res));
    check_same('session_expired', $app->session->getFlash('error_code'));
});

test('CSRF: AJAX continua com o JSON 403 de antes', function () {
    blkb_boot([]);
    $res = csr_verify(new BlockController(), csr_post('/admin/media/upload', 'http://painel.test/admin/media', true));
    check_same(403, $res->getStatus());
    check_same(['error' => __('flash.session_expired')], json_decode($res->getBody(), true));
    check_same(null, csr_location($res));
});

test('CSRF: fetch sem X-Requested-With (Sec-Fetch-Dest: empty) também recebe o JSON 403', function () {
    // Um fetch segue redirect sozinho: receberia a tela HTML com 200, e o JS
    // acharia que o envio deu certo.
    blkb_boot([]);
    $req = new Request('POST', '/admin/items/post/reorder', [], ['_csrf' => 'x'], [], [
        'HTTP_HOST' => 'painel.test', 'HTTP_SEC_FETCH_DEST' => 'empty',
    ], []);
    $res = csr_verify(new BlockController(), $req);
    check_same(403, $res->getStatus());
    check_same(null, csr_location($res));
});

test('CSRF: dentro de um iframe (a janela de blocos), uma página curta no lugar do redirect', function () {
    // Redirecionar para o Referer carregaria o editor inteiro DENTRO da janela.
    blkb_boot([]);
    $req = new Request('POST', '/admin/blocks/form', [], ['_csrf' => 'x'], [], [
        'HTTP_HOST' => 'painel.test', 'HTTP_SEC_FETCH_DEST' => 'iframe',
        'HTTP_REFERER' => 'http://painel.test/admin/items/post/3',
    ], []);
    $res = csr_verify(new BlockController(), $req);
    check_same(403, $res->getStatus());
    check_same(null, csr_location($res));
    check(str_contains($res->getBody(), '<body class="embed"'), 'no layout da janela');
    check(str_contains($res->getBody(), e(__('flash.session_expired_window'))), 'pede para fechar e recarregar');
});

test('CSRF: token certo segue o fluxo normal', function () {
    $app = blkb_boot([]);
    $ok = new Request('POST', '/admin/pages/home', [], ['_csrf' => $app->session->csrfToken()], [], ['HTTP_HOST' => 'painel.test'], []);
    check_same(null, csr_verify(new BlockController(), $ok));
});

test('CSRF: a tela de login mostra o aviso do código session_expired', function () {
    $app = blkb_boot([], false);
    $app->session->flash('error_code', 'session_expired');
    $html = (new AuthController())->login(new Request('GET', '/admin/login', [], [], [], [], []))->getBody();
    check(str_contains($html, e(__('flash.session_expired_resubmit'))), 'aviso na tela de login');
    check(str_contains($html, 'class="auth__error"'), 'no lugar dos erros do login');
});
