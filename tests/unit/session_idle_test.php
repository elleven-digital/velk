<?php

declare(strict_types=1);

use Velk\Admin\Controllers\AuthController;
use Velk\Admin\Controllers\BlockController;
use Velk\Auth;
use Velk\MemberAuth;
use Velk\Request;
use Velk\Session;

/**
 * Quanto tempo um login dura: o admin, 2 dias sem usar o painel; o membro do
 * site, 30 dias sem usar o site — contados a partir do último uso, ajustáveis
 * no .env (ADMIN_SESSION_LIFETIME, MEMBER_SESSION_LIFETIME, em segundos).
 *
 * Os dois logins vivem na MESMA sessão (o mesmo cookie), então o limite de
 * cada um é do próprio Velk: o momento do último uso fica na sessão
 * (`_admin_seen_at`, `_member_seen_at`) e, passado o limite, aquele login
 * sai — o outro continua. O cookie e os dados da sessão duram o maior dos
 * dois, renovados a cada uso.
 *
 * Antes, o único número era SESSION_LIFETIME (7 dias), e o PHP só manda o
 * cookie no login: o admin caía 7 dias depois de entrar, mesmo usando o
 * painel todo dia.
 */

require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__) . '/fixtures/block_boot.php';

if (!function_exists('sit_boot')) {
    /** Admin logado (fixture) com os limites dados. */
    function sit_boot(int $admin = 172800, int $member = 2592000, bool $logged = true): \Velk\App
    {
        $app = blkb_boot([], $logged);
        $app->auth = new Auth($app->db, $app->session, $admin);
        $app->memberAuth = new MemberAuth($app->db, $app->session, $member);
        $app->db->insert('users', ['id' => 2, 'email' => 'b@teste.dev', 'password_hash' => '', 'name' => 'B', 'role' => 'admin', 'created_at' => '2026-01-01']);
        (new ReflectionProperty(\Velk\Database::class, 'pdo'))->getValue($app->db)->exec(
            "CREATE TABLE members (id INTEGER PRIMARY KEY, email TEXT, password_hash TEXT, name TEXT, type TEXT, fields TEXT,
             two_factor_enabled INTEGER DEFAULT 0, email_verified_at TEXT, last_login_at TEXT, created_at TEXT, updated_at TEXT,
             stripe_customer_id TEXT)"
        );
        $app->db->insert('members', ['id' => 5, 'email' => 'm@teste.dev', 'password_hash' => '', 'name' => 'M', 'fields' => '{}', 'created_at' => '2026-01-01']);
        return $app;
    }
}

// ─── Admin ────────────────────────────────────────────────────────

test('admin: o login marca o último uso', function () {
    $app = sit_boot(logged: false);
    $app->auth->login(\Velk\Models\User::find(2));
    check(abs(time() - (int) $app->session->get('_admin_seen_at')) <= 1, 'carimbo no login');
    check($app->auth->check());
});

test('admin: dentro dos 2 dias continua logado', function () {
    $app = sit_boot();
    $app->session->put('_admin_seen_at', time() - 172800 + 60);
    check($app->auth->check());
    check($app->auth->user() !== null);
});

test('admin: passados 2 dias sem usar, o login cai — e só o do admin', function () {
    $app = sit_boot();
    $app->session->put('_admin_seen_at', time() - 172800 - 1);
    $app->session->put('member_id', 5);
    $app->session->put('_member_seen_at', time());

    check(!$app->auth->check(), 'admin deslogado');
    check_same(null, $app->auth->user());
    check(!$app->session->has('user_id'), 'user_id saiu da sessão');
    check($app->auth->expiredThisRequest(), 'sabe que foi por inatividade');
    check($app->memberAuth->check(), 'o membro na mesma sessão continua');
});

test('admin: o limite vem do .env (ADMIN_SESSION_LIFETIME)', function () {
    $app = sit_boot(admin: 3600);
    $app->session->put('_admin_seen_at', time() - 3601);
    check(!$app->auth->check());
});

test('admin: sessão antiga sem carimbo não cai — ganha o carimbo agora', function () {
    $app = sit_boot();
    $app->session->forget('_admin_seen_at');
    check($app->auth->check());
    check(abs(time() - (int) $app->session->get('_admin_seen_at')) <= 1);
});

test('admin: abrir uma tela do painel renova o último uso', function () {
    $app = sit_boot();
    $app->session->put('_admin_seen_at', time() - 3600);
    new BlockController(); // o construtor de toda tela do painel
    check(abs(time() - (int) $app->session->get('_admin_seen_at')) <= 1);
});

test('admin: renovação só regrava depois de 1 minuto (não escreve a cada clique)', function () {
    $app = sit_boot();
    $quase = time() - 30;
    $app->session->put('_admin_seen_at', $quase);
    new BlockController();
    check_same($quase, $app->session->get('_admin_seen_at'));
});

test('admin: ver o site público (prévia de rascunho) não conta como uso do painel', function () {
    $app = sit_boot();
    $antes = time() - 3600;
    $app->session->put('_admin_seen_at', $antes);
    check($app->auth->check(), 'o FrontRouter só pergunta se há admin');
    check_same($antes, $app->session->get('_admin_seen_at'));
});

