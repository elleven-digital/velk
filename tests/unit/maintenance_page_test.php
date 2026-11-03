<?php

declare(strict_types=1);

use Velk\Maintenance;

test('maintenance: página "atualizando" tem auto-refresh e texto genérico', function () {
    $html = Maintenance::render('updating');
    check(str_contains($html, 'http-equiv="refresh"'), 'updating deve ter meta refresh');
    check(str_contains($html, 'Atualizando'), 'updating deve dizer Atualizando');
});

test('maintenance: página "falhou" é genérica e NÃO vaza detalhe interno', function () {
    $html = Maintenance::render('failed');
    check(str_contains($html, 'Atualização falhou'), 'failed deve dizer "Atualização falhou"');
    check(!str_contains($html, 'http-equiv="refresh"'), 'failed não tem auto-refresh');
    foreach (['migration', 'bin/velk', 'SELECT', 'SQL', 'GET_LOCK'] as $leak) {
        check(stripos($html, $leak) === false, "failed não pode vazar: {$leak}");
    }
});
