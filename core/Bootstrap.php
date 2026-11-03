<?php

declare(strict_types=1);

namespace Velk;

require_once __DIR__ . '/Autoloader.php';

final class Bootstrap
{
    public static function boot(string $rootPath): App
    {
        Autoloader::register($rootPath);
        require_once __DIR__ . '/helpers.php';

        Env::load($rootPath . '/.env');

        $config = new Config($rootPath);

        if ((bool) $config->get('app.debug')) {
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
        } else {
            // Production: never show errors to visitors, but still record
            // them server-side. error_reporting(0) used to suppress PHP's
            // own logging too, so fatals/warnings vanished with no trace.
            error_reporting(E_ALL);
            ini_set('display_errors', '0');
            ini_set('log_errors', '1');
        }

        date_default_timezone_set((string) $config->get('app.timezone', 'UTC'));

        $app = new App($config);
        $app->register();

        return $app;
    }
}
