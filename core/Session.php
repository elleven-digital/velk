<?php

declare(strict_types=1);

namespace Velk;

/**
 * Sessão iniciada SOB DEMANDA.
 *
 * O construtor não chama `session_start()`. Ela é iniciada quando:
 *
 *   1. a requisição já trouxe o cookie — o visitante tem sessão, e ler dela
 *      exige abrir; ou
 *   2. alguém escreve (`put`, `flash`, `regenerate`, `csrfToken` sem token).
 *
 * ─── Por que ─────────────────────────────────────────────────────────────
 *
 * Iniciar sempre fazia TODA resposta anônima sair com `Set-Cookie`, e cache
 * de borda não guarda resposta que grava cookie — com razão: a resposta
 * cacheada carregaria um id de sessão específico, e todo visitante seguinte
 * receberia a MESMA sessão. Então nenhuma página do site era cacheável,
 * nem a home sem formulário.
 *
 * Agora uma página de conteúdo que ninguém escreve sai sem cookie nenhum,
 * cacheável na borda. Página com formulário continua fora do cache, porque
 * `csrfToken()` escreve — e isso é aceitável: token CSRF é segredo por
 * visitante, e página cacheada é a mesma para todos, então as duas coisas
 * são incompatíveis por definição, não por implementação.
 *
 * `started()` é o que o `Response::send()` consulta para decidir entre
 * `public, s-maxage=...` e `private, no-store`.
 */
final class Session
{
    private array $config;
    private bool $started = false;

    /**
     * Em CLI (testes, bin/velk) não existe `session_start()`, mas `$_SESSION`
     * continua sendo um array comum e utilizável — que é como os testes
     * exercitam enfileirar/drenar de verdade. Então CLI não é no-op: é sessão
     * sem persistência nem cookie.
     */
    private bool $cli;

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->cli = PHP_SAPI === 'cli';

