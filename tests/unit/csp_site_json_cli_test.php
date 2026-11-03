<?php

declare(strict_types=1);

/**
 * A política de conteúdo mora no site.json (`security.csp`), e dois comandos
 * a leem: o `schema:validate` acusa um bloco errado — um modo digitado errado
 * deixaria o site no open sem ninguém perceber —, e o `csp:check` mostra o
 * modo e os problemas do bloco.
 *
 * O site.json trocado e a restauração ficam em
 * tests/fixtures/site_json_cli.php.
 */

require_once dirname(__DIR__) . '/fixtures/site_json_cli.php';

test('schema:validate: um security.csp bem formado passa', function () {
    $out = sjc_velk('schema:validate', ['security' => ['csp' => [
        'mode' => 'strict',
        'allow_eval' => false,
        'frame_ancestors' => ['https://parceiro.com'],
        'allow' => ['frame' => ['https://www.youtube.com'], 'form' => ['https://*.list-manage.com']],
    ]]]);
    check(str_contains($out, 'site.json is valid'), $out);
});

test('schema:validate: modo, chave e fonte errados no security.csp são problema', function () {
    $out = sjc_velk('schema:validate', ['security' => ['csp' => ['mode' => 'estrito', 'allow' => ['frames' => ['https://a.com'], 'form' => ['https://a.com;x']]]]]);
    check(str_contains($out, 'Issues found in site.json'), $out);
    check(str_contains($out, 'site.json → security.csp.mode "estrito"'), $out);
    check(str_contains($out, 'site.json → security.csp.allow: unknown key "frames"'), $out);
    check(str_contains($out, 'site.json → security.csp.allow.form: dropped invalid source "https://a.com;x"'), $out);

    $out = sjc_velk('schema:validate', ['security' => ['csp' => 'strict']]);
    check(str_contains($out, 'site.json → security.csp must be an object'), $out);
});

test('schema:validate: o bloco da CSP no lugar errado é problema', function () {
    // Ignorado onde está, deixaria o site no open sem uma linha no log.
    $out = sjc_velk('schema:validate', ['security' => ['mode' => 'strict']]);
    check(str_contains($out, 'site.json → security: unknown key "mode"'), $out);
    $out = sjc_velk('schema:validate', ['csp' => ['mode' => 'strict']]);
    check(str_contains($out, 'site.json: "csp" goes under "security"'), $out);
});

test('csp:check: o bin/velk lê o security.csp do site.json e as variáveis do .env', function () {
    $out = sjc_velk('csp:check', ['security' => ['csp' => ['mode' => 'report', 'allow' => ['frames' => ['https://a.com']]]]], ['CSP_MODE' => 'strict']);
    check(str_contains($out, 'CSP mode: report'), $out);
    check(str_contains($out, 'ERROR  config  site.json → security.csp.allow: unknown key "frames"'), $out);
    check(str_contains($out, 'ERROR  config  CSP_MODE in .env is no longer read'), $out);
});
