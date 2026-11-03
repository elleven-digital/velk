<?php

declare(strict_types=1);

use Velk\Services\FormCsv;

/**
 * As chaves de idioma da exportação em CSV existem em pt, en e es.
 *
 * Duas lacunas que o `bin/velk i18n:scan-admin` deixa, e que este arquivo
 * fecha. Os rótulos dos metadados (`FormCsv::META`) passam por um resolvedor,
 * não por `__()` literal, e o scanner não os enxerga — aparecem como "dead" no
 * relatório dele. E as chaves do bloco "Exportar", que ele enxerga, só o
 * REPROVAM quando faltam em en.php; lacuna em pt ou es sai como aviso, e o
 * painel cairia no texto em inglês para quem usa o admin nesses idiomas.
 */

test('i18n: as chaves da exportação existem em pt, en e es', function () {
    $dir = dirname(__DIR__, 2) . '/core/Admin/lang';
    $catalogs = [
        'en' => require $dir . '/en.php',
        'pt' => require $dir . '/pt.php',
        'es' => require $dir . '/es.php',
    ];

    $exportKeys = array_values(array_filter(
        array_keys($catalogs['en']),
        fn(string $k): bool => str_starts_with($k, 'forms.export_')
    ));
    check(in_array('forms.export_submit', $exportKeys, true), 'o bloco "Exportar" tem as chaves dele em en.php');

    foreach ($catalogs as $locale => $catalog) {
        foreach (FormCsv::META as $key) {
            check(isset($catalog[$key]), "{$key} falta em {$locale}.php");
        }
        foreach ($exportKeys as $key) {
            check(isset($catalog[$key]), "{$key} falta em {$locale}.php");
        }
    }
});