        // Sessão já aberta por outro código (índice legado, teste de
        // integração): assume e segue, sem tentar reconfigurar cookie.
        if (!$this->cli && session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
        }
    }

    /** A sessão foi realmente aberta nesta requisição? */
    public function started(): bool
    {
        return $this->started;
    }

    /**
     * A sessão gravou algo que precisa sobreviver à requisição?
     *
     * Diferente de `started()`: em CLI a sessão "funciona" sobre o array sem
     * nunca iniciar de verdade, e o header de cache não deve levar isso em
     * conta. Quem decide cacheabilidade consulta este método.
     */
    public function isStateful(): bool
    {
        return !$this->cli && $this->started;
    }

    /** O nome do cookie, sem depender de a sessão ter iniciado. */
    public function cookieName(): string
    {
        return (string) ($this->config['name'] ?? 'velk_session');
    }

    /**
     * Abre a sessão se necessário.
     *
     * `$forWrite` distingue os dois motivos: leitor só abre quando o cookie
     * existe (há algo para ler); escritor abre sempre, porque precisa de um
     * lugar para gravar. É essa distinção que mantém a visita anônima sem
     * cookie.
     */
    private function boot(bool $forWrite): bool
    {
        if ($this->started) {
            return true;
        }
        // CLI: o array já serve, e não há cookie nem header para negociar.
        if ($this->cli) {
            if (!isset($_SESSION)) {
                $_SESSION = [];
            }
            return true;
        }
        if (!$forWrite && !isset($_COOKIE[$this->cookieName()])) {
            return false;
        }

        if (session_status() === PHP_SESSION_NONE) {
            self::applyStorageSettings(
                self::storageSettings($this->config, (string) ini_get('session.save_handler'))
            );
            session_name($this->cookieName());
            session_set_cookie_params([
                'lifetime' => (int) ($this->config['lifetime'] ?? 60 * 60 * 24 * 30),
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                // Secure flag is conditional: only set on HTTPS requests.
                // Setting it unconditionally would break local-dev over
                // HTTP (the browser would refuse the cookie and auth
                // would never persist). The detection covers both the
                // direct-Apache/Nginx case (HTTPS server var) and the
                // reverse-proxy / Cloudflare case (X-Forwarded-Proto
                // header), since most production deploys terminate TLS
                // upstream and forward the original protocol info.
                'secure' => self::isHttps(),
            ]);
            // Headers já enviados (erro fatal no meio do render, saída
            // acidental antes) fariam session_start() emitir warning e
            // falhar. Melhor degradar para "sem sessão" que sujar a saída.
            if (headers_sent()) {
                return false;
            }
            session_start();
            $this->refreshCookie();
        }

        $this->started = true;
        return true;
    }

    /**
     * Cookie deslizante: a validade conta do último uso, não do login.
     *
     * O PHP só manda o cookie quando o id é novo (primeira visita, login,
     * regenerate); numa sessão que já existe ele não o reenvia, e a validade
     * ficava presa à hora do login — o admin caía 7 dias depois de entrar,
     * usando o painel todo dia. Aqui o cookie volta com validade nova sempre
     * que a requisição trouxe o MESMO id que a sessão tem (o caso em que o
     * PHP não o mandou). Quanto tempo cada login vale é com Auth e MemberAuth;
     * o cookie só precisa durar o maior dos dois.
     */
    private function refreshCookie(): void
    {
        $name = $this->cookieName();
        if (!self::cookieNeedsRefresh($_COOKIE[$name] ?? null, (string) session_id())) {
            return;
        }
        setcookie($name, (string) session_id(), [
            'expires' => time() + (int) ($this->config['lifetime'] ?? 60 * 60 * 24 * 30),
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => self::isHttps(),
        ]);
    }

    /**
     * Pura: os segundos de um limite de sessão vindos do `.env`, ou o padrão.
     * Só vale um inteiro positivo: `ADMIN_SESSION_LIFETIME=` esquecido em
     * branco virava 0, e o login caía em um minuto; `2d` virava 2.
     */
    public static function lifetimeFrom(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : $default;
        }
        if (is_string($value) && preg_match('/^\s*\d+\s*$/', $value) === 1 && (int) $value > 0) {
            return (int) $value;
        }
        return $default;
    }

    /** Pura: o cookie precisa ser reenviado? (O PHP já manda quando o id é novo.) */
    public static function cookieNeedsRefresh(mixed $requestId, string $sessionId): bool
    {
        return is_string($requestId) && $requestId !== '' && $requestId === $sessionId;
    }

    /** Segundos entre regravações do carimbo de último uso. */
    private const ACTIVITY_GRANULARITY = 60;

    /**
     * O login guardado sob `$seenKey` passou de `$limit` segundos sem uso?
     *
     * Admin e membro dividem a sessão (e o cookie), então a inatividade de
     * cada um é contada aqui, pelo carimbo do último uso que cada login grava
     * — não pelo cookie, que dura o maior dos dois. Sessão sem carimbo (de
     * antes desta versão, com SESSION_PATH vazio) ganha o carimbo agora em vez
     * de cair: não há como saber quando foi o último uso. Expirado, o carimbo
     * sai; quem chama tira o próprio login.
     */
    public function idleExpired(string $seenKey, int $limit): bool
    {
        $seen = $this->get($seenKey);
        if (!is_int($seen)) {
            $this->put($seenKey, time());
            return false;
        }
        if (time() - $seen > $limit) {
            $this->forget($seenKey);
            return true;
        }
        return false;
    }

    /**
     * Marca agora como último uso. Só regrava depois de ACTIVITY_GRANULARITY
     * segundos: com a sessão inalterada o PHP nem reescreve o arquivo
     * (session.lazy_write), e um minuto de folga não muda um limite de dias.
     */
    public function markActive(string $seenKey): void
    {
        $seen = $this->get($seenKey);
        if (!is_int($seen) || time() - $seen >= self::ACTIVITY_GRANULARITY) {
            $this->put($seenKey, time());
        }
    }

    /**
     * Onde e por quanto tempo os DADOS da sessão vivem. Pura, a não ser pela
     * pasta: cria `storage/sessions` (0700) se faltar — testável sem sessão.
     *
     * O cookie sempre teve duração própria; os dados, não:
     * ficavam na pasta e no `gc_maxlifetime` do php.ini do host. Num Debian
     * com PHP-FPM, quem limpa a pasta padrão é o `sessionclean` do cron, que
     * lê o php.ini (24 min por padrão) e ignora ini_set e o pool — o cookie
     * prometia 7 dias e os dados sumiam em menos de uma hora parados, e a
     * pasta é de todos os sites do servidor, então a limpeza de um vizinho
     * também apagava as nossas. Uma pasta do próprio Velk sai do alcance do
     * cron e dos vizinhos, como no Laravel (storage/framework/sessions) e no
     * CodeIgniter 4 (writable/session).
     *
     * Só vale para o handler de arquivos: um php.ini que manda as sessões
     * para Redis ou Memcached sabe o que faz, e trocar o save_path ali
     * quebraria a conexão. Pasta que não existe nem pode ser criada, ou que
     * não é gravável, cai no padrão do host — pior que a pasta própria, mas
     * igual ao que era, nunca uma tela de erro. `SESSION_PATH` vazio pede
     * isso de propósito.
     *
     * 0700 e criada pelo próprio PHP da requisição (o dono certo): numa
     * hospedagem compartilhada o nome de cada arquivo é o id da sessão, e
     * listar a pasta não pode ficar aberto a outros usuários do servidor.
     *
     * @return array{lifetime: int, save_path: ?string}
     */
    public static function storageSettings(array $config, string $handler): array
    {
        $lifetime = (int) ($config['lifetime'] ?? 60 * 60 * 24 * 30);
        $path = trim((string) ($config['path'] ?? ''));

        $savePath = null;
        if ($handler === 'files' && $path !== '') {
            if (!is_dir($path) && @mkdir($path, 0700, true)) {
                @chmod($path, 0700); // o umask pode ter afrouxado o modo do mkdir
            }
            if (is_dir($path) && is_writable($path)) {
                $savePath = $path;
            }
        }

        return ['lifetime' => $lifetime, 'save_path' => $savePath];
    }

    /**
     * Aplica storageSettings() — antes do session_start(), que é quando o PHP
     * lê esses valores.
     *
     * `gc_maxlifetime` acompanha o cookie sempre, inclusive no fallback: o GC
     * do próprio PHP (quando o host o deixa ligado) passa a respeitar a nossa
     * duração. Com pasta própria, ninguém mais a limpa, então o GC do PHP é
     * ligado ali (1 em cada 100 aberturas apaga o que passou do prazo), mesmo
     * onde o host o desliga (`gc_probability = 0` no Debian).
     *
     * @param array{lifetime: int, save_path: ?string} $settings
     */
    public static function applyStorageSettings(array $settings): void
    {
        ini_set('session.gc_maxlifetime', (string) $settings['lifetime']);
        if ($settings['save_path'] !== null) {
            session_save_path($settings['save_path']);
            ini_set('session.gc_probability', '1');
            ini_set('session.gc_divisor', '100');
        }
    }

    /**
     * Best-effort HTTPS detection. Two signals:
     *   1. PHP's HTTPS server var — present and non-"off" when the web
     *      server itself terminated TLS (Apache mod_ssl, nginx ssl_*).
     *   2. X-Forwarded-Proto — set by reverse proxies, load balancers,
     *      and CDN/edge services (Cloudflare, AWS ALB, etc.) when they
     *      handle TLS upstream and pass plaintext to the origin.
     *
     * Either being true means the original request reached us over
     * HTTPS — safe to mark cookies Secure.
     */
    private static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
            return true;
        }
        return false;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->boot(false)) {
            return $default;
        }
        return $_SESSION[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        if (!$this->boot(true)) {
            return;
        }
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        if (!$this->boot(false)) {
            return false;
        }
        return isset($_SESSION[$key]);
    }

    public function forget(string $key): void
    {
        // Esquecer o que não existe não precisa criar sessão para isso.
        if (!$this->boot(false)) {
            return;
        }
        unset($_SESSION[$key]);
    }

    public function flash(string $key, mixed $value): void
    {
        if (!$this->boot(true)) {
            return;
        }
        $_SESSION['_flash'][$key] = $value;
    }

    public function getFlash(string $key, mixed $default = null): mixed
    {
        // get + forget, mas sem criar sessão: se não há cookie, não há flash
        // — e criar sessão só para descobrir isso emitiria cookie em toda
        // página que consulta status de formulário.
        if (!$this->boot(false)) {
            return $default;
        }
        $value = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);
        return $value;
    }

    public function regenerate(): void
    {
        // Só uma sessão PHP de verdade troca de id: em CLI ela é só o array,
        // e session_regenerate_id() sem sessão ativa emite warning.
        if (!$this->boot(true) || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        session_regenerate_id(true);
    }

    public function destroy(): void
    {
        if (!$this->boot(false)) {
            return;
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();
        $this->started = false;
    }

    public function csrfToken(): string
    {
        // Escreve quando não há token — é este método que tira uma página com
        // formulário do cache, e é por isso que o tema não deve chamar
        // csrf_field() num partial global (rodapé): faria TODA página gravar
        // sessão e nada cachearia, sem erro nenhum aparecendo.
        if (!$this->has('_csrf')) {
            $this->put('_csrf', bin2hex(random_bytes(32)));
        }
        return (string) $this->get('_csrf');
    }

    public function verifyCsrf(?string $token): bool
    {
        if ($token === null) {
            return false;
        }
        // Só leitura: sem sessão não há token válido, e verificar não pode
        // criar uma.
        $current = $this->get('_csrf');
        return is_string($current) && hash_equals($current, $token);
    }
}
