<?php

declare(strict_types=1);

use Velk\App;
use Velk\Config;

/**
 * Task 6 de "ordem manual": o MARKUP que items/index.php e terms/index.php
 * emitem para o contrato genérico de arrasto do admin.js (initSortableTable)
 * — data-sortable-table/data-sortable-endpoint/data-csrf no <tbody>,
 * draggable+data-sortable-id em cada <tr>, a alça como primeiro <td> — e
 * SÓ quando $ordemManual (o que o controller lê de Item::manualOrder() /
 * Term::manualOrder()) está ligado.
 *
 * Não testa admin.js (contrato genérico, comentado e já em uso pelos
 * scripts de rastreamento) nem os endpoints /reorder — auth, CSRF, 404
 * sem a flag e a permuta em si já estão cobertos por reorder_endpoint_test.php.
 * Aqui é só a VIEW: o HTML certo, no estado certo, com o número de colunas
 * batendo entre <thead> e <tbody> — nos dois estados da flag. É exatamente
 * a classe de bug mais fácil de introduzir nesta task: acrescentar o <td>
 * da alça sem o <th> correspondente (ou vice-versa) deixa a tabela com uma
 * coluna a mais ou a menos, sem erro de PHP nenhum pra acusar.
 *
 * As views são incluídas DIRETAMENTE — mesma técnica de
 * pagination_partial_test.php (ob_start + include, variáveis no escopo da
 * função) — em vez de passar por Controller::render(), que embrulharia no
 * layout inteiro (sidebar, nav) só pra testar duas linhas condicionais.
 * Ainda assim App::instance() precisa existir de verdade: admin_url(),
 * csrf_token() e __() dependem dele. O boot abaixo é o mínimo pra isso —
 * inspirado no roe_boot() de reorder_endpoint_test.php, sem banco (os
 * items/terms da view são stdClass fabricados, não lidos de lugar nenhum) —
 * e usa o catálogo de idioma REAL (core/Admin/lang/en.php), então um
 * 'items.drag_hint'/'terms.drag_hint' esquecido lá quebraria __() aqui
 * também, não só o gate i18n:scan-admin.
 */

// admin_url(), e() e csrf_token() são helpers globais de core/helpers.php
// — não vêm pelo autoloader de classes que o harness registra. Cada função
// lá é guardada por function_exists(), então um require_once é seguro
// independente da ordem de carga entre os arquivos *_test.php.
require_once dirname(__DIR__, 2) . '/core/helpers.php';

if (!function_exists('mov_boot')) {
    function mov_boot(): void
    {
        $rootPath = dirname(__DIR__, 2);

        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, ['paths' => ['core' => $rootPath . '/core']]);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, [
            'item_types' => [],
            'taxonomies' => [],
        ]);

        $app = new App($config);
        $app->basePath = '';

        $_SESSION = [];
        $app->session = new \Velk\Session();
        $app->adminI18n = new \Velk\Admin\I18n($rootPath . '/core/Admin/lang', 'en', 'en');
    }
}

if (!function_exists('mov_render_items')) {
    /** Renderiza items/index.php isolada, como uma view faria (include herda escopo). */
    function mov_render_items(bool $ordemManual, array $items): string
    {
        $type = 'produto';
        $typeConfig = ['label' => 'Produtos'];
        $search = '';
        $statusFilter = '';
        $total = count($items);
        $pg = ['page' => 1, 'totalPages' => 1];
        $pgQuery = [];

        ob_start();
        include dirname(__DIR__, 2) . '/core/Admin/views/items/index.php';
        return (string) ob_get_clean();
    }
}

if (!function_exists('mov_render_terms')) {
    /** Renderiza terms/index.php isolada — mesma técnica de mov_render_items(). */
    function mov_render_terms(bool $ordemManual, array $terms): string
    {
        $taxonomy = 'categoria';
        $taxonomyConfig = ['label' => 'Categorias'];
        $total = count($terms);
        $pg = ['page' => 1, 'totalPages' => 1];
        $pgQuery = [];

        ob_start();
        include dirname(__DIR__, 2) . '/core/Admin/views/terms/index.php';
        return (string) ob_get_clean();
    }
}

if (!function_exists('mov_um_item')) {
    /** @return list<object> */
    function mov_um_item(): array
    {
        return [(object) [
            'id' => 7,
            'title' => 'Produto X',
            'slug' => 'produto-x',
            'status' => 'published',
            'updatedAt' => '2026-01-01 10:00:00',
        ]];
    }
}

if (!function_exists('mov_um_termo')) {
    /** @return list<object> */
    function mov_um_termo(): array
    {
        return [(object) ['id' => 9, 'name' => 'Termo X', 'slug' => 'termo-x']];
    }
}

if (!function_exists('mov_col_counts')) {
    /**
     * [total de <th> dentro do <thead>, total de <td> na primeira <tr> do <tbody>].
     * Não usa DOMDocument (sem certeza de disponibilidade da extensão no
     * ambiente de teste) — regex simples é suficiente pra HTML sem tabelas
     * aninhadas, mesmo espírito de pagination_partial_test.php.
     */
    function mov_col_counts(string $html): array
    {
        preg_match('#<thead>(.*?)</thead>#s', $html, $head);
        $thCount = substr_count($head[1] ?? '', '<th');

        preg_match('#<tbody[^>]*>.*?<tr[^>]*>(.*?)</tr>#s', $html, $row);
        $tdCount = substr_count($row[1] ?? '', '<td');

        return [$thCount, $tdCount];
    }
}

// ─── items/index.php ───────────────────────────────────────────────────────

