<?php

declare(strict_types=1);

namespace Velk;

final class Router
{
    /** @var array<string, list<array{pattern:string,handler:callable,params:array}>> */
    private array $routes = ['GET' => [], 'POST' => [], 'PUT' => [], 'DELETE' => []];

    /** @var list<callable> */
    private array $fallbacks = [];

    public function get(string $pattern, callable|array $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable|array $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function any(string $pattern, callable|array $handler): void
    {
        $this->add('GET', $pattern, $handler);
        $this->add('POST', $pattern, $handler);
    }

    public function add(string $method, string $pattern, callable|array $handler): void
    {
        $this->routes[$method][] = [
            'pattern' => $this->compilePattern($pattern),
            'handler' => $handler,
            'raw' => $pattern,
        ];
    }

    public function fallback(callable $handler): void
    {
        $this->fallbacks[] = $handler;
    }

    private function invoke(callable|array $handler, Request $request, array $params): mixed
    {
        if (is_array($handler) && count($handler) === 2 && is_string($handler[0]) && is_string($handler[1])) {
            [$class, $method] = $handler;
            $instance = new $class();
            return $instance->$method($request, $params);
        }
        if (is_callable($handler)) {
            return $handler($request, $params);
        }
        throw new \RuntimeException('Invalid route handler.');
    }

    private function compilePattern(string $pattern): string
    {
        $regex = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}#',
            function ($m) {
                $name = $m[1];
                $constraint = $m[2] ?? '[^/]+';
                return '(?P<' . $name . '>' . $constraint . ')';
            },
            $pattern
        ) ?? $pattern;
        return '#^' . $regex . '$#';
    }

    public function dispatch(Request $request): Response
    {
        // HEAD is GET without a body (RFC 9110 §9.3.2). There's no
        // separate HEAD table — a HEAD request looks itself up against the
        // GET routes so it runs the exact same handler a GET would (same
        // status, same headers). Response::send() strips the body back
        // out at emission time. POST/PUT/DELETE handlers are never
        // reachable this way, since HEAD only ever looks at the GET table.
        $lookupMethod = $request->isHead() ? 'GET' : $request->method;
        $methodRoutes = $this->routes[$lookupMethod] ?? [];

        foreach ($methodRoutes as $route) {
            if (preg_match($route['pattern'], $request->path, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                $result = $this->invoke($route['handler'], $request, $params);
                return $this->toResponse($result);
            }
        }

        foreach ($this->fallbacks as $fallback) {
            $result = $fallback($request);
            if ($result instanceof Response) {
                return $result;
            }
        }

        return Response::notFound();
    }

    private function toResponse(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }
        if (is_string($result)) {
            return Response::html($result);
        }
        if (is_array($result) || is_object($result)) {
            return Response::json($result);
        }
        return Response::html('');
    }
}
