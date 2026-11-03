<?php

declare(strict_types=1);

/**
 * O topo das telas de item, página e termo: "Ver no site" e um botão só com
 * o ícone de copiar, que copia o endereço público completo. A partial
 * layout/_site_link_actions.php serve as três; o termo não tinha nem o
 * "Ver no site".
 */

if (!function_exists('sla_read')) {
    function sla_read(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }
}

test('topo: item, página e termo usam os mesmos botões do endereço público', function () {
    foreach (['items/edit.php', 'pages/edit.php', 'terms/edit.php'] as $tela) {
        check(str_contains(sla_read('core/Admin/views/' . $tela), "include __DIR__ . '/../layout/_site_link_actions.php';"), $tela);
    }
    check(str_contains(sla_read('core/Admin/views/terms/edit.php'), "\$publicUrl = !\$isNew ? \$term->url() : '';"), 'o termo usa o endereço dele (vazio sem página própria)');
});

test('topo: o botão de copiar é só o ícone, com o nome para leitor de tela', function () {
    $partial = sla_read('core/Admin/views/layout/_site_link_actions.php');
    check(str_contains($partial, 'class="button button--ghost button--icon copy-link" data-copy-link="<?= e($publicUrl) ?>"'), 'botão de ícone com o link');
    check(str_contains($partial, 'aria-label="<?= __(\'items.copy_link\') ?>"'), 'o nome do botão');
    check(str_contains($partial, 'data-copied-label="<?= __(\'items.link_copied\') ?>"'), 'o aviso depois de copiar');
    foreach (['pt' => ['Copiar link', 'Link copiado'], 'en' => ['Copy link', 'Link copied'], 'es' => ['Copiar enlace', 'Enlace copiado']] as $lang => [$copiar, $copiado]) {
        $catalogo = sla_read("core/Admin/lang/{$lang}.php");
        check(str_contains($catalogo, "'items.copy_link'                => '{$copiar}',"), "{$lang}: copiar");
        check(str_contains($catalogo, "'items.link_copied'              => '{$copiado}',"), "{$lang}: copiado");
    }
});

test('topo: copiar leva o endereço completo, com um jeito para http', function () {
    $js = sla_read('core/Admin/assets/js/admin.js');
    $inicio = strpos($js, 'const initCopyLink = ');
    $fim = strpos($js, '// ── Media search', (int) $inicio);
    check($inicio !== false && $fim !== false, 'initCopyLink não encontrado');
    $fn = substr($js, (int) $inicio, (int) $fim - (int) $inicio);
    check(str_contains($fn, "new URL(btn.dataset.copyLink || '', window.location.href).href"), 'o href relativo vira endereço completo');
    check(str_contains($fn, 'await navigator.clipboard.writeText(url);'), 'a área de transferência');
    check(str_contains($fn, "document.execCommand('copy')"), 'e o jeito antigo, sem a API');
    check(str_contains($js, "bootEach('[data-copy-link]', initCopyLink);"), 'ligado no boot');
});
