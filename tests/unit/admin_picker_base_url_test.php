<?php

declare(strict_types=1);

/**
 * O picker de mídia (image + gallery) NÃO pode derivar a URL do admin a partir
 * do href do <link> do admin.css.
 *
 * Com o cache-busting (admin_asset()), esse href vira
 * `…/__static/css/admin.css?v=<mtime>`, e o `replace('/__static/css/admin.css','')`
 * deixava o `?v=<mtime>` pra trás — produzindo `…/admin?v=123/media?picker=1`, que
 * o navegador resolve como `/admin` (o dashboard) com query lixo. Resultado: o modal
 * "Biblioteca de mídia" abria o admin INTEIRO em vez do picker.
 *
 * A fonte correta é `ADMIN_BASE` (= `document.body.dataset.adminBase`), que não tem
 * query string. Este teste proíbe a derivação frágil reaparecer.
 */
test('admin.js: picker usa ADMIN_BASE, não deriva URL do href do admin.css', function () {
    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/core/Admin/assets/js/admin.js');

    check(
        !str_contains($js, '/__static/css/admin.css'),
        'admin.js ainda deriva a URL do <link> do admin.css (quebra com o cache-bust ?v=); use ADMIN_BASE.'
    );
});
