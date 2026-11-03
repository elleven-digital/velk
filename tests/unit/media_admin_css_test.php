<?php

declare(strict_types=1);

test('media grid: CSS do tile de vídeo (frame + badge play)', function () {
    $css = (string) file_get_contents(
        dirname(__DIR__, 2) . '/core/Admin/assets/css/admin.css'
    );
    check(str_contains($css, '.media-grid__video'), 'falta .media-grid__video — tile de vídeo sem estilo');
    check(
        str_contains($css, '[data-media-category="video"]'),
        'falta o seletor por categoria — badge ▶ do vídeo some'
    );
});
