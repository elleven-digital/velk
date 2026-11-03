<?php

declare(strict_types=1);

namespace Velk\Commands;

use Velk\PublicCsp;

/**
 * `bin/velk csp:check` — o que o modo `strict` bloquearia no tema, e a
 * configuração da CSP (`security.csp` no `site.json`, e o `.env`).
 *
 * É para rodar antes de ligar o estrito (e depois de mexer no tema). Não
 * precisa de banco. Erro sai com código 1:
 *
 * - `<script>` sem `nonce` nos templates — não roda no estrito;
 * - `on…=` e `javascript:` nos templates — código inline que nenhuma senha
 *   libera;
 * - `<meta http-equiv="Content-Security-Policy">` nos templates — o core não
 *   a vê na resposta, e as duas políticas valeriam juntas;
 * - `<form action="https://…">` (ou o `formaction` de um botão) para outro
 *   site que não esteja no `security.csp.allow.form` — o `form-action` do
 *   estrito recusaria o envio;
 * - `header('Content-Security-Policy…')` no PHP do tema — o core deixa a
 *   política do tema e não manda a sua, então o `security.csp` não vale;
 * - modo, chave ou fonte inválidos no `security.csp`, e uma variável antiga
 *   da CSP no `.env`, que não é mais lida.
 *
 * `eval(` e `new Function(` nos `.js` do tema são aviso: rodam com
 * `security.csp.allow_eval`, e muitas vezes estão numa biblioteca que nem
 * chega a usá-los.
 *
 * A leitura é por padrão de texto, não um parser de HTML: ela pode apontar
 * um `<script` dentro de um comentário, e não enxerga o script montado por
 * concatenação no PHP. É um guia para a revisão, não uma prova.
 */
final class Csp
{
    /** Pastas que não são do tema. */
    private const SKIP_DIRS = ['node_modules', '.git'];

    /** Um `<?php … ?>` / `<?= … ?>` dentro de uma tag, que pode ter `>`. */
    private const PHP_BLOCK = '<\?(?:php|=)?.*?\?>';

    /**
     * @param mixed $csp  `site.json → security.csp`
     * @param array<string, mixed> $env  `PublicCsp::envSettings()`
     * @param resource|null $out  padrão STDOUT
     * @param string $siteUrl  o `APP_URL`: um formulário que posta nele é do
     *                         próprio site, mesmo com o endereço completo
     * @return int código de saída
     */
    public static function check(string $themePath, mixed $csp, array $env = [], $out = null, string $siteUrl = ''): int
    {
        $out ??= STDOUT;
        $resolved = PublicCsp::resolve($csp, $env);
        $settings = $resolved['settings'];

        $errors = [];
        $warnings = [];
        foreach ($resolved['problems'] as $problem) {
            $errors[] = 'config  ' . $problem;
        }

        $base = basename(rtrim($themePath, '/'));
        foreach (self::files($themePath) as $file) {
            $rel = $base . '/' . ltrim(substr($file, strlen(rtrim($themePath, '/'))), '/');
            $src = (string) file_get_contents($file);
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

            if ($ext === 'php' || $ext === 'html') {
                foreach (self::templateProblems($src, $settings['lists']['form'], $siteUrl) as [$offset, $message]) {
                    $errors[] = $rel . ':' . self::line($src, $offset) . '  ' . $message;
                }
            }
            if ($ext === 'php' && preg_match('/header\s*\(\s*["\']Content-Security-Policy/i', $src, $m, PREG_OFFSET_CAPTURE) === 1) {
                $errors[] = $rel . ':' . self::line($src, $m[0][1]) . '  the theme sends its own Content-Security-Policy — '
                    . 'the core then sends none and security.csp has no effect; remove it and set security.csp in site.json';
            }
            if ($ext === 'js' && !$settings['allow_eval']
                && preg_match('/\beval\s*\(|\bnew\s+Function\s*\(/', $src, $m, PREG_OFFSET_CAPTURE) === 1) {
                $warnings[] = $rel . ':' . self::line($src, $m[0][1]) . '  eval() / new Function() — '
                    . 'blocked in strict unless security.csp.allow_eval is true';
            }
        }

        fwrite($out, 'CSP mode: ' . $settings['mode'] . " (site.json → security.csp.mode)\n");
        if ($settings['mode'] === 'open' || $settings['mode'] === 'off') {
            fwrite($out, "Nothing is blocked in this mode — the list below is what strict would block.\n");
        }
        fwrite($out, "\n");
        foreach ($errors as $line) {
            fwrite($out, 'ERROR  ' . $line . "\n");
        }
        foreach ($warnings as $line) {
            fwrite($out, 'WARN   ' . $line . "\n");
        }

        if ($errors === [] && $warnings === []) {
            fwrite($out, "No problems found.\n");
        } else {
            fwrite($out, "\n" . count($errors) . ' error(s), ' . count($warnings) . " warning(s).\n");
        }

        return $errors === [] ? 0 : 1;
    }

