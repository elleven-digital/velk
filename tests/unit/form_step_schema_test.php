<?php

declare(strict_types=1);

/**
 * `steps` e `step_webhook` no schema:validate (spec 2026-09-09 §8).
 *
 * Configuração errada de etapas só apareceria em runtime como "envio
 * reprovado sem motivo visível": um nome de campo com typo sai da partição,
 * o campo nunca é validado por etapa nenhuma, e o envio final reprova por
 * obrigatório faltando. O linter é o único lugar onde isso vira erro legível.
 *
 * Molde: tests/unit/payment_schema_validate_test.php — patch + restore do
 * theme/site.json real, com backup, porque bin/velk fixa $rootPath.
 */

if (!function_exists('fss_validate')) {
    /** @param array<string,mixed> $formDef mesclado em forms.<primeiro do tema> */
    function fss_validate(array $formDef): string
    {
        $raiz = dirname(__DIR__, 2);
        $origem = $raiz . '/theme/site.json';
        if (!is_file($origem)) {
            throw new RuntimeException('fss_validate: theme/site.json ausente — este teste precisa de um theme/ real');
        }
        $backup = $origem . '.fss-backup';
        if (is_file($backup)) { rename($backup, $origem); }

        $site = json_decode((string) file_get_contents($origem), true);
        $first = array_key_first((array) ($site['forms'] ?? []));
        if ($first === null) {
            throw new RuntimeException('fss_validate: o theme/site.json não declara nenhum formulário');
        }
        $site['forms'][$first] = array_merge($site['forms'][$first], $formDef);

        if (!copy($origem, $backup)) { throw new RuntimeException('fss_validate: backup falhou'); }
        try {
            file_put_contents($origem, json_encode($site, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($raiz . '/bin/velk') . ' schema:validate 2>&1');
        } finally {
            if (!rename($backup, $origem)) { throw new RuntimeException('fss_validate: restore falhou'); }
        }
        return is_string($out) ? $out : '';
    }
}

if (!function_exists('fss_fields')) {
    /** Três campos declarados, para as etapas particionarem. */
    function fss_fields(): array
    {
        return [
            ['name' => 'nome', 'type' => 'text', 'label' => 'Nome'],
            ['name' => 'email', 'type' => 'email', 'label' => 'Email'],
            ['name' => 'msg', 'type' => 'textarea', 'label' => 'Mensagem'],
        ];
    }
}

test('validate: partição correta passa', function () {
    $out = fss_validate(['fields' => fss_fields(), 'steps' => [['nome', 'email'], ['msg']]]);
    check(str_contains($out, 'site.json is valid'), "deveria validar:\n{$out}");
});

test('validate: steps que não é lista de listas é acusado', function () {
    $out = fss_validate(['fields' => fss_fields(), 'steps' => ['nome', 'email']]);
    check(str_contains($out, "'steps' must be a list of lists of field names"), $out);
});

test('validate: nome citado em steps que não existe em fields é acusado, com a lista de válidos', function () {
    $out = fss_validate(['fields' => fss_fields(), 'steps' => [['nome', 'emial'], ['msg']]]);
    check(str_contains($out, 'step 1 names "emial", which is not a field of this form'), $out);
    check(str_contains($out, 'nome, email, msg'), "deveria listar os nomes válidos:\n{$out}");
});

test('validate: campo de fields que não aparece em nenhuma etapa é acusado', function () {
    $out = fss_validate(['fields' => fss_fields(), 'steps' => [['nome', 'email']]]);
    check(str_contains($out, 'field "msg" is not in any step'), $out);
});

test('validate: campo repetido em duas etapas é acusado', function () {
    $out = fss_validate(['fields' => fss_fields(), 'steps' => [['nome', 'email'], ['email', 'msg']]]);
    check(str_contains($out, 'field "email" appears in more than one step'), $out);
});

test('validate: step_webhook sem steps não tem efeito e é acusado', function () {
    $out = fss_validate(['fields' => fss_fields(), 'step_webhook' => 'manda_pro_crm']);
    check(str_contains($out, "'step_webhook' has no effect without 'steps'"), $out);
});

test('validate: step_webhook com steps passa', function () {
    $out = fss_validate([
        'fields' => fss_fields(),
        'steps' => [['nome', 'email'], ['msg']],
        'step_webhook' => 'manda_pro_crm',
    ]);
    check(str_contains($out, 'site.json is valid'), "deveria validar:\n{$out}");
});
