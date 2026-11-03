<?php

declare(strict_types=1);

use Velk\Commands\Csp;

/**
 * `bin/velk csp:check`: o que o modo `strict` bloquearia no tema, antes de
 * alguém ligá-lo em produção, e a configuração da CSP (`security.csp` no
 * `site.json`). Um tema de exemplo com um problema de cada tipo, e um tema
 * limpo.
 */

if (!function_exists('ccc_theme')) {
    /** Um tema temporário com os arquivos dados; devolve o caminho. */
    function ccc_theme(array $files): string
    {
        $dir = sys_get_temp_dir() . '/velk_csp_check_' . bin2hex(random_bytes(4)) . '/theme';
        mkdir($dir, 0777, true);
        foreach ($files as $path => $content) {
            @mkdir(dirname($dir . '/' . $path), 0777, true);
            file_put_contents($dir . '/' . $path, $content);
        }
        return $dir;
    }

    function ccc_rm(string $dir): void
    {
        $root = dirname($dir);
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($root);
    }

    /**
     * [código de saída, saída] do check.
     *
     * @param mixed $csp  o `security.csp` do site.json
     * @param array $env  o `config('csp')`: report_uri e legacy
     */
    function ccc_run(array $files, mixed $csp = null, array $env = [], string $siteUrl = ''): array
    {
        $dir = ccc_theme($files);
        $out = fopen('php://memory', 'w+');
        try {
            $code = Csp::check($dir, $csp, $env, $out, $siteUrl);
        } finally {
            ccc_rm($dir);
        }
        rewind($out);
        return [$code, (string) stream_get_contents($out)];
    }
}

test('csp:check: tema limpo sai com 0', function () {
    [$code, $out] = ccc_run([
        'partials/footer.php' => "<footer>\n<script src=\"<?= e(asset('app.js')) ?>\" nonce=\"<?= csp_nonce() ?>\" defer></script>\n"
            . "<script nonce=\"<?= csp_nonce() ?>\">init()</script>\n"
            . "<?= json_script('dados', \$x) ?>\n<script type=\"application/ld+json\">{}</script>\n</footer>",
        'assets/app.js' => "document.addEventListener('click', go);",
    ], ['mode' => 'strict']);
    check_same(0, $code, $out);
    check(str_contains($out, 'CSP mode: strict (site.json → security.csp.mode)'), $out);
    check(str_contains($out, 'No problems found.'), $out);
});

test('csp:check: <script> sem senha é erro, com arquivo e linha', function () {
    [$code, $out] = ccc_run([
        'home.php' => "<main>\n\n<script>alert(1)</script>\n<script src=\"<?= asset('a.js') ?>\" defer></script>\n<script type=\"module\">x()</script>\n"
            . "<script src=\"https://x.com/a.js?v=1&nonce=abc\"></script>\n</main>",
    ]);
    check_same(1, $code);
    check(str_contains($out, 'ERROR  theme/home.php:3  <script> without nonce'), $out);
    check(str_contains($out, 'ERROR  theme/home.php:4  <script> without nonce'), $out);
    check(str_contains($out, 'ERROR  theme/home.php:5  <script> without nonce'), $out);
    check(str_contains($out, 'ERROR  theme/home.php:6  <script> without nonce'), 'nonce= dentro da URL não é a senha');
    check(str_contains($out, '4 error(s), 0 warning(s).'), $out);
});

test('csp:check: bloco de dados (json, template) não precisa de senha', function () {
    [$code, $out] = ccc_run([
        'a.html' => '<script type="application/json" id="x">{}</script><script type="text/template"><p></p></script>',
    ]);
    check_same(0, $code, $out);
});

test('csp:check: on…= e javascript: nos templates são erro', function () {
    [$code, $out] = ccc_run([
        'partials/menu.php' => "<button onclick=\"abrir()\">Menu</button>\n<img src=\"x.png\" ONERROR='f()'>\n<a href=\"javascript:void(0)\">x</a>",
    ]);
    check_same(1, $code);
    check(str_contains($out, 'ERROR  theme/partials/menu.php:1  inline event handler (onclick=)'), $out);
    check(str_contains($out, 'ERROR  theme/partials/menu.php:2  inline event handler (onerror=)'), $out);
    check(str_contains($out, 'ERROR  theme/partials/menu.php:3  javascript: URL'), $out);
});

