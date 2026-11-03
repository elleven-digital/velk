<?php

declare(strict_types=1);

use Velk\Services\MediaService;

/** Helper: sanitiza e devolve string (falha o teste se virar null). */
if (!function_exists('sanitize_svg')) {
    function sanitize_svg(string $svg): string
    {
        $out = MediaService::sanitizeSvgMarkup($svg);
        return $out ?? '__NULL__';
    }
}

test('svg: <script> minúsculo é removido (comportamento base)', function () {
    $out = sanitize_svg('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    check(!str_contains($out, 'alert(1)'), 'script minúsculo removido');
    check(stripos($out, '<script') === false, 'sem tag script');
});

test('svg: <SCRIPT> MAIÚSCULO/misto é removido (o bug corrigido)', function () {
    $up = sanitize_svg('<svg xmlns="http://www.w3.org/2000/svg"><SCRIPT>alert(1)</SCRIPT></svg>');
    check(stripos($up, 'script') === false, 'SCRIPT maiúsculo removido');
    check(!str_contains($up, 'alert(1)'), 'payload some');

    $mixed = sanitize_svg('<svg xmlns="http://www.w3.org/2000/svg"><ScRiPt>alert(1)</ScRiPt></svg>');
    check(stripos($mixed, 'script') === false, 'ScRiPt misto removido');
});

test('svg: <FOREIGNOBJECT> maiúsculo é removido', function () {
    $out = sanitize_svg('<svg xmlns="http://www.w3.org/2000/svg"><FOREIGNOBJECT><body>x</body></FOREIGNOBJECT></svg>');
    check(stripos($out, 'foreignobject') === false, 'foreignObject removido em qualquer caso');
});

test('svg: <style> perigoso é removido; <style> com CSS legítimo é preservado', function () {
    // Conteúdo perigoso (javascript: no CSS) → elemento removido inteiro.
    $bad = sanitize_svg('<svg xmlns="http://www.w3.org/2000/svg"><style>*{x:url("javascript:alert(1)")}</style><rect/></svg>');
    check(stripos($bad, '<style') === false, 'style com javascript: removido');
    check(!str_contains($bad, 'javascript:alert(1)'), 'CSS malicioso some');
    // @import (fetch externo) e expression() (mesmo maiúsculo) também derrubam o <style>.
    check(stripos(sanitize_svg('<svg xmlns="http://www.w3.org/2000/svg"><style>@import url(//evil/x.css);</style></svg>'), '<style') === false, '@import removido');
    check(stripos(sanitize_svg('<svg xmlns="http://www.w3.org/2000/svg"><STYLE>a{width:expression(alert(1))}</STYLE></svg>'), 'style') === false, 'expression() maiúsculo removido');
    // CSS legítimo (fill/stroke — caso Figma/Illustrator) é PRESERVADO.
    $ok = sanitize_svg('<svg xmlns="http://www.w3.org/2000/svg"><style>.cls-1{fill:#f00;stroke:#00f}</style><rect class="cls-1"/></svg>');
    check(stripos($ok, '<style') !== false, 'style com CSS legítimo preservado');
    check(str_contains($ok, 'fill:#f00'), 'declarações de cor preservadas');
});

test('svg: atributos on* e href javascript: continuam stripados; SVG limpo sobrevive', function () {
    $out = sanitize_svg('<svg xmlns="http://www.w3.org/2000/svg"><rect onload="alert(1)"/><a href="javascript:alert(1)">x</a></svg>');
    check(stripos($out, 'onload') === false, 'onload stripado');
    check(!str_contains($out, 'javascript:alert(1)'), 'href javascript: stripado');

    $clean = MediaService::sanitizeSvgMarkup('<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10" fill="#f00"/></svg>');
    check($clean !== null, 'SVG limpo não é rejeitado');
    check(str_contains($clean, '<rect'), 'conteúdo legítimo preservado');
    check(str_contains($clean, 'fill="#f00"') || str_contains($clean, "fill=\"#f00\""), 'atributos legítimos preservados');
});

test('svg: markup não-parseável vira null (upload rejeitado)', function () {
    check_same(null, MediaService::sanitizeSvgMarkup('isto não é xml <<<'));
    check_same(null, MediaService::sanitizeSvgMarkup(''));
});
