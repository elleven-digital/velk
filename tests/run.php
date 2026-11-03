<?php

declare(strict_types=1);

require __DIR__ . '/harness.php';

foreach (glob(__DIR__ . '/unit/*_test.php') ?: [] as $file) {
    fwrite(STDOUT, "\n\033[1m" . basename($file) . "\033[0m\n");
    require $file;
}

fwrite(STDOUT, "\n" . TestRunner::$passed . ' passed, ' . TestRunner::$failed . " failed\n");
exit(TestRunner::$failed > 0 ? 1 : 0);
