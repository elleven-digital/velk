<?php

declare(strict_types=1);

namespace Velk;

final class View
{
    private string $basePath;
    /** @var array<string, mixed> */
    private array $shared = [];

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/');
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    public function shared(string $key, mixed $default = null): mixed
    {
        return $this->shared[$key] ?? $default;
    }

    public function render(string $template, array $data = []): string
    {
        $candidate = $this->resolve($template);
        if ($candidate === null) {
            throw new \RuntimeException("Template not found: {$template}");
        }

        return $this->renderFile($candidate, $data);
    }

    public function renderFile(string $absolutePath, array $data = []): string
    {
        if (!is_file($absolutePath)) {
            throw new \RuntimeException("Template file not found: {$absolutePath}");
        }

        $merged = array_merge($this->shared, $data);

        ob_start();
        try {
            (function (array $__data, string $__file): void {
                extract($__data, EXTR_SKIP);
                require $__file;
            })($merged, $absolutePath);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }

    public function partial(string $name, array $data = []): string
    {
        $path = $this->basePath . '/partials/' . ltrim($name, '/');
        if (!str_ends_with($path, '.php')) {
            $path .= '.php';
        }
        return $this->renderFile($path, $data);
    }

    private function resolve(string $template): ?string
    {
        $path = $this->basePath . '/' . ltrim($template, '/');
        if (!str_ends_with($path, '.php')) {
            $path .= '.php';
        }
        return is_file($path) ? $path : null;
    }

    public function basePath(): string
    {
        return $this->basePath;
    }
}
