<?php

declare(strict_types=1);

use Velk\Config;

/**
 * `forms.<id>.steps` (spec 2026-09-09): as três leituras que traduzem a
 * declaração do site.json. São o ÚNICO lugar onde o array 0-based do JSON
 * vira etapa 1-based — o resto do sistema só fala 1-based.
 *
 * Declaração malformada devolve [] de propósito, ou seja "sem etapas": o
 * `schema:validate` é quem recusa a configuração errada, e em runtime cair no
 * comportamento de hoje é a degradação segura. O contrário — adivinhar uma
 * partição a partir de lixo — geraria etapa com campo faltando e reprovaria
 * envio legítimo.
 */

if (!function_exists('fsc_config')) {
    function fsc_config(array $forms): Config
    {
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, []);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, ['forms' => $forms]);
        return $config;
    }
}

if (!function_exists('fsc_ok')) {
    /** Um formulário bem formado com três etapas. */
    function fsc_ok(): Config
    {
        return fsc_config(['orcamento' => [
            'steps' => [['name', 'email'], ['cep', 'city'], ['budget']],
            'fields' => [],
        ]]);
    }
}

test('formSteps: devolve as etapas declaradas, na ordem', function () {
    check_same([['name', 'email'], ['cep', 'city'], ['budget']], fsc_ok()->formSteps('orcamento'));
});

test('formSteps: sem a chave, ou formulário inexistente, é []', function () {
    check_same([], fsc_config(['contato' => ['fields' => []]])->formSteps('contato'));
    check_same([], fsc_ok()->formSteps('nao-existe'));
});

test('formSteps: declaração malformada é [] — o schema:validate é quem recusa', function () {
    check_same([], fsc_config(['x' => ['steps' => 'name,email']])->formSteps('x'), 'string não é partição');
    check_same([], fsc_config(['x' => ['steps' => ['name', 'email']]])->formSteps('x'), 'lista de strings não é lista de listas');
    check_same([], fsc_config(['x' => ['steps' => [[]]]])->formSteps('x'), 'etapa vazia');
    check_same([], fsc_config(['x' => ['steps' => [['name'], []]]])->formSteps('x'), 'uma etapa vazia invalida tudo');
    check_same([], fsc_config(['x' => ['steps' => []]])->formSteps('x'), 'lista vazia é "sem etapas"');
});

test('formSteps: nome não-string dentro de uma etapa é descartado', function () {
    check_same([['name']], fsc_config(['x' => ['steps' => [['name', 42, '']]]])->formSteps('x'));
});

test('formHasSteps: é o "permite etapas" — presença de steps bem formadas', function () {
    check_same(true, fsc_ok()->formHasSteps('orcamento'));
    check_same(false, fsc_config(['contato' => ['fields' => []]])->formHasSteps('contato'));
    check_same(false, fsc_config(['x' => ['steps' => 'errado']])->formHasSteps('x'));
});

test('formStepFields: é 1-BASED — a etapa 1 é a primeira', function () {
    $c = fsc_ok();
    check_same(['name', 'email'], $c->formStepFields('orcamento', 1));
    check_same(['cep', 'city'], $c->formStepFields('orcamento', 2));
    check_same(['budget'], $c->formStepFields('orcamento', 3));
});

test('formStepFields: fora do intervalo é [], nunca erro', function () {
    $c = fsc_ok();
    check_same([], $c->formStepFields('orcamento', 0), 'zero não é etapa');
    check_same([], $c->formStepFields('orcamento', 4));
    check_same([], $c->formStepFields('orcamento', -1));
    check_same([], $c->formStepFields('nao-existe', 1));
});
