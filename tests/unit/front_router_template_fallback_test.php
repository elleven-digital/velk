<?php

declare(strict_types=1);

use Velk\Config;
use Velk\FrontRouter;
use Velk\Models\Item;

/**
 * I8.2 (functional): FrontRouter::renderItemResponse() must not 404 an item
 * just because its saved template key no longer resolves (site.json entry
 * removed/renamed). Exercises the REAL private method via reflection —
 * status stays 'published' throughout so the App::instance()->auth->check()
 * branch is never reached (short-circuited by the status check), meaning no
 * DB/session bootstrap is needed. Config is built the same way
 * not_found_page_test.php / db_error_message_test.php do: bypass the
 * constructor and inject just the private props the method actually reads
 * (env.paths.theme, site) — pointed at a disposable temp theme dir so both
 * the "single.php present" and "single.php absent" branches are provokable
 * without touching the project's real theme/.
 */

if (!function_exists('fr_make_config')) {
    function fr_make_config(string $themeDir): Config
    {
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, ['paths' => ['theme' => $themeDir]]);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, ['site' => ['name' => 'Test Site']]);
        return $config;
    }
}

if (!function_exists('fr_make_published_item')) {
    function fr_make_published_item(?string $template): Item
    {
        $item = (new ReflectionClass(Item::class))->newInstanceWithoutConstructor();
        $item->status = 'published'; // skips App::instance()->auth->check() (short-circuited)
        $item->template = $template;
        return $item;
    }
}

if (!function_exists('fr_render_item_response')) {
    function fr_render_item_response(string $type, array $def, Item $item, Config $config): ?\Velk\Response
    {
        $method = new ReflectionMethod(FrontRouter::class, 'renderItemResponse');
        return $method->invoke(null, $type, $def, $item, $config);
    }
}

if (!function_exists('fr_temp_theme_dir')) {
    function fr_temp_theme_dir(): string
    {
        $dir = sys_get_temp_dir() . '/velk_frontrouter_test_' . bin2hex(random_bytes(6));
        mkdir($dir . '/templates', 0777, true);
        return $dir;
    }
}

if (!function_exists('fr_rrmdir')) {
    function fr_rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $path = $dir . '/' . $f;
            is_dir($path) ? fr_rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}

test('I8.2: chave de template removida/renomeada cai pro single.php do tema (não 404 mais)', function () {
    $themeDir = fr_temp_theme_dir();
    try {
        file_put_contents($themeDir . '/templates/single.php', '<?php echo "SINGLE_FALLBACK_OK"; ?>');
        // list mode, sem `template` de tipo — só o fallback FINAL (single.php)
        // pode salvar este caso.
        $def = ['templates' => ['outro' => ['label' => 'Outro', 'file' => 'outro.php']]];
        $item = fr_make_published_item('chave-que-nao-existe-mais');
        $config = fr_make_config($themeDir);

        $response = fr_render_item_response('post', $def, $item, $config);

        check($response !== null, 'não pode 404 só porque a chave sumiu — deve cair pro single.php');
        check_same(200, $response->getStatus());
        check(str_contains($response->getBody(), 'SINGLE_FALLBACK_OK'), 'deve ter renderizado o single.php do tema, não uma página vazia');
    } finally {
        fr_rrmdir($themeDir);
    }
});

test('I8.2: chave removida E sem single.php no tema → 404 de verdade (não inventa arquivo)', function () {
    $themeDir = fr_temp_theme_dir(); // templates/ existe, mas SEM single.php
    try {
        $def = ['templates' => ['outro' => ['label' => 'Outro', 'file' => 'outro.php']]];
        $item = fr_make_published_item('chave-que-nao-existe-mais');
        $config = fr_make_config($themeDir);

        $response = fr_render_item_response('post', $def, $item, $config);

        check($response === null, 'sem single.php no tema, deve mesmo 404 — não fabricar conteúdo');
    } finally {
        fr_rrmdir($themeDir);
    }
});

test('I8.2: chave válida continua resolvendo pro PRÓPRIO arquivo (fallback não interfere)', function () {
    $themeDir = fr_temp_theme_dir();
    try {
        file_put_contents($themeDir . '/templates/single.php', '<?php echo "SHOULD_NOT_BE_USED"; ?>');
        mkdir($themeDir . '/templates/custom', 0777, true);
        file_put_contents($themeDir . '/templates/custom/video.php', '<?php echo "CUSTOM_TEMPLATE_OK"; ?>');

        $def = ['templates' => ['video' => ['label' => 'Vídeo', 'file' => 'custom/video.php']]];
        $item = fr_make_published_item('video'); // chave VÁLIDA
        $config = fr_make_config($themeDir);

        $response = fr_render_item_response('post', $def, $item, $config);

        check($response !== null);
        check(str_contains($response->getBody(), 'CUSTOM_TEMPLATE_OK'), 'chave válida deve renderizar o próprio arquivo');
        check(!str_contains($response->getBody(), 'SHOULD_NOT_BE_USED'), 'não pode cair pro single.php quando a chave é válida');
    } finally {
        fr_rrmdir($themeDir);
    }
});

test('I8.2: chave removida, mas com template do TIPO declarado → usa o do tipo, nem chega no single.php', function () {
    $themeDir = fr_temp_theme_dir();
    try {
        file_put_contents($themeDir . '/templates/single.php', '<?php echo "SHOULD_NOT_BE_USED"; ?>');
        file_put_contents($themeDir . '/templates/type-default.php', '<?php echo "TYPE_DEFAULT_OK"; ?>');

        $def = [
            'template' => 'type-default.php',
            'templates' => ['outro' => ['label' => 'Outro', 'file' => 'outro.php']],
        ];
        $item = fr_make_published_item('chave-removida');
        $config = fr_make_config($themeDir);

        $response = fr_render_item_response('post', $def, $item, $config);

        check($response !== null);
        check(str_contains($response->getBody(), 'TYPE_DEFAULT_OK'), 'deve usar o template do TIPO antes de cair pro single.php');
        check(!str_contains($response->getBody(), 'SHOULD_NOT_BE_USED'));
    } finally {
        fr_rrmdir($themeDir);
    }
});
