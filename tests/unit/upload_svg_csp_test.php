<?php

declare(strict_types=1);

use Velk\App;
use Velk\Config;
use Velk\Request;
use Velk\UploadController;

/**
 * Revisão de 2026-09: CSP `sandbox` em SVG servido por /uploads/.
 *
 * SVG é o único upload que o navegador EXECUTA quando aberto pela URL. O
 * MediaService sanitiza no upload, mas sanitizador de SVG é corrida contra
 * vetores novos; o header é a rede por baixo. Só SVG recebe — uma imagem
 * comum não executa nada e não deve ganhar header que não precisa.
 */

require_once dirname(__DIR__, 2) . '/core/helpers.php';

if (!function_exists('usc_boot')) {
    /** App mínimo com paths.uploads apontando para um diretório temporário. */
    function usc_boot(string $uploads): App
    {
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, ['paths' => ['uploads' => $uploads]]);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, []);
        $app = new App($config);
        $app->basePath = '';
        return $app;
    }
}

if (!function_exists('usc_serve')) {
    function usc_serve(string $uploads, string $name): \Velk\Response
    {
        usc_boot($uploads);
        $req = new Request('GET', '/uploads/' . $name, [], [], [], ['HTTP_HOST' => 'site.com'], []);
        return (new UploadController())->serve($req, ['path' => $name]);
    }
}

test('svg servido por /uploads/ leva Content-Security-Policy: sandbox', function () {
    $dir = sys_get_temp_dir() . '/velk_usc_' . bin2hex(random_bytes(4));
    mkdir($dir, 0775, true);
    file_put_contents($dir . '/a.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><rect/></svg>');

    $r = usc_serve($dir, 'a.svg');

    check_same(200, $r->getStatus());
    check_same('image/svg+xml', $r->getHeader('Content-Type'));
    check_same('sandbox', $r->getHeader('Content-Security-Policy'));
    @unlink($dir . '/a.svg'); @rmdir($dir);
});

test('imagem comum NÃO leva CSP — não executa nada, não precisa', function () {
    $dir = sys_get_temp_dir() . '/velk_usc_' . bin2hex(random_bytes(4));
    mkdir($dir, 0775, true);
    // PNG 1x1 válido, para o mime_content_type reconhecer.
    file_put_contents($dir . '/a.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

    $r = usc_serve($dir, 'a.png');

    check_same(200, $r->getStatus());
    check_same('image/png', $r->getHeader('Content-Type'));
    check_same(null, $r->getHeader('Content-Security-Policy'));
    @unlink($dir . '/a.png'); @rmdir($dir);
});
