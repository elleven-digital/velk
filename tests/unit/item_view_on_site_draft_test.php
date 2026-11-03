<?php

declare(strict_types=1);

/**
 * "Ver no site" na tela do item também no rascunho.
 *
 * O site mostra o rascunho a quem está logado no painel
 * (FrontRouter::renderItemResponse) — é a prévia antes de publicar —, mas o
 * botão só aparecia com o item publicado: para ver o rascunho, era preciso
 * montar a URL à mão.
 */

test('item: "Ver no site" aparece no rascunho também', function () {
    $view = (string) file_get_contents(dirname(__DIR__, 2) . '/core/Admin/views/items/edit.php');
    check(str_contains($view, "\$publicUrl = !\$isNew ? \$item->url() : '';"), 'o endereço do item salvo, qualquer que seja o status');
    check(str_contains($view, "include __DIR__ . '/../layout/_site_link_actions.php';"), 'os botões vêm da partial');
    check(!preg_match("/status === 'published'[^\\n]*publicUrl/", $view), 'sem exigir o item publicado');
    $partial = (string) file_get_contents(dirname(__DIR__, 2) . '/core/Admin/views/layout/_site_link_actions.php');
    check(str_contains($partial, "if ((\$publicUrl ?? '') === '') return;"), 'a partial só esconde quando não há página pública');

    // A prévia existe mesmo: o site serve o rascunho ao admin logado.
    $router = (string) file_get_contents(dirname(__DIR__, 2) . '/core/FrontRouter.php');
    check(str_contains($router, "if (\$item->status !== 'published' && !App::instance()->auth->check()) {"), 'o rascunho só some para quem não está logado');
});
