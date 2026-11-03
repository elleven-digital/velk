<?php

declare(strict_types=1);

use Velk\PublicFormController;

/**
 * M5: PublicFormController::validate() stored/emailed a `select` submission
 * verbatim, without checking it's one of the field's declared `options`
 * (theme/site.json → forms.*.fields[].options, the same {value: label} map
 * used by content select fields — see the real "revenue" field in
 * theme/site.json). A tampered/arbitrary value (not one of the <option>s
 * the theme actually renders) went straight to the DB and the recipient
 * email. validate() is a private INSTANCE method — reached via Reflection.
 */

if (!function_exists('pfc_validate')) {
    /** @return array{0: array, 1: array} [$values, $errors] */
    function pfc_validate(array $fields, array $post): array
    {
        $method = new ReflectionMethod(PublicFormController::class, 'validate');
        return $method->invoke(new PublicFormController(), $fields, $post);
    }
}

if (!function_exists('pfc_select_field')) {
    function pfc_select_field(): array
    {
        return [[
            'name' => 'revenue',
            'type' => 'select',
            'label' => 'Faturamento anual',
            'options' => [
                '' => 'Selecione',
                '<4M' => 'Abaixo de R$ 4M',
                '4-10M' => 'R$ 4M – R$ 10M',
            ],
        ]];
    }
}

test('select: valor declarado em options é aceito, sem erro', function () {
    [$values, $errors] = pfc_validate(pfc_select_field(), ['revenue' => '4-10M']);
    check_same([], $errors);
    check_same('4-10M', $values['revenue']);
});

test('select: valor FORA de options é rejeitado (tampering / não é um <option> real)', function () {
    [, $errors] = pfc_validate(pfc_select_field(), ['revenue' => 'literalmente-qualquer-coisa']);
    check(isset($errors['revenue']), 'valor não-declarado deve gerar erro de validação');
});

test('select: vazio (placeholder "Selecione") não é required por padrão — não gera erro de opção inválida', function () {
    [, $errors] = pfc_validate(pfc_select_field(), ['revenue' => '']);
    check(!isset($errors['revenue']), 'campo vazio e não-required não deve reprovar por opção inválida');
});

test('select: campo required + valor inválido gera só o erro apropriado', function () {
    $fields = pfc_select_field();
    $fields[0]['required'] = true;
    [, $errors] = pfc_validate($fields, ['revenue' => 'nao-existe']);
    check(isset($errors['revenue']));
});

test('select: sem options declaradas no field, mantém compat (não reprova arbitrariamente)', function () {
    $fields = [['name' => 'x', 'type' => 'select', 'label' => 'X']]; // sem 'options'
    [, $errors] = pfc_validate($fields, ['x' => 'qualquer-coisa']);
    check_same([], $errors, 'select sem options declaradas não tem base pra validar contra — não pode quebrar formulários existentes');
});

test('select: campo ausente do POST continua tratado como vazio (não quebra required check)', function () {
    [$values, $errors] = pfc_validate(pfc_select_field(), []);
    check_same('', $values['revenue']);
    check(!isset($errors['revenue']));
});
