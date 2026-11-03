<?php

declare(strict_types=1);

/**
 * Nenhuma view do admin pode ter <form> aninhado.
 *
 * Forms aninhados são HTML inválido: o parser do navegador DESCARTA o form
 * interno, então o botão de submit dele acaba submetendo o form EXTERNO. Foi
 * exatamente o bug "Excluir salva em vez de excluir" (o form de delete estava
 * dentro do form de edição). Este teste é a guarda contra o padrão reaparecer
 * por copy-paste entre as views de edição.
 */
test('admin views: nenhum <form> aninhado', function () {
    $dir = dirname(__DIR__, 2) . '/core/Admin/views';
    $offenders = [];

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if (!$f->isFile() || $f->getExtension() !== 'php') {
            continue;
        }
        // Remove os blocos PHP antes de contar tags — um fechamento de PHP
        // embute um ">" que atrapalharia a contagem de <form>.
        $html = preg_replace('/<\?.*?\?>/s', '', (string) file_get_contents($f->getPathname())) ?? '';
        preg_match_all('/<\/?form\b/i', $html, $m);

        $depth = 0;
        $max = 0;
        foreach ($m[0] as $tag) {
            if (str_starts_with($tag, '</')) {
                $depth = max(0, $depth - 1);
            } else {
                $depth++;
                if ($depth > $max) {
                    $max = $depth;
                }
            }
        }
        if ($max > 1) {
            $offenders[] = substr($f->getPathname(), strlen($dir) + 1) . " (depth {$max})";
        }
    }

    check($offenders === [], 'forms aninhados encontrados: ' . implode(', ', $offenders));
});
