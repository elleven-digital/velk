<?php

declare(strict_types=1);

use Velk\Config;
use Velk\FrontRouter;
use Velk\Models\Term;

/**
 * I8.2 (taxonomy half): renderTermResponse() had the exact same immediate
 * `return null;` bug renderItemResponse() had — a taxonomy TERM whose
 * saved template key no longer resolves 404'd outright, even when the
 * theme has a taxonomy.php fallback. TemplateResolver::fileFor() is
 * shared by item_types AND taxonomies (its own docblock says so), so the
 * item-only fix from the previous wave was half-done. Fixed with the same
 * pattern: fileFor() falls custom key → the taxonomy's own `template`;
 * FrontRouter adds a final rung — taxonomy.php, NOT single.php, per
 * AGENTS.md's chain ("Taxonomy archives: ... Fallback taxonomy.php") and
 * confirmed by renderWithFallback() already using 'taxonomy.php' as ITS
 * own fallback literal for terms a few lines below.
 *
 * Unlike renderItemResponse() (which can skip App::instance() entirely by
 * using an already-published item), renderTermResponse() unconditionally
 * calls Item::byTerm($term->id) a few lines after the template fallback —
 * there's no input that avoids it. No local MySQL is reachable in this
 * sandbox (confirmed separately investigating M6), so these tests can't
 * inspect a real rendered body. Instead they use a structural fact: a
 * clean `return null;` (the bug) never throws, so ANY throw — or a real
 * Response — proves execution got PAST the fallback guard being tested,
 * regardless of what happens afterward or whether App/DB happen to be
 * available in this process. Only the "genuinely nothing resolves"
 * scenario is asserted DB-free or exception-free, because that's the one
 * path that legitimately returns before ever touching Item::byTerm().
 */

if (!function_exists('frt_make_config')) {
    function frt_make_config(string $themeDir): Config
    {
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, ['paths' => ['theme' => $themeDir]]);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, ['site' => ['name' => 'Test Site']]);
        return $config;
    }
}

if (!function_exists('frt_make_term')) {
    function frt_make_term(int $id, ?string $template): Term
    {
        $term = (new ReflectionClass(Term::class))->newInstanceWithoutConstructor();
        $term->id = $id;
        $term->template = $template;
        return $term;
    }
}

if (!function_exists('frt_temp_theme_dir')) {
    function frt_temp_theme_dir(): string
    {
        $dir = sys_get_temp_dir() . '/velk_frontrouter_term_test_' . bin2hex(random_bytes(6));
        mkdir($dir . '/templates', 0777, true);
        return $dir;
    }
}

if (!function_exists('frt_rrmdir')) {
    function frt_rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $path = $dir . '/' . $f;
            is_dir($path) ? frt_rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}

/**
 * true quando renderTermResponse() NÃO tomou o `return null;` imediato —
 * ou devolveu uma Response de verdade, ou lançou (só é alcançável depois
 * da guarda, já que um `return null;` limpo nunca lança).
 */
if (!function_exists('frt_did_not_404_immediately')) {
    function frt_did_not_404_immediately(string $taxonomy, array $def, Term $term, Config $config): bool
    {
        $method = new ReflectionMethod(FrontRouter::class, 'renderTermResponse');
        try {
            $response = $method->invoke(null, $taxonomy, $def, $term, $config);
            return $response !== null;
        } catch (\Throwable $e) {
            return true;
        }
    }
}

test('I8.2 (taxonomia): chave removida + taxonomy.php presente no tema — não 404 imediato', function () {
    $themeDir = frt_temp_theme_dir();
    try {
        file_put_contents($themeDir . '/templates/taxonomy.php', '<?php echo "TAXONOMY_FALLBACK_OK";');
        $def = ['templates' => ['outro' => ['label' => 'Outro', 'file' => 'outro.php']]]; // sem `template` do tipo
        $term = frt_make_term(1, 'chave-que-nao-existe-mais');
        $config = frt_make_config($themeDir);

        check(
            frt_did_not_404_immediately('categoria', $def, $term, $config),
            'chave de template removida não pode 404 de cara — deve tentar taxonomy.php antes de desistir'
        );
    } finally {
        frt_rrmdir($themeDir);
    }
});

test('I8.2 (taxonomia): chave removida, SEM taxonomy.php e SEM template do tipo — 404 de verdade, sem exceção', function () {
    $themeDir = frt_temp_theme_dir(); // templates/ existe, mas SEM taxonomy.php
    try {
        $def = ['templates' => ['outro' => ['label' => 'Outro', 'file' => 'outro.php']]];
        $term = frt_make_term(1, 'chave-que-nao-existe-mais');
        $config = frt_make_config($themeDir);

        $method = new ReflectionMethod(FrontRouter::class, 'renderTermResponse');
        $response = $method->invoke(null, 'categoria', $def, $term, $config);

        check($response === null, 'sem taxonomy.php no tema e sem template do tipo, deve mesmo 404 — não fabricar conteúdo nem tocar o banco');
    } finally {
        frt_rrmdir($themeDir);
    }
});

test('I8.2 (taxonomia): chave removida, mas com template do TIPO declarado — resolve sem precisar do taxonomy.php', function () {
    $themeDir = frt_temp_theme_dir(); // SEM taxonomy.php — só pra provar que nem precisou dele
    try {
        $def = [
            'template' => 'type-default.php',
            'templates' => ['outro' => ['label' => 'Outro', 'file' => 'outro.php']],
        ];
        $term = frt_make_term(1, 'chave-removida');
        $config = frt_make_config($themeDir);

        check(
            frt_did_not_404_immediately('categoria', $def, $term, $config),
            'com `template` do tipo declarado, TemplateResolver::fileFor() já resolve — nem deveria chegar a checar taxonomy.php'
        );
    } finally {
        frt_rrmdir($themeDir);
    }
});

test('I8.2 (taxonomia): chave VÁLIDA continua resolvendo normal (TemplateResolver::fileFor não muda pra chave válida)', function () {
    // FrontRouter-level (Item::byTerm sem DB) não dá pra provar o corpo
    // renderizado aqui — mas o resolver que decide QUAL arquivo usar é o
    // mesmo TemplateResolver::fileFor(), já coberto a fundo (chave válida
    // inalterada) em tests/unit/template_resolver_test.php, e é
    // shape-agnostic entre item_types e taxonomias (mesmo array $def).
    // Este teste só confirma que uma chave válida também não 404 de cara.
    $themeDir = frt_temp_theme_dir();
    try {
        mkdir($themeDir . '/templates/custom', 0777, true);
        file_put_contents($themeDir . '/templates/custom/video.php', '<?php echo "CUSTOM_OK";');
        $def = ['templates' => ['video' => ['label' => 'Vídeo', 'file' => 'custom/video.php']]];
        $term = frt_make_term(1, 'video'); // chave VÁLIDA
        $config = frt_make_config($themeDir);

        check(frt_did_not_404_immediately('categoria', $def, $term, $config));
    } finally {
        frt_rrmdir($themeDir);
    }
});
