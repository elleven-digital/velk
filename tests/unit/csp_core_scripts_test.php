<?php

declare(strict_types=1);

use Velk\ClientEvents;
use Velk\Consent;
use Velk\PublicCsp;

/**
 * No modo `strict` da CSP o navegador só roda o `<script>` que leva a senha
 * (nonce) da página. Todo script que o core imprime no site a leva: o
 * antispam, o Turnstile, o runtime do consentimento, os scripts cadastrados
 * no painel (os liberados e os adiados, dentro do base64) e os eventos de
 * conversão. Faltar em um deles derruba aquele recurso só no modo estrito,
 * que é onde ninguém está olhando.
 */

require_once dirname(__DIR__, 2) . '/core/helpers.php';

if (!function_exists('ccs_run')) {
    /**
     * PHP num subprocesso limpo: os helpers guardam um `static` de "já
     * emiti o script", e outro teste do harness pode tê-los chamado antes.
     * Devolve [saída, senha da requisição].
     */
    function ccs_run(string $php, array $env = []): array
    {
        $root = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'velk_ccs_') . '.php';
        file_put_contents(
            $tmp,
            "<?php require " . var_export($root . '/core/Autoloader.php', true) . ";\n"
            . "Velk\\Autoloader::register(" . var_export($root, true) . ");\n"
            . "require " . var_export($root . '/core/helpers.php', true) . ";\n"
            . $php . "\n"
            . "echo \"\\n@@\" . csp_nonce();\n"
        );
        $prefix = '';
        foreach ($env as $name => $value) {
            $prefix .= $name . '=' . escapeshellarg($value) . ' ';
        }
        $out = (string) shell_exec($prefix . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' 2>&1');
        @unlink($tmp);

        $at = strrpos($out, "\n@@");
        return $at === false ? [$out, ''] : [substr($out, 0, $at), substr($out, $at + 3)];
    }

    function ccs_cats(): array
    {
        return [
            'necessarios' => ['label' => 'Necessários', 'mode' => 'required'],
            'marketing' => ['label' => 'Marketing', 'mode' => 'opt_in'],
        ];
    }
}

test('senha: o script do antispam (form_honeypot) a leva', function () {
    [$html, $nonce] = ccs_run('echo form_honeypot();');
    check($nonce !== '', $html);
    check_same(1, substr_count($html, '<script'), $html);
    check(str_contains($html, '<script nonce="' . $nonce . '">'), $html);
});

test('senha: o script do Turnstile (form_captcha) a leva', function () {
    [$html, $nonce] = ccs_run('echo form_captcha();', ['TURNSTILE_SITE_KEY' => 'site', 'TURNSTILE_SECRET_KEY' => 'secret']);
    check($nonce !== '', $html);
    check(
        str_contains($html, '<script nonce="' . $nonce . '" src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>'),
        $html
    );
});

test('senha: o runtime do consentimento a leva', function () {
    $html = Consent::renderRuntime(ccs_cats(), []);
    check(str_starts_with($html, "\n<script nonce=\"" . PublicCsp::nonce() . "\">(function(){"), substr($html, 0, 80));
});

test('senha: cada <script> de um script liberado do painel a leva', function () {
    $n = PublicCsp::nonce();
    $code = '<script async src="https://www.googletagmanager.com/gtag/js?id=G-X"></script>'
        . '<script>gtag("config","G-X")</script><noscript><img src="https://x.com/p.gif"></noscript>';
    $esperado = '<script nonce="' . $n . '" async src="https://www.googletagmanager.com/gtag/js?id=G-X"></script>'
        . '<script nonce="' . $n . '">gtag("config","G-X")</script><noscript><img src="https://x.com/p.gif"></noscript>';

    // Sem categoria (o padrão da tela), com categoria necessária, e com a
    // opcional já aceita pelo visitante.
    $casos = [
        ['', []],
        ['necessarios', []],
        ['marketing', Consent::parse(Consent::encode(['marketing' => true]))],
    ];
    foreach ($casos as [$category, $choices]) {
        $rows = [['category' => $category, 'location' => 'head', 'code' => $code]];
        check_same("\n" . $esperado . "\n", Consent::renderScripts($rows, 'head', ccs_cats(), $choices), $category);
    }
});

test('senha: no script adiado pelo consentimento ela vai dentro do base64', function () {
    // O runtime recria cada <script> copiando os atributos: com lista de
    // scripts no CSP, sem a senha o recriado não roda.
    $rows = [['category' => 'marketing', 'location' => 'head', 'code' => '<script>fbq("init","1")</script>']];
    $html = Consent::renderScripts($rows, 'head', ccs_cats(), []);

    check(str_contains($html, '<script type="text/plain" data-velk-consent="marketing">'), $html);
    preg_match('#>([A-Za-z0-9+/=]+)</script>#', $html, $m);
    check_same('<script nonce="' . PublicCsp::nonce() . '">fbq("init","1")</script>', base64_decode($m[1] ?? ''));
});

test('senha: um nonce que o autor já pôs no painel fica', function () {
    $rows = [['category' => '', 'location' => 'head', 'code' => '<script nonce="do-autor">a()</script>']];
    check_same("\n<script nonce=\"do-autor\">a()</script>\n", Consent::renderScripts($rows, 'head', ccs_cats(), []));
});

test('senha: o push do dataLayer e o snippet de conversão a levam', function () {
    $html = ClientEvents::render([
        ['name' => 'velk_form_success', 'data' => ['form_id' => 'x'], 'snippet' => 'gtag("event","conversion");', 'queued_at' => 1000],
    ]);
    check_same(2, substr_count($html, '<script'));
    check_same(2, substr_count($html, '<script nonce="' . PublicCsp::nonce() . '">'), $html);
});
