<?php

declare(strict_types=1);

require_once __DIR__ . '/core/Bootstrap.php';

try {
    $app = Velk\Bootstrap::boot(__DIR__);
    $app->run();
} catch (Velk\SetupException $e) {
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    echo Velk\Setup::renderRequired($e);
} catch (Velk\MaintenanceException $e) {
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    if ($e->mode === 'updating') {
        header('Retry-After: 3');
    }
    echo Velk\Maintenance::render($e->mode);
} catch (\Throwable $e) {
    // Last-resort net: anything not already handled above (a PDO error
    // mid-request, a TypeError in a theme template, ...) lands here
    // instead of escaping as a blank, unlogged 500. Full detail always
    // goes to the server log; the response only gets it when APP_DEBUG
    // is on — the public otherwise sees a generic page.
    error_log(sprintf(
        '[Velk] Unhandled %s: %s in %s:%d',
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));

    header('Cache-Control: no-store');

    $debug = (bool) Velk\Env::get('APP_DEBUG', false);
    $message = $debug
        ? sprintf('%s: %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine())
        : 'Server Error';

    Velk\Response::serverError($message)->send();
}
