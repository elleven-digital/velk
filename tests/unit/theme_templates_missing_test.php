<?php

declare(strict_types=1);

use Velk\Config;
use Velk\ThemeTemplates;

/**
 * schema:validate aponta o template declarado no site.json que não existe
 * em theme/templates/ quando o de reserva (page.php, single.php,
 * taxonomy.php) também falta: aquele endereço dá 404 no site.
 */

if (!function_exists('ttm_problems')) {
    /** @param list<string> $files templates presentes no tema */
    function ttm_problems(array $site, array $files): array
    {
        $theme = sys_get_temp_dir() . '/velk_ttm_' . bin2hex(random_bytes(4));
        mkdir($theme . '/templates', 0777, true);
        foreach ($files as $f) {
            file_put_contents($theme . '/templates/' . $f, '');
        }
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, ['paths' => ['theme' => $theme]]);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, $site);
        try {
            return ThemeTemplates::missing($config);
        } finally {
            foreach ($files as $f) unlink($theme . '/templates/' . $f);
            rmdir($theme . '/templates');
            rmdir($theme);
        }
    }

    function ttm_site(): array
    {
        return [
            'pages' => ['sobre' => ['label' => 'Sobre', 'template' => 'page-sobre.php']],
            'page_templates' => ['landing' => ['label' => 'Landing', 'file' => 'page-landing.php']],
            'item_types' => [
                'post' => ['slug' => 'post', 'template' => 'single-post.php'],
                'bloco' => ['has_page' => false, 'template' => 'nada.php'],
                'case' => ['slug' => 'case', 'templates' => ['a' => ['label' => 'A', 'file' => 'case-a.php']]],
            ],
            'taxonomies' => ['categoria' => ['slug' => 'categoria', 'template' => 'taxonomy-categoria.php']],
        ];
    }
}

test('templates: com todos os arquivos, nada a apontar', function () {
    $files = ['page-sobre.php', 'page-landing.php', 'single-post.php', 'case-a.php', 'taxonomy-categoria.php'];
    check_same([], ttm_problems(ttm_site(), $files));
});

test('templates: arquivo que falta, mas com o de reserva no tema, não dá 404', function () {
    check_same([], ttm_problems(ttm_site(), ['page.php', 'single.php', 'taxonomy.php']));
});

test('templates: arquivo que falta sem o de reserva é apontado, um por declaração', function () {
    $problems = ttm_problems(ttm_site(), []);
    check_same(5, count($problems), implode(' | ', $problems));
    $all = implode("\n", $problems);
    foreach (['page-sobre.php', 'page-landing.php', 'single-post.php', 'case-a.php', 'taxonomy-categoria.php'] as $f) {
        check(str_contains($all, 'templates/' . $f), $f);
    }
    check(!str_contains($all, 'nada.php'), 'tipo sem página não precisa de template');
    check(str_contains($all, 'taxonomy.php'), 'diz qual arquivo de reserva falta');
});

test('templates: nome sem .php vale como o FrontRouter o lê', function () {
    $site = ['pages' => ['sobre' => ['label' => 'Sobre', 'template' => 'about']]];
    check_same([], ttm_problems($site, ['about.php']));
});

test('templates: página sem template usa page.php — sem ele, é apontada', function () {
    $site = ['pages' => ['sobre' => ['label' => 'Sobre']]];
    check_same([], ttm_problems($site, ['page.php']));
    $p = ttm_problems($site, []);
    check_same(1, count($p), implode(' | ', $p));
    check(str_contains($p[0], 'Page "sobre"') && str_contains($p[0], 'page.php'), $p[0]);
});
