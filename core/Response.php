<?php

declare(strict_types=1);

namespace Velk;

final class Response
{
    private int $status = 200;
    private array $headers = [];
    private string $body = '';
    private ?\Closure $streamer = null;

    public function status(int $code): Response
    {
        $this->status = $code;
        return $this;
    }

    public function header(string $name, string $value): Response
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function body(string $content): Response
    {
        $this->body = $content;
        return $this;
    }

    /**
     * Corpo emitido por callback (streaming em chunks) em vez de string —
     * usado pra servir arquivos grandes sem carregá-los inteiros na memória.
     */
    public function stream(\Closure $emitter): Response
    {
        $this->streamer = $emitter;
        return $this;
    }

    public static function html(string $body, int $status = 200): Response
    {
        return (new self())
            ->status($status)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->body($body);
    }

    public static function json(mixed $data, int $status = 200): Response
    {
        return (new self())
            ->status($status)
            ->header('Content-Type', 'application/json; charset=UTF-8')
            ->body((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function xml(string $body, int $status = 200): Response
    {
        return (new self())
            ->status($status)
            ->header('Content-Type', 'application/xml; charset=UTF-8')
            ->body($body);
    }

    public static function redirect(string $url, int $status = 302): Response
    {
        return (new self())
            ->status($status)
            ->header('Location', $url);
    }

    public static function notFound(string $message = 'Not Found'): Response
    {
        return self::html('<h1>404</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>', 404);
    }

    public static function serverError(string $message = 'Server Error'): Response
    {
        return self::html('<h1>500</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>', 500);
    }

    /**
     * `Cache-Control` decidido no ÚLTIMO instante, e por um motivo: só aqui
     * se sabe se a requisição gravou sessão. Uma página de conteúdo que
     * ninguém escreveu pode ser cacheada na borda; uma que abriu sessão
     * (formulário pedindo token CSRF, membro logado, flash pendente) não —
     * a resposta carrega um id de sessão, e cachear serviria a MESMA sessão
     * a todo visitante seguinte.
     *
     * Só toca respostas que não definiram `Cache-Control` explicitamente, e
     * só GET/HEAD com status 200. Download autenticado, JSON de admin e
     * redirect continuam decidindo por conta própria.
     */
    private function applyCacheControl(): void
    {
        if (isset($this->headers['Cache-Control'])) {
            return;
        }
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET' && $method !== 'HEAD') {
            return;
        }
        if ($this->status !== 200) {
            return;
        }

        if (!\Velk\App::hasInstance()) {
            return;
        }
        $app = \Velk\App::instance();
        // `session` é propriedade TIPADA sem default: se o boot falhou antes
        // do register(), acessá-la lança "must not be accessed before
        // initialization" — dentro do handler de erro, virando fatal.
        if (!isset($app->session)) {
            return;
        }

        if ($app->session->isStateful()) {
            $this->headers['Cache-Control'] = 'private, no-store';
            if ((bool) \Velk\Env::get('APP_DEBUG', false)) {
                $this->headers['X-Velk-Cache'] = 'bypass (session)';
            }
            return;
        }

        // `s-maxage` e não `max-age`: instrui a BORDA a guardar, e deixa o
        // navegador revalidar. Um `max-age` alto no navegador faria o
        // visitante ver conteúdo velho até o cache dele expirar, o que numa
        // correção urgente de conteúdo é o pior comportamento possível.
        $ttl = (int) \Velk\Env::get('CACHE_TTL', 0);
        if ($ttl <= 0) {
            return;
        }
        $this->headers['Cache-Control'] = 'public, max-age=0, s-maxage=' . $ttl;
        if ((bool) \Velk\Env::get('APP_DEBUG', false)) {
            $this->headers['X-Velk-Cache'] = 'cacheable (s-maxage=' . $ttl . ')';
        }
    }

    public function send(): void
    {
        $this->applyCacheControl();

        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        // HEAD gets the same status/headers a GET would (Content-Type,
        // Content-Length, ...) but never a body (RFC 9110 §9.3.2). Checked
        // here — the one emission point for every response, including the
        // last-resort error handlers in index.php — so no caller has to
        // remember to suppress it. Returning before the streamer runs also
        // skips the file read entirely for HEAD on downloads (UploadController),
        // instead of reading it just to throw the output away.
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'HEAD') {
            return;
        }

        if ($this->streamer !== null) {
            ($this->streamer)();
            return;
        }
        echo $this->body;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /** Lê um header já definido (nome insensível a caixa); null se ausente. */
    public function getHeader(string $name): ?string
    {
        foreach ($this->headers as $k => $v) {
            if (strcasecmp($k, $name) === 0) {
                return $v;
            }
        }
        return null;
    }

    public function getBody(): string
    {
        return $this->body;
    }
}
