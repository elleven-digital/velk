<?php

declare(strict_types=1);

use Velk\Models\FormSettings;

/**
 * v3.4 (conversion events): `FormSettings::sanitizeMapping()`.
 *
 * Guarda de ESCRITA: garante que só as chaves da whitelist chegam ao banco.
 * O `ClientEvents::buildUserData()` já filtra na leitura, então isto é
 * cinto-e-suspensório de propósito — uma linha editada à mão no banco não
 * pode empurrar chave arbitrária pro dataLayer, e dado ruim nem entra.
 *
 * `forForm`/`save` dependem de banco; o round-trip completo (linha ausente →
 * settings vazias, insert, upsert sem duplicar, snippet null) foi verificado
 * por script descartável contra o banco local — mesmo padrão de
 * variant_service_test.
 */

if (!function_exists('fs_sanitize')) {
    function fs_sanitize(array $mapping): array
    {
        $m = new ReflectionMethod(FormSettings::class, 'sanitizeMapping');
        return $m->invoke(null, $mapping);
    }
}

test('sanitizeMapping: mantém só as chaves da whitelist', function () {
    // O laço percorre a whitelist, não o input — mesma garantia estrutural do
    // ClickIds. `cpf` não é rejeitado: ele nunca é consultado.
    check_same(
        ['email' => 'seu_email'],
        fs_sanitize(['email' => 'seu_email', 'cpf' => 'documento', 'address' => 'rua'])
    );
});

test('sanitizeMapping: apara espaço no nome do campo', function () {
    check_same(['email' => 'campo'], fs_sanitize(['email' => '  campo  ']));
});

test('sanitizeMapping: campo vazio, só espaço ou não-string é descartado', function () {
    check_same([], fs_sanitize(['email' => '']));
    check_same([], fs_sanitize(['email' => '   ']));
    check_same([], fs_sanitize(['email' => null]));
    check_same([], fs_sanitize(['email' => ['a']]));
    check_same([], fs_sanitize([]));
});

test('sanitizeMapping: as duas chaves juntas', function () {
    check_same(
        ['email' => 'e', 'phone_number' => 'p'],
        fs_sanitize(['phone_number' => 'p', 'email' => 'e'])
    );
});
