<?php

declare(strict_types=1);

/**
 * O bloco do topo da tela do item: o título na linha toda; embaixo, o slug
 * com o status (e o template, quando o tipo tem) — sem linha divisória.
 * Antes eram título e slug na primeira linha e o status sozinho na segunda,
 * separado por uma linha.
 */

test('item: título na linha toda; slug e status na de baixo, sem linha entre elas', function () {
    $view = (string) file_get_contents(dirname(__DIR__, 2) . '/core/Admin/views/items/edit.php');
    $inicio = strpos($view, '<div class="form-meta">');
    $fim = strpos($view, '<?php if ($hierarchical ?? false): ?>', (int) $inicio);
    check($inicio !== false && $fim !== false, 'bloco do topo não encontrado');
    $meta = substr($view, (int) $inicio, (int) $fim - (int) $inicio);

    $titulo = strpos($meta, 'name="title" id="title"');
    $linha = strpos($meta, '<div class="form-row">');
    $slug = strpos($meta, 'name="slug" id="slug"');
    $status = strpos($meta, 'name="status" id="status"');
    check($titulo !== false && $linha !== false && $titulo < $linha, 'o título vem antes, fora da linha de campos');
    check($slug !== false && $status !== false && $linha < $slug && $slug < $status, 'slug e status na mesma linha, embaixo');
    check(substr_count($meta, '<div class="form-row">') === 1, 'uma linha de campos só, a do slug');

    $css = (string) file_get_contents(dirname(__DIR__, 2) . '/core/Admin/assets/css/admin.css');
    check(!str_contains($css, '.form-meta > .form-row + .form-row'), 'sem a linha divisória entre as linhas do bloco');
});