test('csp:check: um > dentro de um valor entre aspas não esconde o resto da tag', function () {
    // "Início > Serviços" num title, "1 > 2" num alt: a tag vai até o > de
    // fora das aspas, e o handler e a senha depois dele contam.
    [$code, $out] = ccc_run([
        'partials/trilha.php' => "<a title=\"Início > Serviços\" onclick=\"voltar()\">x</a>\n"
            . "<script data-x='a>b' nonce=\"<?= csp_nonce() ?>\">ok()</script>\n"
            . "<img alt=\"<?= e('1 > 2') ?>\" onerror=\"f()\">\n"
            . "<script <?= \$adiar ? 'defer' : '' ?> nonce=\"<?= csp_nonce() ?>\" src=\"a.js\"></script>",
    ]);
    check_same(1, $code);
    check(str_contains($out, 'ERROR  theme/partials/trilha.php:1  inline event handler (onclick=)'), $out);
    check(!str_contains($out, 'trilha.php:2'), 'a senha depois do > entre aspas conta');
    check(str_contains($out, 'ERROR  theme/partials/trilha.php:3  inline event handler (onerror=)'), $out);
    check(!str_contains($out, 'trilha.php:4'), 'um bloco PHP fora das aspas também não corta a tag');
});

test('csp:check: atributo que só termina em on…= não é handler', function () {
    [$code, $out] = ccc_run(['a.php' => '<meta name="x" content="y"><div data-onclick="z" aria-describedby="w"></div><a href="/?a=1&onclick=2">x</a>']);
    check_same(0, $code, $out);
});

test('csp:check: o tema mandando o próprio cabeçalho de CSP é erro', function () {
    [$code, $out] = ccc_run([
        'functions.php' => "<?php\n\nadd_action();\nheader(\"Content-Security-Policy: default-src 'self'\");\n",
    ]);
    check_same(1, $code);
    check(str_contains($out, 'ERROR  theme/functions.php:4  the theme sends its own Content-Security-Policy'), $out);
    check(str_contains($out, 'security.csp has no effect'), 'o core deixa a política do tema e não manda a sua');
});

test('csp:check: a política do tema numa tag <meta> também é erro', function () {
    // O core não tem como vê-la na resposta: as duas políticas valeriam juntas.
    [$code, $out] = ccc_run([
        'partials/header.php' => "<head>\n<meta http-equiv=\"X-UA-Compatible\" content=\"IE=edge\">\n"
            . "<meta http-equiv='content-security-policy' content=\"default-src 'self'\">\n"
            . "<meta http-equiv=\"Content-Security-Policy-Report-Only\" content=\"default-src 'self'\">\n</head>",
    ]);
    check_same(1, $code);
    check(str_contains($out, "ERROR  theme/partials/header.php:3  the theme sets its own Content-Security-Policy in a <meta> tag"), $out);
    check(!str_contains($out, 'header.php:2'), 'outro http-equiv não conta');
    // O navegador ignora um Report-Only numa <meta>: não vale junto com nada.
    check(!str_contains($out, 'header.php:4'), 'Report-Only numa <meta> não se aplica');
});

test('csp:check: eval e new Function no JS do tema são aviso, e somem com o allow_eval', function () {
    $files = ['assets/vendor/lib.js' => "var a = 1;\nvar f = new Function('return 1');", 'assets/b.js' => "eval ('x')"];

    [$code, $out] = ccc_run($files);
    check_same(0, $code, 'aviso não é erro');
    check(str_contains($out, 'WARN   theme/assets/vendor/lib.js:2  eval() / new Function()'), $out);
    check(str_contains($out, 'WARN   theme/assets/b.js:1  eval() / new Function()'), $out);
    check(str_contains($out, 'security.csp.allow_eval'), $out);

    [, $out] = ccc_run($files, ['allow_eval' => true]);
    check(!str_contains($out, 'WARN'), $out);
});

test('csp:check: node_modules fica de fora', function () {
    [$code, $out] = ccc_run(['node_modules/x/index.html' => '<script>a()</script>', 'node_modules/x/i.js' => 'eval(1)']);
    check_same(0, $code, $out);
    check(!str_contains($out, 'node_modules'), $out);
});

test('csp:check: modo e fonte inválidos no site.json são erro', function () {
    [$code, $out] = ccc_run([], ['mode' => 'estrito', 'allow' => ['frame' => ['https://www.youtube.com https://x.com;y', 'https://a.com,https://b.com']]]);
    check_same(1, $code);
    check(str_contains($out, 'ERROR  config  site.json → security.csp.mode "estrito"'), $out);
    check(str_contains($out, 'ERROR  config  site.json → security.csp.allow.frame: dropped invalid source "https://x.com;y"'), $out);
    check(str_contains($out, 'ERROR  config  site.json → security.csp.allow.frame: dropped invalid source "https://a.com,https://b.com"'), $out);
});