test('admin: expirado por inatividade, o painel manda para o login com o aviso', function () {
    $app = sit_boot();
    $app->session->put('_admin_seen_at', time() - 172800 - 1);
    $c = new BlockController();
    $res = (new ReflectionMethod($c, 'requireAuth'))->invoke($c, new Request('GET', '/admin/pages', [], [], [], [], []));
    check_same(admin_url('login'), $res->getHeader('Location'));
    check_same('session_idle', $app->session->getFlash('error_code'));
});

test('admin: a tela de login mostra o aviso de inatividade', function () {
    $app = sit_boot(logged: false);
    $app->session->flash('error_code', 'session_idle');
    $html = (new AuthController())->login(new Request('GET', '/admin/login', [], [], [], [], []))->getBody();
    check(str_contains($html, e(__('flash.session_idle'))), $html);
});

test('admin: logout limpa o carimbo', function () {
    $app = sit_boot();
    $app->auth->logout();
    check(!$app->session->has('_admin_seen_at'));
});

// ─── Membro ───────────────────────────────────────────────────────

test('membro: o login marca o último uso; dentro dos 30 dias continua', function () {
    $app = sit_boot(logged: false);
    $app->memberAuth->loginAs(\Velk\Models\Member::find(5));
    check(abs(time() - (int) $app->session->get('_member_seen_at')) <= 1);
    $app->session->put('_member_seen_at', time() - 2592000 + 60);
    check($app->memberAuth->check());
    check($app->memberAuth->member() !== null);
});

test('membro: passados 30 dias sem usar o site, o login cai — e só o do membro', function () {
    $app = sit_boot();
    $app->session->put('member_id', 5);
    $app->session->put('_member_seen_at', time() - 2592000 - 1);
    check(!$app->memberAuth->check());
    check_same(null, $app->memberAuth->member());
    check(!$app->session->has('member_id'));
    check($app->auth->check(), 'o admin na mesma sessão continua');
});

test('membro: o limite vem do .env (MEMBER_SESSION_LIFETIME)', function () {
    $app = sit_boot(member: 86400);
    $app->session->put('member_id', 5);
    $app->session->put('_member_seen_at', time() - 86401);
    check(!$app->memberAuth->check());
});

test('membro: usar o site (qualquer página que leia o login) renova o último uso', function () {
    $app = sit_boot();
    $app->session->put('member_id', 5);
    $app->session->put('_member_seen_at', time() - 3600);
    check($app->memberAuth->check());
    check(abs(time() - (int) $app->session->get('_member_seen_at')) <= 1);
});

test('membro: logout limpa o carimbo', function () {
    $app = sit_boot();
    $app->session->put('member_id', 5);
    $app->session->put('_member_seen_at', time());
    $app->memberAuth->logout();
    check(!$app->session->has('_member_seen_at'));
});

// ─── Cookie e configuração ────────────────────────────────────────

test('cookie: reenviado a cada uso só quando o PHP não o mandou (mesmo id da requisição)', function () {
    check(Session::cookieNeedsRefresh('abc123', 'abc123'), 'sessão existente: o PHP não reenvia, o Velk sim');
    check(!Session::cookieNeedsRefresh(null, 'abc123'), 'sessão nova: o PHP já manda');
    check(!Session::cookieNeedsRefresh('', 'abc123'));
    check(!Session::cookieNeedsRefresh('velho', 'novo'), 'id trocado (login): o PHP já manda o novo');
});

test('config: admin 2 dias, membro 30 dias, e a sessão dura o maior dos dois', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/config/env.php');
    check(str_contains($src, "Session::lifetimeFrom(Env::get('ADMIN_SESSION_LIFETIME'), 60 * 60 * 24 * 2)"), 'ADMIN_SESSION_LIFETIME');
    check(str_contains($src, "Session::lifetimeFrom(Env::get('MEMBER_SESSION_LIFETIME'), 60 * 60 * 24 * 30)"), 'MEMBER_SESSION_LIFETIME');
    check(str_contains($src, "'lifetime'        => max(\$adminLifetime, \$memberLifetime)"), 'lifetime = o maior');
    check(!str_contains($src, "'SESSION_LIFETIME'"), 'SESSION_LIFETIME não é mais lido');
});

test('config: limite vazio ou que não é número de segundos vale o padrão', function () {
    // `ADMIN_SESSION_LIFETIME=` em branco virava 0 e o login caía em um
    // minuto; `2d` virava 2.
    foreach (['', '0', '-5', '2d', '1.5', 'abc', null, true, false, 0, -10] as $valor) {
        check_same(172800, Session::lifetimeFrom($valor, 172800), var_export($valor, true));
    }
    check_same(3600, Session::lifetimeFrom('3600', 172800));
    check_same(3600, Session::lifetimeFrom(' 3600 ', 172800));
    check_same(3600, Session::lifetimeFrom(3600, 172800));
});

test('admin e membro: limite zero ou negativo vale o padrão, não um minuto', function () {
    $app = sit_boot();
    foreach ([0, -1] as $limite) {
        $auth = new Auth($app->db, $app->session, $limite);
        $app->session->put('user_id', 1);
        $app->session->put('_admin_seen_at', time() - 3600);
        check($auth->check(), "admin com limite {$limite}");
        $member = new MemberAuth($app->db, $app->session, $limite);
        $app->session->put('member_id', 5);
        $app->session->put('_member_seen_at', time() - 3600);
        check($member->check(), "membro com limite {$limite}");
    }
});
