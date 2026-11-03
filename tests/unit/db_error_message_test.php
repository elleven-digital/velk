<?php

declare(strict_types=1);

use Velk\App;
use Velk\Config;

/**
 * App::dbErrorMessage() is private — reached via Reflection, same technique
 * page_hierarchy_test.php / not_found_page_test.php use for Config: build it
 * without the constructor and inject the private property directly. Only
 * app.debug is read by the method, so no DB/theme file access is needed —
 * config_with_debug() injects just that one env value.
 */
if (!function_exists('config_with_debug')) {
    function config_with_debug(bool $debug): Config
    {
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        $prop = new ReflectionProperty(Config::class, 'env');
        $prop->setValue($config, ['app' => ['debug' => $debug]]);
        return $config;
    }
}

if (!function_exists('call_db_error_message')) {
    function call_db_error_message(App $app, string $generic, \Throwable $e): string
    {
        // No setAccessible() call: a no-op since PHP 8.1 (deprecated in 8.5)
        // — Reflection can invoke private members directly nowadays, same as
        // the ReflectionProperty::setValue() calls elsewhere in this suite.
        $method = new ReflectionMethod(App::class, 'dbErrorMessage');
        return $method->invoke($app, $generic, $e);
    }
}

test('dbErrorMessage: APP_DEBUG=false → só o texto genérico, sem detalhe cru (conexão)', function () {
    $app = new App(config_with_debug(false));
    $e = new \PDOException("SQLSTATE[HY000] [1045] Access denied for user 'root'@'localhost'");
    $msg = call_db_error_message($app, 'Cannot connect to database.', $e);
    check_same('Cannot connect to database.', $msg);
    check(!str_contains($msg, 'root'), 'não pode conter o usuário do PDO');
    check(!str_contains($msg, 'Access denied'), 'não pode conter o detalhe cru do PDO');
});

test('dbErrorMessage: APP_DEBUG=true → inclui a mensagem crua da exception (conexão)', function () {
    $app = new App(config_with_debug(true));
    $e = new \PDOException("SQLSTATE[HY000] [1045] Access denied for user 'root'@'localhost'");
    $msg = call_db_error_message($app, 'Cannot connect to database.', $e);
    check(str_starts_with($msg, 'Cannot connect to database.'), 'mantém o prefixo genérico');
    check(str_contains($msg, "Access denied for user 'root'@'localhost'"), 'inclui o detalhe cru quando debug=true');
});

test('dbErrorMessage: mesmo tratamento pro call-site de auto-install/migration', function () {
    $appOff = new App(config_with_debug(false));
    $appOn  = new App(config_with_debug(true));
    $e = new \RuntimeException("SQLSTATE[42S02]: Base table or view not found: migrations");

    $off = call_db_error_message($appOff, 'Database setup failed.', $e);
    check_same('Database setup failed.', $off);
    check(!str_contains($off, 'migrations'), 'debug=false não pode vazar nome de tabela/SQL');

    $on = call_db_error_message($appOn, 'Database setup failed.', $e);
    check(str_starts_with($on, 'Database setup failed.'), 'mantém o prefixo genérico em debug=true');
    check(str_contains($on, 'Base table or view not found: migrations'), 'debug=true mostra o detalhe cru');
});
