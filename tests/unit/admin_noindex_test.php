<?php

declare(strict_types=1);

use Velk\Admin\AdminRouter;
use Velk\Config;
use Velk\Request;
use Velk\Response;

/**
 * O painel não vai para buscador: toda resposta sob o prefixo do admin sai
 * com `X-Robots-Tag: noindex, nofollow`. É o cabeçalho, e não o robots.txt,
 * que tira uma página do índice — o `Disallow` só impede a visita, o endereço
 * ainda pode ser indexado por um link, e o robots.txt é público: citar o
 * admin nele entrega o caminho.
 */

if (!function_exists('ani_tag')) {
    /** O X-Robots-Tag que uma resposta em `$path` leva, com o prefixo dado. */
    function ani_tag(string $path, ?string $prefix = null): ?string
    {
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, $prefix === null ? [] : ['admin' => ['prefix' => $prefix]]);
        $response = AdminRouter::noIndex(new Request('GET', $path, [], [], [], [], []), Response::html('ok'), $config);
        return $response->getHeader('X-Robots-Tag');
    }
}

test('admin: toda resposta sob o prefixo sai com noindex, nofollow', function () {
    foreach (['/admin', '/admin/', '/admin/login', '/admin/forms/contato/export', '/admin/__static/css/admin.css'] as $path) {
        check_same('noindex, nofollow', ani_tag($path), $path);
    }
});

test('admin: com o prefixo trocado vale o configurado, e /admin vira caminho comum', function () {
    check_same('noindex, nofollow', ani_tag('/painel-x7/login', '/painel-x7'));
    check_same('noindex, nofollow', ani_tag('/painel-x7', 'painel-x7/'));
    check_same(null, ani_tag('/admin/login', '/painel-x7'));
});

test('admin: o site público não ganha o cabeçalho, nem caminho que só começa igual', function () {
    foreach (['/', '/contato', '/administracao', '/admin-blog/post'] as $path) {
        check_same(null, ani_tag($path), $path);
    }
});

test('admin: o App passa toda resposta pelo noIndex() antes de enviar', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/core/App.php');
    check(
        str_contains($src, 'AdminRouter::noIndex($this->request, $this->router->dispatch($this->request), $this->config)'),
        'App::run() não passa a resposta pelo AdminRouter::noIndex()'
    );
});

test('admin: os layouts do painel também levam a meta robots', function () {
    foreach (['main', 'auth', 'embed'] as $layout) {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . "/core/Admin/views/layout/{$layout}.php");
        check(str_contains($src, '<meta name="robots" content="noindex, nofollow">'), $layout);
    }
});

test('admin: o robots.txt de exemplo não cita o caminho do admin', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/robots.txt.example');
    check(!str_contains($src, '/admin'), 'o robots.txt é público: citar o admin entrega o caminho');
});
