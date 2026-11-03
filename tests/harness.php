<?php

declare(strict_types=1);

/**
 * Runner de testes mínimo, zero-dependência, para lógica pura do Velk.
 * Uso: `php tests/run.php`. Cada arquivo tests/unit/*_test.php chama
 * test('nome', fn) e usa check* para asserções.
 */

require_once __DIR__ . '/../core/Autoloader.php';
\Velk\Autoloader::register(dirname(__DIR__));

final class TestRunner
{
    public static int $passed = 0;
    public static int $failed = 0;
    /** @var list<string> */
    public static array $failures = [];
}

function test(string $name, callable $fn): void
{
    try {
        $fn();
        TestRunner::$passed++;
        fwrite(STDOUT, "  \033[32m✓\033[0m {$name}\n");
    } catch (\Throwable $e) {
        TestRunner::$failed++;
        TestRunner::$failures[] = "{$name} — {$e->getMessage()}";
        fwrite(STDOUT, "  \033[31m✗\033[0m {$name} — {$e->getMessage()}\n");
    }
}

function check(bool $cond, string $msg = 'assertion failed'): void
{
    if (!$cond) {
        throw new \RuntimeException($msg);
    }
}

function check_same(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        $prefix = $msg !== '' ? $msg . ': ' : '';
        throw new \RuntimeException(
            $prefix . 'expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true)
        );
    }
}