    /**
     * Os problemas de um template, como [offset, mensagem].
     *
     * @return list<array{0: int, 1: string}>
     */
    private static function templateProblems(string $src, array $formSources, string $siteUrl): array
    {
        $found = [];
        $php = self::PHP_BLOCK;

        // Toda tag de abertura, lida até o `>` dela: o fechamento de um bloco
        // PHP e um valor entre aspas (`title="Início > Serviços"`) também têm
        // `>`, e o `nonce` ou o `on…=` podem vir depois deles.
        preg_match_all(
            '~<([a-z][a-z0-9-]*)((?:' . $php . '|"[^"]*"|\'[^\']*\'|[^>"\'])*)>~is',
            $src,
            $tags,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );
        foreach ($tags as $tag) {
            $name = strtolower($tag[1][0]);
            $attrs = (string) preg_replace('~' . $php . '~s', '', $tag[2][0]);
            $offset = $tag[0][1];

            if ($name === 'script' && self::isJavaScript($attrs) && preg_match(PublicCsp::NONCE_ATTR, $attrs) !== 1) {
                $found[] = [$offset, '<script> without nonce — add nonce="<?= csp_nonce() ?>"'];
            }
            if (preg_match('/(?:^|[\s"\'\/])(on[a-z]+)\s*=/i', $attrs, $h) === 1) {
                $found[] = [$offset, 'inline event handler (' . strtolower($h[1]) . '=) — move it to a script file'];
            }
            // O `action` do formulário, e o `formaction` de um botão, que manda
            // o formulário para outro endereço — o form-action vale para os dois.
            $target = match ($name) {
                'form' => self::offSiteAction($attrs, 'action', $formSources, $siteUrl),
                'button', 'input' => self::offSiteAction($attrs, 'formaction', $formSources, $siteUrl),
                default => null,
            };
            if ($target !== null) {
                $found[] = [$offset, ($name === 'form' ? 'form posts to ' : $name . ' sends the form to ') . $target
                    . ' — list it in security.csp.allow.form (site.json)'
                    . ($siteUrl === '' ? ', or set APP_URL if it is the site\'s own address' : '')];
            }
            if ($name === 'meta'
                && preg_match('/(?:^|[\s"\'\/])http-equiv\s*=\s*["\']?\s*content-security-policy(?![\w-])/i', $attrs) === 1) {
                $found[] = [$offset, 'the theme sets its own Content-Security-Policy in a <meta> tag — '
                    . 'it applies together with the core\'s; remove it and set security.csp in site.json'];
            }
        }

        preg_match_all('/(?<![\w-])(?:href|src|action|formaction)\s*=\s*["\']?\s*javascript:/i', $src, $links, PREG_OFFSET_CAPTURE);
        foreach ($links[0] as [, $offset]) {
            $found[] = [$offset, 'javascript: URL — use a click listener in a script file'];
        }

        usort($found, fn(array $a, array $b): int => $a[0] <=> $b[0]);
        return $found;
    }

    /**
     * A origem para onde o formulário envia, quando é outro site e nada a
     * libera; null quando envia para o próprio site (endereço relativo, o
     * `APP_URL`, ou um endereço montado por PHP, que a leitura não enxerga).
     *
     * @param string $attr  `action` (no `<form>`) ou `formaction` (no botão)
     * @param list<string> $formSources
     */
    private static function offSiteAction(string $attrs, string $attr, array $formSources, string $siteUrl): ?string
    {
        if (preg_match('/(?:^|[\s"\'\/])' . $attr . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $attrs, $m) !== 1) {
            return null;
        }
        $action = trim(($m[1] ?? '') . ($m[2] ?? '') . ($m[3] ?? ''));
        if (preg_match('#^(?:(https?):)?//#i', $action, $s) !== 1) {
            return null;
        }
        $url = ($s[1] ?? '') === '' ? 'https:' . $action : $action;
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '' || $host === strtolower((string) parse_url($siteUrl, PHP_URL_HOST))) {
            return null;
        }
        foreach ($formSources as $source) {
            if (PublicCsp::sourceMatches($source, $url)) {
                return null;
            }
        }
        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) . '://' . $host;
    }

    /** Sem `type`, ou com um tipo de JavaScript: o navegador executa. */
    private static function isJavaScript(string $attrs): bool
    {
        if (preg_match('/(?<![\w-])type\s*=\s*["\']?\s*([^"\'\s>]*)/i', $attrs, $m) !== 1) {
            return true;
        }
        return in_array(strtolower($m[1]), ['', 'text/javascript', 'application/javascript', 'module'], true);
    }

    /** @return list<string> */
    private static function files(string $themePath): array
    {
        if (!is_dir($themePath)) {
            return [];
        }
        $files = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($themePath, \FilesystemIterator::SKIP_DOTS),
                fn(\SplFileInfo $f): bool => !($f->isDir() && in_array($f->getFilename(), self::SKIP_DIRS, true))
            )
        );
        foreach ($it as $file) {
            if (in_array(strtolower($file->getExtension()), ['php', 'html', 'js'], true)) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        return $files;
    }

    private static function line(string $src, int $offset): int
    {
        return substr_count($src, "\n", 0, $offset) + 1;
    }
}