test('csp:check: variável antiga no .env é erro, com o lugar novo dela', function () {
    [$code, $out] = ccc_run([], ['mode' => 'strict'], ['legacy' => ['CSP_MODE', 'CSP_FORM_ACTION']]);
    check_same(1, $code);
    check(str_contains($out, 'ERROR  config  CSP_MODE in .env is no longer read'), $out);
    check(str_contains($out, 'security.csp.allow.form'), $out);
});

test('csp:check: nos modos open e off, e sem o bloco no site.json, avisa que nada é bloqueado ainda', function () {
    foreach ([null, ['mode' => 'open'], ['mode' => 'off']] as $csp) {
        [, $out] = ccc_run([], $csp);
        check(str_contains($out, 'Nothing is blocked in this mode'), $out);
    }
    [, $out] = ccc_run([]);
    check(str_contains($out, 'CSP mode: open'), $out);
    [, $out] = ccc_run([], ['mode' => 'strict']);
    check(!str_contains($out, 'Nothing is blocked in this mode'), $out);
});

test('csp:check: o bin/velk registra o comando e o lista na ajuda', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/velk');
    check(str_contains($src, "'csp:check' => function ()"), 'comando não registrado');
    check(str_contains($src, 'Velk\\Commands\\Csp::check('), 'o comando não chama Commands\\Csp::check()');
    check(str_contains($src, "(string) \$config->get('app.url', '')"), 'o comando passa o APP_URL para o check');
    check(str_contains($src, 'echo "  csp:check '), 'csp:check fora da ajuda');
});

test('csp:check: formulário do tema que envia para outro site sem estar liberado é erro', function () {
    $files = ['partials/newsletter.php' => "<section>\n<form method=\"post\" action=\"https://www.rdstation.com/api/form\">\n</form>\n"
        . "<form action=\"<?= e(url('/form/contato')) ?>\" method=\"post\"></form>\n"
        . "<form action=\"/busca\"></form>\n<form action='https://meusite.com.br/form/x'></form>\n</section>"];

    [$code, $out] = ccc_run($files, null, [], 'https://meusite.com.br');
    check_same(1, $code);
    check(str_contains($out, 'ERROR  theme/partials/newsletter.php:2  form posts to https://www.rdstation.com'), $out);
    check(str_contains($out, 'security.csp.allow.form'), $out);
    check(!str_contains($out, 'newsletter.php:4'), 'endereço montado por PHP a leitura não enxerga');
    check(!str_contains($out, 'newsletter.php:5'), 'endereço relativo é o próprio site');
    check(!str_contains($out, 'newsletter.php:6'), 'o endereço do próprio site (APP_URL) não conta como fora');

    [$code, $out] = ccc_run($files, ['allow' => ['form' => ['*.rdstation.com']]], [], 'https://meusite.com.br');
    check_same(0, $code, 'liberado no site.json');
});

test('csp:check: um botão com formaction para outro site também é erro', function () {
    // O navegador aplica o form-action ao formaction do botão, que manda o
    // formulário para outro endereço.
    [$code, $out] = ccc_run([
        'contato.php' => "<form action=\"/contato\" method=\"post\">\n<button formaction=\"https://outro.com/x\">Enviar</button>\n"
            . "<input type=\"submit\" formaction=\"//terceiro.com/y\">\n<button formaction=\"/local\">ok</button>\n</form>",
    ], null, [], 'https://meusite.com.br');
    check_same(1, $code);
    check(str_contains($out, 'ERROR  theme/contato.php:2  button sends the form to https://outro.com'), $out);
    check(str_contains($out, 'ERROR  theme/contato.php:3  input sends the form to https://terceiro.com'), $out);
    check(!str_contains($out, 'contato.php:4'), $out);
});

test('csp:check: sem APP_URL, o aviso lembra que o endereço pode ser do próprio site', function () {
    [, $out] = ccc_run(['a.php' => "<form action=\"https://meusite.com.br/x\"></form>"]);
    check(str_contains($out, 'set APP_URL'), $out);
    [, $out] = ccc_run(['a.php' => "<form action=\"https://outro.com/x\"></form>"], null, [], 'https://meusite.com.br');
    check(!str_contains($out, 'set APP_URL'), 'com o APP_URL definido, a dica sobra');
});

test('csp:check: chave desconhecida no security.csp.allow do site.json é erro', function () {
    [$code, $out] = ccc_run([], ['allow' => ['frames' => ['https://www.youtube.com']]]);
    check_same(1, $code);
    check(str_contains($out, 'ERROR  config  site.json → security.csp.allow: unknown key "frames"'), $out);
});
