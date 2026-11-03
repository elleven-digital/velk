<?php

declare(strict_types=1);

namespace Velk;

/**
 * Tiny dotenv loader. No dependencies.
 *
 * Order of precedence:
 *   1. Variables already in the environment (Apache SetEnv, host panel, Vercel, etc.)
 *   2. The `.env` file (gitignored, local secrets)
 *   3. Defaults passed to `Env::get()` from `config/env.php`
 */
final class Env
{
    /**
     * Load a `.env` file into `$_ENV`, `$_SERVER`, and `getenv()`.
     * Pre-existing variables are NOT overridden — that lets the host platform
     * win over the file when both are set (e.g. production secrets injected
     * by the hosting environment).
     */
    public static function load(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Allow leading "export " (POSIX style)
            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }

            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }

            $key = trim(substr($line, 0, $eq));
            $value = trim(substr($line, $eq + 1));

            if ($key === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }

            // Strip surrounding quotes; double quotes process common escapes.
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                    if ($first === '"') {
                        $value = str_replace(
                            ['\\n', '\\r', '\\t', '\\"', '\\\\'],
                            ["\n", "\r", "\t", '"', '\\'],
                            $value
                        );
                    }
                }
            }

            if (getenv($key) !== false || array_key_exists($key, $_ENV)) {
                continue;
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    /**
     * Read an env var. Coerces "true"/"false"/"null"/"empty" string literals.
     * Returns `$default` if the var is not set.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? null;
        if ($value === null) {
            $raw = getenv($key);
            if ($raw === false) {
                return $default;
            }
            $value = $raw;
        }

        if (!is_string($value)) {
            return $value;
        }

        return match (strtolower($value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}