test('items/index: sem a flag, <tbody> não ganha nenhum atributo data-sortable-* e a linha não é arrastável', function () {
    mov_boot();
    $html = mov_render_items(false, mov_um_item());

    check(!str_contains($html, 'data-sortable-table'), 'data-sortable-table vazou sem a flag');
    check(!str_contains($html, 'data-sortable-id'), 'data-sortable-id vazou sem a flag');
    check(!str_contains($html, 'draggable='), 'draggable vazou sem a flag');
    check(!str_contains($html, 'sortable-handle'), 'alça vazou sem a flag');
    check(!str_contains($html, 'Drag to reorder'), 'dica de arrasto vazou sem a flag');
});

test('items/index: com a flag, <tbody> ganha o contrato completo do initSortableTable e a linha vem com draggable+id', function () {
    mov_boot();
    $html = mov_render_items(true, mov_um_item());

    check(str_contains($html, 'data-sortable-table'), 'data-sortable-table ausente com a flag ligada');
    check(
        str_contains($html, 'data-sortable-endpoint="/admin/items/produto/reorder"'),
        'endpoint errado — precisa ser /admin/items/{type}/reorder'
    );
    check(str_contains($html, 'data-csrf="'), 'data-csrf ausente');
    check(str_contains($html, 'draggable="true" data-sortable-id="7"'), 'linha sem draggable+data-sortable-id do item certo');
    check(str_contains($html, 'sortable-handle'), 'alça ausente com a flag ligada');
    check(str_contains($html, 'Drag to reorder'), 'dica de arrasto ausente com a flag ligada');
});

test('items/index: número de <th> no thead bate com o número de <td> na linha, com e sem a flag — e a flag acrescenta exatamente 1 coluna', function () {
    mov_boot();
    [$thOff, $tdOff] = mov_col_counts(mov_render_items(false, mov_um_item()));
    [$thOn, $tdOn] = mov_col_counts(mov_render_items(true, mov_um_item()));

    check_same($thOff, $tdOff, 'sem a flag: th e td já não batiam antes desta task');
    check_same($thOn, $tdOn, 'com a flag: a alça criou <td> sem <th> correspondente (ou vice-versa)');
    check_same($thOff + 1, $thOn, 'a flag deveria acrescentar exatamente 1 <th> (a alça)');
    check_same($tdOff + 1, $tdOn, 'a flag deveria acrescentar exatamente 1 <td> por linha (a alça)');
});

test('items/index: lista vazia não tem <table> nenhuma — não existe linha de "nenhum registro" com colspan pra manter em dia', function () {
    mov_boot();
    $html = mov_render_items(true, []);

    check(str_contains($html, 'class="empty"'), 'estado vazio deveria cair no bloco .empty');
    check(!str_contains($html, '<table'), 'lista vazia não deveria renderizar tabela nenhuma');
});

// ─── terms/index.php ───────────────────────────────────────────────────────

test('terms/index: sem a flag, <tbody> não ganha nenhum atributo data-sortable-* e a linha não é arrastável', function () {
    mov_boot();
    $html = mov_render_terms(false, mov_um_termo());

    check(!str_contains($html, 'data-sortable-table'), 'data-sortable-table vazou sem a flag');
    check(!str_contains($html, 'data-sortable-id'), 'data-sortable-id vazou sem a flag');
    check(!str_contains($html, 'draggable='), 'draggable vazou sem a flag');
    check(!str_contains($html, 'sortable-handle'), 'alça vazou sem a flag');
    check(!str_contains($html, 'Drag to reorder'), 'dica de arrasto vazou sem a flag');
});

test('terms/index: com a flag, <tbody> ganha o contrato completo do initSortableTable, com o prefixo /taxonomies/ (não /terms/)', function () {
    mov_boot();
    $html = mov_render_terms(true, mov_um_termo());

    check(str_contains($html, 'data-sortable-table'), 'data-sortable-table ausente com a flag ligada');
    check(
        str_contains($html, 'data-sortable-endpoint="/admin/taxonomies/categoria/reorder"'),
        'endpoint errado — precisa ser /admin/taxonomies/{taxonomy}/reorder, NUNCA /admin/terms/...'
    );
    check(str_contains($html, 'data-csrf="'), 'data-csrf ausente');
    check(str_contains($html, 'draggable="true" data-sortable-id="9"'), 'linha sem draggable+data-sortable-id do termo certo');
    check(str_contains($html, 'sortable-handle'), 'alça ausente com a flag ligada');
    check(str_contains($html, 'Drag to reorder'), 'dica de arrasto ausente com a flag ligada');
});

test('terms/index: número de <th> no thead bate com o número de <td> na linha, com e sem a flag — e a flag acrescenta exatamente 1 coluna', function () {
    mov_boot();
    [$thOff, $tdOff] = mov_col_counts(mov_render_terms(false, mov_um_termo()));
    [$thOn, $tdOn] = mov_col_counts(mov_render_terms(true, mov_um_termo()));

    check_same($thOff, $tdOff, 'sem a flag: th e td já não batiam antes desta task');
    check_same($thOn, $tdOn, 'com a flag: a alça criou <td> sem <th> correspondente (ou vice-versa)');
    check_same($thOff + 1, $thOn, 'a flag deveria acrescentar exatamente 1 <th> (a alça)');
    check_same($tdOff + 1, $tdOn, 'a flag deveria acrescentar exatamente 1 <td> por linha (a alça)');
});

test('terms/index: lista vazia não tem <table> nenhuma — não existe linha de "nenhum registro" com colspan pra manter em dia', function () {
    mov_boot();
    $html = mov_render_terms(true, []);

    check(str_contains($html, 'class="empty"'), 'estado vazio deveria cair no bloco .empty');
    check(!str_contains($html, '<table'), 'lista vazia não deveria renderizar tabela nenhuma');
});
