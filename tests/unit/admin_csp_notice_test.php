<?php

declare(strict_types=1);

use Velk\App;
use Velk\Config;
use Velk\PublicCsp;

/**
 * A tela "Scripts e rastreamento" avisa quando o site está no modo estrito
 * da CSP (ou no ensaio dele): parte do que se cola ali deixa de rodar, e
 * quem cola precisa saber disso na tela, não pelo console do navegador. O
 * modo vem do site.json (`security.csp.mode`).
 */

require_once dirname(__DIR__, 2) . '/core/helpers.php';

if (!function_exists('acn_render')) {
    /** O aviso para o `security.csp` dado, com o painel em inglês. */
    function acn_render(array $csp): string
    {
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, ['csp' => []]);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, $csp === [] ? [] : ['security' => ['csp' => $csp]]);
        $app = new App($config);
        $app->adminI18n = new \Velk\Admin\I18n(dirname(__DIR__, 2) . '/core/Admin/lang', 'en', 'en');

        $csp = PublicCsp::settings($config);
        ob_start();
        include dirname(__DIR__, 2) . '/core/Admin/views/scripts/_csp_notice.php';
        return (string) ob_get_clean();
    }
}

test('aviso da CSP: nos modos open e off, e sem o bloco no site.json, a tela não muda', function () {
    check_same('', acn_render([]));
    check_same('', acn_render(['mode' => 'open', 'allow' => ['frame' => ['https://a.com']]]));
    check_same('', acn_render(['mode' => 'off']));
});

test('aviso da CSP: no strict, diz o que deixa de rodar e onde se libera um formulário', function () {
    $html = acn_render(['mode' => 'strict']);
    check(str_contains($html, 'class="flash flash--warning"'), $html);
    check(str_contains($html, 'runs a strict security policy'), $html);
    check(str_contains($html, 'document.write'), $html);
    check(str_contains($html, 'security.csp.allow.form'), 'o formulário colado que envia para fora também precisa de aviso');
    check(!str_contains($html, 'nothing is blocked yet'), $html);
    check(!str_contains($html, 'CSP_'), 'nenhuma variável do .env: a política mora no site.json');
});

test('aviso da CSP: no report, diz que é ensaio', function () {
    $html = acn_render(['mode' => 'report']);
    check(str_contains($html, 'nothing is blocked yet'), $html);
    check(str_contains($html, 'security.csp.mode'), $html);
    check(!str_contains($html, 'runs a strict security policy'), $html);
    check(!str_contains($html, 'CSP_'), $html);
});

test('aviso da CSP: as três línguas do painel têm as frases, sem as variáveis antigas do .env', function () {
    foreach (['en', 'pt', 'es'] as $lang) {
        $keys = require dirname(__DIR__, 2) . "/core/Admin/lang/{$lang}.php";
        foreach (['scripts.csp_strict', 'scripts.csp_report'] as $key) {
            check(isset($keys[$key]) && $keys[$key] !== '', "{$lang}: {$key}");
            check(!str_contains((string) ($keys[$key] ?? ''), 'CSP_'), "{$lang}: {$key}");
            check(str_contains((string) ($keys[$key] ?? ''), 'security.csp'), "{$lang}: {$key}");
        }
        check(!isset($keys['scripts.csp_script_list']), "{$lang}: a lista de scripts saiu");
    }
});

test('aviso da CSP: a tela inclui o aviso e o controller entrega o modo', function () {
    $root = dirname(__DIR__, 2);
    $view = (string) file_get_contents($root . '/core/Admin/views/scripts/index.php');
    check(str_contains($view, "include __DIR__ . '/_csp_notice.php';"), 'scripts/index.php não inclui o aviso');
    $ctrl = (string) file_get_contents($root . '/core/Admin/Controllers/TrackingScriptController.php');
    check(str_contains($ctrl, "'csp' => \\Velk\\PublicCsp::settings(\$this->app->config),"), 'o controller não entrega o modo da CSP');
});
