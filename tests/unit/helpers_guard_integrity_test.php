<?php

declare(strict_types=1);

/**
 * Hardening T12 (M4) — form_honeypot() was guarded by
 * `function_exists('form_field')` (a name that is declared NOWHERE in
 * helpers.php) instead of its own name. Since `form_field` never exists,
 * that guard was always truthy — the FIRST include was harmless (nothing
 * to redeclare yet), but a SECOND include of helpers.php (framework code
 * that requires it more than once in the same request, or a test file
 * requiring it alongside the harness) fataled with "Cannot redeclare
 * form_honeypot()". Fixed by pointing the guard at 'form_honeypot'.
 *
 * Two checks:
 *   1. A static sweep of EVERY `if (!function_exists('X')) { function Y`
 *      block in the file — X must equal Y, or the same bug class exists
 *      somewhere else. This is what actually caught the original bug.
 *   2. A live regression guard: literally re-`require` helpers.php (not
 *      require_once) and confirm the process survives — the concrete
 *      symptom the mismatched guard produced.
 */

// Self-contained regardless of *_test.php run order — require_once here
// guarantees form_honeypot (and everything else) is defined before the
// second-include test below forces a real re-execution of the file.
require_once dirname(__DIR__, 2) . '/core/helpers.php';

if (!function_exists('helpers_source')) {
    function helpers_source(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/core/helpers.php');
    }
}

test('helpers.php: todo guard function_exists(X) protege uma function X — mesmo nome', function () {
    $src = helpers_source();
    preg_match_all(
        '/if\s*\(\s*!function_exists\(\s*\'([^\']+)\'\s*\)\s*\)\s*\{/',
        $src,
        $guards,
        PREG_OFFSET_CAPTURE
    );

    // Sanity: helpers.php tem dezenas de helpers guardados; se a regex não
    // bateu nada, o teste passaria "por engano" sem checar nada de verdade.
    check(count($guards[1]) > 50, 'esperava dezenas de guards function_exists em helpers.php');

    foreach ($guards[1] as [$guardName, $offset]) {
        // A function protegida pelo guard é sempre a próxima declaração —
        // basta confirmar que "function <guardName>(" aparece logo depois
        // (janela generosa: maior bloco de doc + assinatura do arquivo).
        $after = substr($src, $offset, 2000);
        check(
            (bool) preg_match('/\bfunction\s+' . preg_quote($guardName, '/') . '\s*\(/', $after),
            "guard function_exists('{$guardName}') não protege uma function {$guardName}(...) — nome errado (mesma classe de bug do form_honeypot/form_field)"
        );
    }
});

test('helpers.php: sobrevive a um segundo include (form_honeypot não fatal-redeclara)', function () {
    check(function_exists('form_honeypot'), 'precondição: o require_once no topo deste arquivo já deve ter definido form_honeypot');
    // require (não require_once) força reexecução real do arquivo — é
    // exatamente o cenário que fatalizava antes do fix do guard.
    require dirname(__DIR__, 2) . '/core/helpers.php';
    check(function_exists('form_honeypot'), 'form_honeypot ainda deve existir depois do segundo include');
    check(str_contains(form_honeypot(), 'name="_hp"'), 'form_honeypot ainda funciona normalmente depois do segundo include');
});
