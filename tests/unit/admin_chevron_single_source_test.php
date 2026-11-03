<?php

declare(strict_types=1);

use Velk\FieldRenderer;

/**
 * O chevron de `<details>` sai de um lugar só.
 *
 * Antes o SVG estava copiado em FieldRenderer e em forms/show.php, e a lista
 * de submissões — terceiro lugar a precisar dele — acabou com um `▾` digitado
 * à mão. O glifo não girava ao abrir e mudava de forma conforme a fonte do
 * sistema, então o mesmo controle tinha duas aparências no mesmo painel.
 *
 * A guarda mira o padrão, não o caso: qualquer view que precise de um chevron
 * chama `FieldRenderer::chevron()`, e um glifo de seta no markup é sinal de
 * que alguém rolou o próprio de novo.
 */
test('chevron(): markup estável, com a classe que o CSS gira', function () {
    $html = FieldRenderer::chevron();

    check(str_contains($html, 'class="chevron"'), 'precisa da classe .chevron — é ela que o CSS gira');
    check(str_contains($html, '<svg'), 'precisa ser SVG, não glifo de texto');
    check(str_contains($html, 'aria-hidden="true"'), 'decorativo: leitor de tela deve ignorar');
    check(str_contains($html, 'currentColor'), 'a cor vem do contexto, não fixa no markup');
});

test('nenhuma view do admin desenha seta de disclosure à mão', function () {
    $dir = dirname(__DIR__, 2) . '/core/Admin/views';
    $offenders = [];

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if (!$f->isFile() || $f->getExtension() !== 'php') {
            continue;
        }
        $src = (string) file_get_contents($f->getPathname());

        // Comentários fora: explicar a armadilha citando o glifo é legítimo.
        $code = preg_replace('!/\*.*?\*/!s', '', $src) ?? '';
        $code = preg_replace('!//[^\n]*!', '', $code) ?? '';

        foreach (['▾', '▼', '▸', '►', '▴', '▲', '◂', '◄'] as $glyph) {
            if (str_contains($code, $glyph)) {
                $offenders[] = substr($f->getPathname(), strlen($dir) + 1) . " ({$glyph})";
                break;
            }
        }
    }

    check(
        $offenders === [],
        'seta digitada à mão — use FieldRenderer::chevron(): ' . implode(', ', $offenders)
    );
});

test('o SVG do chevron não está duplicado pelo codebase', function () {
    // O `d` do path é a assinatura: se aparecer fora de FieldRenderer, alguém
    // copiou o desenho em vez de chamar o helper.
    $root = dirname(__DIR__, 2);
    $needle = 'M3 5l3 3 3-3';
    $offenders = [];

    foreach (['core/Admin/views', 'core/Admin/assets/js'] as $rel) {
        $dir = $root . '/' . $rel;
        if (!is_dir($dir)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->isFile() && str_contains((string) file_get_contents($f->getPathname()), $needle)) {
                $offenders[] = substr($f->getPathname(), strlen($root) + 1);
            }
        }
    }

    check(
        $offenders === [],
        'desenho do chevron copiado — chame FieldRenderer::chevron(): ' . implode(', ', $offenders)
    );
});
