<?php

declare(strict_types=1);

use Velk\Config;
use Velk\FrontRouter;
use Velk\Models\Item;
use Velk\Models\Term;

/**
 * Um template declarado no site.json cujo arquivo não existe no tema, sem o
 * arquivo de reserva (single.php, taxonomy.php, page.php), dava erro 500
 * ("Template not found") em vez de 404. Agora o FrontRouter responde 404, e
 * o schema:validate aponta o arquivo que falta.
 */

require_once dirname(__DIR__) . '/fixtures/slug_i18n_boot.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

if (!function_exists('fta_theme')) {
    /** Um tema temporário com os templates dados; devolve o caminho. */
    function fta_theme(array $templates): string
    {
        $dir = sys_get_temp_dir() . '/velk_fta_' . bin2hex(random_bytes(5));
        mkdir($dir . '/templates', 0777, true);
        foreach ($templates as $file => $php) {
            file_put_contents($dir . '/templates/' . $file, $php);
        }
        $env = (new ReflectionProperty(Config::class, 'env'))->getValue(\Velk\App::instance()->config);
        $env['paths']['theme'] = $dir;
        (new ReflectionProperty(Config::class, 'env'))->setValue(\Velk\App::instance()->config, $env);
        return $dir;
    }

    function fta_rm(string $dir): void
    {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    function fta_call(string $method, array $args): mixed
    {
        return (new ReflectionMethod(FrontRouter::class, $method))->invoke(null, ...$args);
    }
}

test('template ausente: termo sem o arquivo declarado nem taxonomy.php → 404, não erro', function () {
    $app = sib_boot();
    $dir = fta_theme([]);
    try {
        $term = Term::find(sib_term('categoria', 'teste'));
        $r = fta_call('renderTermResponse', ['categoria', ['template' => 'taxonomy-categoria.php'], $term, $app->config]);
        check_same(null, $r);
    } finally {
        fta_rm($dir);
    }
});

test('template ausente: com taxonomy.php no tema, o termo usa ele', function () {
    $app = sib_boot();
    $dir = fta_theme(['taxonomy.php' => 'RESERVA']);
    try {
        $term = Term::find(sib_term('categoria', 'teste'));
        $r = fta_call('renderTermResponse', ['categoria', ['template' => 'taxonomy-categoria.php'], $term, $app->config]);
        check(str_contains((string) $r?->getBody(), 'RESERVA'));
    } finally {
        fta_rm($dir);
    }
});

test('template ausente: post sem o arquivo declarado nem single.php → 404', function () {
    $app = sib_boot();
    $dir = fta_theme([]);
    try {
        $item = Item::find(sib_item('post', 'meu-post'));
        $r = fta_call('renderItemResponse', ['post', ['template' => 'single-post.php'], $item, $app->config]);
        check_same(null, $r);
    } finally {
        fta_rm($dir);
    }
});

test('template ausente: página sem o arquivo declarado nem page.php → 404', function () {
    $app = sib_boot();
    $dir = fta_theme([]);
    try {
        $r = fta_call('renderPage', [['key' => 'sobre', 'page' => $app->config->page('sobre')], $app->config]);
        check_same(null, $r);
    } finally {
        fta_rm($dir);
    }
});
