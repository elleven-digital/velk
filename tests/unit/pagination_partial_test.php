<?php

declare(strict_types=1);

// e() é helper global usado pelo partial.
require_once dirname(__DIR__, 2) . '/core/helpers.php';

/** Renderiza o partial isolado, como uma view faria (include herda escopo). */
if (!function_exists('render_pagination')) {
    function render_pagination(int $page, int $totalPages, array $query = []): string
    {
        ob_start();
        include dirname(__DIR__, 2) . '/core/Admin/views/layout/pagination.php';
        return (string) ob_get_clean();
    }
}

test('partial: 1 página só → não renderiza nada', function () {
    check_same('', trim(render_pagination(1, 1)));
});

test('partial: poucas páginas → sequência completa, sem gap', function () {
    $html = render_pagination(2, 3);
    check(str_contains($html, '>1</a>'), 'tem página 1');
    check(str_contains($html, '>2</a>'), 'tem página 2');
    check(str_contains($html, '>3</a>'), 'tem página 3');
    check(!str_contains($html, 'pagination__gap'), 'sem elipse');
});

test('partial: janela compacta com elipses (6 de 42)', function () {
    $html = render_pagination(6, 42);
    foreach ([1, 4, 5, 6, 7, 8, 42] as $n) {
        check(str_contains($html, '>' . $n . '</a>'), "tem página {$n}");
    }
    check(!str_contains($html, '>2</a>'), 'não tem página 2');
    check(!str_contains($html, '>41</a>'), 'não tem página 41');
    check_same(2, substr_count($html, 'pagination__gap'));
});

test('partial: página atual marcada como ativa (uma vez)', function () {
    $html = render_pagination(6, 42);
    check_same(1, substr_count($html, 'pagination__link--active'));
});

test('partial: preserva a query atual nos hrefs, trocando só o page', function () {
    $html = render_pagination(2, 3, ['q' => 'blusa', 'status' => 'draft']);
    check(str_contains($html, 'q=blusa'), 'busca preservada');
    check(str_contains($html, 'status=draft'), 'filtro preservado');
    check(str_contains($html, 'page=3'), 'href aponta pra outra página');
    check(str_contains($html, 'href="?'), 'href relativo (sem baseUrl)');
});

test('partial: valores da query são escapados', function () {
    $html = render_pagination(1, 2, ['q' => '"><script>']);
    check(!str_contains($html, '"><script>'), 'sem injeção crua no atributo');
});
