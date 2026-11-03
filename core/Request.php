<?php

declare(strict_types=1);

namespace Velk;

final class Request
{
    public string $method;
    public string $path;
    public array $query;
    public array $post;
    public array $files;
    public array $server;
    public array $cookies;
    public array $headers;

    /**
     * O Referer, mas só quando aponta para ESTE host; senão null.
     *
     * Serve para "voltar de onde veio" depois de um POST. O Referer é um
     * header que o cliente controla — via navegador ele sempre reflete a
     * página que hospedou o formulário, então não vira open redirect por
     * si só; mas era o único ponto do engine que devolvia um header cru
     * como destino de redirect, e um cliente não-navegador manda o que
     * quiser. Comparar o host fecha isso sem custo: quem chegou de fora
     * do site volta para a raiz do site, não para fora.
     *
     * A comparação ignora porta e caixa: `Site.com:443` e `site.com` são
     * o mesmo host para este fim, e proxies reescrevem porta com frequência.
     */
    public function refererSameHost(): ?string
    {
        $referer = trim((string) ($this->server['HTTP_REFERER'] ?? ''));
        if ($referer === '') {
            return null;
        }

        $refHost = parse_url($referer, PHP_URL_HOST);
        $ownHost = (string) ($this->server['HTTP_HOST'] ?? '');
        if (!is_string($refHost) || $refHost === '' || $ownHost === '') {
            return null;
        }

        $norm = static fn(string $h): string => strtolower(preg_replace('/:\d+$/', '', $h) ?? $h);

        return $norm($refHost) === $norm($ownHost) ? $referer : null;
    }

    public function __construct(
        string $method,
        string $path,
        array $query,
        array $post,
        array $files,
        array $server,
        array $cookies
    ) {
        $this->method = strtoupper($method);
        $this->path = $path;
        $this->query = $query;
        $this->post = $post;
        $this->files = $files;
        $this->server = $server;
        $this->cookies = $cookies;
        $this->headers = self::parseHeaders($server);
    }

    public static function capture(string $basePath = ''): Request
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = '/' . trim($path, '/');

        if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
            $path = substr($path, strlen($basePath));
            $path = $path === '' ? '/' : '/' . trim($path, '/');
        }

        return new self(
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            $path,
            $_GET,
            $_POST,
            $_FILES,
            $_SERVER,
            $_COOKIE
        );
    }

    private static function parseHeaders(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            }
        }
        return $headers;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function all(): array
    {
        return array_merge($this->query, $this->post);
    }

    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function isGet(): bool
    {
        return $this->method === 'GET';
    }

    public function isHead(): bool
    {
        return $this->method === 'HEAD';
    }

    public function isAjax(): bool
    {
        return ($this->headers['x-requested-with'] ?? '') === 'XMLHttpRequest';
    }

    /**
     * Whether the caller asked for a JSON response, via the `Accept` header.
     *
     * This is the switch that decides whether a public controller answers
     * with JSON or falls back to the flash-and-redirect flow every existing
     * theme depends on — so it errs narrow on purpose. Matching too widely
     * would break every plain `<form>` POST on every site out there.
     *
     * `Accept` and not `X-Requested-With` (which `isAjax()` above covers):
     * `fetch()` doesn't send the latter on its own, so it can't decide this
     * for modern code. A theme opts in explicitly with
     * `headers: {'Accept': 'application/json'}`.
     *
     * Parses the header properly instead of a substring scan, so neighbours
     * like `application/ld+json` and `application/jsonp` don't count as a
     * request for OUR json.
     */
    public function wantsJson(): bool
    {
        $accept = strtolower(trim($this->headers['accept'] ?? ''));
        if ($accept === '') {
            return false;
        }
        foreach (explode(',', $accept) as $part) {
            // "application/json;q=0.9" → "application/json"
            $type = trim(explode(';', $part, 2)[0]);
            if ($type === 'application/json') {
                return true;
            }
        }
        return false;
    }

    public function ip(): string
    {
        return $this->server['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public function userAgent(): string
    {
        return $this->headers['user-agent'] ?? '';
    }
}
