<?php

declare(strict_types=1);

use Velk\Admin\Controllers\OptionController;

/**
 * Hardening T10 (M7) — translation saves MERGE, not full-replace.
 *
 * OptionController::edit (and the identical pattern in Item/Term/Page
 * controllers) used to rebuild the ENTIRE value_translations/
 * slug_translations map from the current POST alone: it iterated every
 * ENABLED language and wrote whatever it found (defaulting to "nothing"
 * for a language the POST didn't include), then persisted that map as
 * the new, complete stored value. A language whose panel wasn't part of
 * THIS particular submission — disabled at render time, or a trimmed/
 * tampered form — had its previously-saved translation silently wiped.
 *
 * Controller::mergeTranslations() is the shared fix: given the EXISTING
 * stored map and an $updates map built from ONLY the languages actually
 * present in the POST (array_key_exists, not just non-empty), it merges
 * updates in and leaves every other language byte-for-byte untouched.
 * It's a pure, static, DB-free function — tested directly here via
 * Reflection (protected). The controllers themselves (Request/CSRF/DB
 * plumbing around it) are covered by the throwaway functional script.
 */

if (!function_exists('merge_translations')) {
    function merge_translations(array $existing, array $updates): array
    {
        // mergeTranslations is `protected static` on the shared Controller
        // base — any concrete controller works as the reflection target.
        // No setAccessible(): private/protected members are reflectable
        // by default since PHP 8.1.
        $method = new ReflectionMethod(OptionController::class, 'mergeTranslations');
        return $method->invoke(null, $existing, $updates);
    }
}

test('mergeTranslations: idioma ausente de $updates preserva o valor existente intacto', function () {
    // O caso central do bug: um idioma cujo painel não veio nesta
    // submissão (array_key_exists falso no POST) não deve ser tocado.
    $existing = ['en' => ['title' => 'Hello'], 'es' => ['title' => 'Hola']];
    check_same($existing, merge_translations($existing, []));
});

test('mergeTranslations: idioma presente com valor novo substitui SÓ aquele idioma', function () {
    $existing = ['en' => ['title' => 'Hello'], 'es' => ['title' => 'Hola']];
    $merged = merge_translations($existing, ['en' => ['title' => 'Hi']]);
    check_same(['en' => ['title' => 'Hi'], 'es' => ['title' => 'Hola']], $merged, 'es intocado');
});

test('mergeTranslations: idioma presente com valor null REMOVE a entrada (tab em branco = sem tradução)', function () {
    // Contrato existente (empty-translation-drops, ver AGENTS.md, "i18n Phase 3"):
    // tab enviado em branco apaga a tradução, não a congela como vazia.
    $existing = ['en' => ['title' => 'Hello'], 'es' => ['title' => 'Hola']];
    $merged = merge_translations($existing, ['en' => null]);
    check_same(['es' => ['title' => 'Hola']], $merged);
});

test('mergeTranslations: idioma novo (não existia antes) é adicionado', function () {
    $existing = ['es' => ['title' => 'Hola']];
    $merged = merge_translations($existing, ['en' => ['title' => 'Hi']]);
    check_same(['es' => ['title' => 'Hola'], 'en' => ['title' => 'Hi']], $merged);
});

test('mergeTranslations: vários idiomas atualizados de uma vez, o resto preservado', function () {
    $existing = ['en' => ['a' => 1], 'es' => ['a' => 2], 'fr' => ['a' => 3]];
    $merged = merge_translations($existing, ['en' => ['a' => 10], 'fr' => null]);
    check_same(['en' => ['a' => 10], 'es' => ['a' => 2]], $merged, 'en atualizado, fr removido, es intocado');
});

test('mergeTranslations: existing vazio (criação nova) — só o que veio em $updates sobrevive', function () {
    $merged = merge_translations([], ['en' => ['title' => 'Hi'], 'es' => null]);
    check_same(['en' => ['title' => 'Hi']], $merged, 'equivalente ao comportamento anterior quando não há nada pra preservar');
});

test('mergeTranslations: $updates vazio e $existing vazio → []', function () {
    check_same([], merge_translations([], []));
});
