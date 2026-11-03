<?php

declare(strict_types=1);

use Velk\Session;

/**
 * A sessão guarda os dados numa pasta do próprio Velk (storage/sessions), com
 * o tempo de vida dos DADOS igual ao do cookie.
 *
 * Antes, só o cookie tinha duração (SESSION_LIFETIME, 7 dias); os dados
 * ficavam na pasta e no gc_maxlifetime do php.ini do host. Num Debian com
 * PHP-FPM (um servidor Cloudways em produção), o `sessionclean` do cron lê o
 * gc_maxlifetime do php.ini (24 min) — e ignora ini_set e o pool —, então o
 * cookie prometia 7 dias e os dados morriam em 24 a 54 min parados. Como a
 * pasta /var/lib/php/sessions é de todos os sites do servidor, a limpeza de
 * outro site também apagava as nossas sessões. Pasta própria tira as sessões
 * do alcance do cron e dos vizinhos (o padrão do Laravel, storage/framework/
 * sessions, e do CodeIgniter 4, writable/session).
 */

if (!function_exists('sst_tmp')) {
    function sst_tmp(string $nome): string
    {
        $dir = sys_get_temp_dir() . '/velk-session-test-' . getmypid() . '-' . $nome;
        sst_rm($dir);
        return $dir;
    }

    function sst_rm(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        @chmod($dir, 0700);
        foreach (scandir($dir) ?: [] as $f) {
            if ($f !== '.' && $f !== '..') {
                $p = $dir . '/' . $f;
                is_dir($p) ? sst_rm($p) : @unlink($p);
            }
        }
        @rmdir($dir);
    }
}

test('sessão: pasta própria que já existe e é gravável vira o save_path', function () {
    $dir = sst_tmp('existe');
    mkdir($dir, 0700, true);
    $s = Session::storageSettings(['path' => $dir, 'lifetime' => 3600], 'files');
    check_same($dir, $s['save_path']);
    sst_rm($dir);
});

test('sessão: pasta que não existe é criada com 0700', function () {
    $dir = sst_tmp('nova') . '/sessions';
    $s = Session::storageSettings(['path' => $dir, 'lifetime' => 3600], 'files');
    check_same($dir, $s['save_path']);
    check(is_dir($dir), 'a pasta foi criada');
    // 0700: numa hospedagem compartilhada, o nome de cada arquivo de sessão
    // é o id dela — listar a pasta não pode ficar aberto a outros usuários.
    check_same('0700', substr(sprintf('%o', fileperms($dir)), -4));
    sst_rm(dirname($dir));
});

test('sessão: pasta sem permissão de escrita cai no padrão do host, sem quebrar', function () {
    $dir = sst_tmp('travada');
    mkdir($dir, 0500, true);
    $s = Session::storageSettings(['path' => $dir, 'lifetime' => 3600], 'files');
    check_same(null, $s['save_path']);
    sst_rm($dir);
});

test('sessão: pasta impossível de criar cai no padrão do host', function () {
    $pai = sst_tmp('pai-travado');
    mkdir($pai, 0500, true);
    $s = Session::storageSettings(['path' => $pai . '/sessions', 'lifetime' => 3600], 'files');
    check_same(null, $s['save_path']);
    check(!is_dir($pai . '/sessions'), 'nada foi criado');
    sst_rm($pai);
});

test('sessão: SESSION_PATH vazio mantém o padrão do host', function () {
    check_same(null, Session::storageSettings(['path' => '', 'lifetime' => 3600], 'files')['save_path']);
    check_same(null, Session::storageSettings(['lifetime' => 3600], 'files')['save_path']);
});

test('sessão: handler que não é de arquivo (redis no php.ini) fica intocado', function () {
    $dir = sst_tmp('redis');
    mkdir($dir, 0700, true);
    check_same(null, Session::storageSettings(['path' => $dir, 'lifetime' => 3600], 'redis')['save_path']);
    sst_rm($dir);
});

test('sessão: a vida dos dados acompanha a do cookie', function () {
    check_same(3600, Session::storageSettings(['lifetime' => 3600], 'files')['lifetime']);
    check_same(2592000, Session::storageSettings([], 'files')['lifetime'], 'padrão de 30 dias (o maior limite, o do membro)');
});

test('sessão: aplicar as configurações ajusta gc_maxlifetime, save_path e o GC do PHP', function () {
    $antes = [
        'gc_maxlifetime' => ini_get('session.gc_maxlifetime'),
        'gc_probability' => ini_get('session.gc_probability'),
        'gc_divisor' => ini_get('session.gc_divisor'),
        'save_path' => session_save_path(),
    ];
    $dir = sst_tmp('aplicar');
    mkdir($dir, 0700, true);
    try {
        Session::applyStorageSettings(['lifetime' => 7200, 'save_path' => $dir]);
        check_same('7200', ini_get('session.gc_maxlifetime'));
        check_same($dir, session_save_path());
        // Pasta própria: ninguém mais limpa — o GC do próprio PHP precisa
        // ligar, mesmo onde o host o desliga (gc_probability = 0 no Debian).
        check_same('1', ini_get('session.gc_probability'));
        check_same('100', ini_get('session.gc_divisor'));

        // Sem pasta própria: só o gc_maxlifetime; save_path e GC do host.
        session_save_path($antes['save_path']);
        ini_set('session.gc_probability', (string) $antes['gc_probability']);
        Session::applyStorageSettings(['lifetime' => 1800, 'save_path' => null]);
        check_same('1800', ini_get('session.gc_maxlifetime'));
        check_same($antes['save_path'], session_save_path());
        check_same((string) $antes['gc_probability'], ini_get('session.gc_probability'));
    } finally {
        ini_set('session.gc_maxlifetime', (string) $antes['gc_maxlifetime']);
        ini_set('session.gc_probability', (string) $antes['gc_probability']);
        ini_set('session.gc_divisor', (string) $antes['gc_divisor']);
        session_save_path($antes['save_path']);
        sst_rm($dir);
    }
});

test('sessão: config/env.php aponta session.path para storage/sessions, com SESSION_PATH por cima', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/config/env.php');
    check(str_contains($src, "Env::get('SESSION_PATH', dirname(__DIR__) . '/storage/sessions')"), 'session.path no config');
});

test('sessão: storage/ fica fora do alcance público nos dois exemplos de servidor', function () {
    $root = dirname(__DIR__, 2);
    check(preg_match('#RewriteRule \^\(core\|config\|migrations\|storage\|#', (string) file_get_contents($root . '/.htaccess.example')) === 1, '.htaccess');
    check(preg_match('#location ~ \^/\(core\|config\|migrations\|storage\|[^)]*\)\(/\|\$\) \{ deny all; \}#', (string) file_get_contents($root . '/nginx.conf.example')) === 1, 'nginx');
});

test('sessão: storage/ não é caminho do engine (o upgrade preserva as sessões)', function () {
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/engine-manifest.json'), true);
    foreach ($manifest['engine_paths'] as $p) {
        check(!str_starts_with($p, 'storage'), "engine_paths inclui {$p}");
    }
});
