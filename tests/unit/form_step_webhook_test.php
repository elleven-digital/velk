<?php

declare(strict_types=1);

use Velk\App;

/**
 * `step_webhook` e o `$context` estendido (spec 2026-09-09 §5).
 *
 * Duas chaves, e não uma, porque os dois momentos pedem handlers diferentes
 * na prática: sincronizar contato parcial no CRM não é a mesma coisa que
 * avisar o time que chegou lead completo. Compartilhar a chave obrigaria todo
 * handler a começar com um `if`.
 *
 * `data` no contexto existe porque o `$values` de um `step_webhook` da etapa
 * 2 traz só os campos DAQUELA etapa — e o handler que sincroniza o CRM
 * precisa do e-mail da etapa 1 para saber QUAL contato atualizar. Sem ele,
 * todo handler iria ao banco buscar o que o engine já tinha na mão.
 *
 * `completed` não é derivável de `step === total_steps`: um reenvio da etapa 2
 * num lead que está na 3 tem step 2, total 3, e não completa nada.
 */

require_once dirname(__DIR__) . '/fixtures/form_step_boot.php';

if (!function_exists('fsw_boot')) {
    function fsw_boot(array $steps, array $extra = []): App
    {
        $app = fst_boot($steps, [
            ['name' => 'nome', 'type' => 'text', 'required' => true],
            ['name' => 'email', 'type' => 'email', 'required' => true],
            ['name' => 'cep', 'type' => 'text', 'required' => true],
        ]);
        // Enxerta as chaves de webhook no site.json já montado.
        $ref = new ReflectionProperty(Velk\Config::class, 'site');
        $site = $ref->getValue($app->config);
        $site['forms']['orcamento'] = array_merge($site['forms']['orcamento'], $extra);
        $ref->setValue($app->config, $site);
        $GLOBALS['fsw_calls'] = [];
        return $app;
    }
}

if (!function_exists('fsw_record_step')) {
    function fsw_record_step(string $formId, array $values, array $context): void
    {
        $GLOBALS['fsw_calls'][] = ['kind' => 'step', 'values' => $values, 'context' => $context];
    }
}

if (!function_exists('fsw_record_final')) {
    function fsw_record_final(string $formId, array $values, array $context): void
    {
        $GLOBALS['fsw_calls'][] = ['kind' => 'final', 'values' => $values, 'context' => $context];
    }
}

if (!function_exists('fsw_kinds')) {
    /** @return list<string> */
    function fsw_kinds(): array
    {
        return array_map(static fn($c) => $c['kind'], $GLOBALS['fsw_calls'] ?? []);
    }
}

test('step_webhook dispara em TODA etapa, a primeira inclusive; webhook só no fim', function () {
    $app = fsw_boot([['nome', 'email'], ['cep']], [
        'step_webhook' => 'fsw_record_step',
        'webhook' => 'fsw_record_final',
    ]);

    $t = fst_post($app, ['_step' => 1, 'nome' => 'Ana', 'email' => 'a@x.dev'])['body']['token'];
    check_same(['step'], fsw_kinds(), 'a etapa 1 é o caso de uso principal do step_webhook');

    fst_post($app, ['_step' => 2, '_step_token' => $t, 'cep' => '01001-000']);
    check_same(['step', 'step', 'final'], fsw_kinds(), 'a final dispara os dois');
});

test('$context da etapa 1 traz step/total_steps/completed/data', function () {
    $app = fsw_boot([['nome', 'email'], ['cep']], ['step_webhook' => 'fsw_record_step']);
    fst_post($app, ['_step' => 1, 'nome' => 'Ana', 'email' => 'a@x.dev']);

    $c = $GLOBALS['fsw_calls'][0]['context'];
    check_same(1, $c['step']);
    check_same(2, $c['total_steps']);
    check_same(false, $c['completed']);
    check_same(['nome' => 'Ana', 'email' => 'a@x.dev'], $c['data']);
    check(isset($c['submission_id'], $c['ip'], $c['params'], $c['created_at']), 'as chaves antigas seguem');
});

test('$context: data é o ACUMULADO, values é só a etapa', function () {
    // O ponto do `data`: o handler do CRM precisa do e-mail da etapa 1 para
    // saber qual contato atualizar quando chega o CEP da etapa 2.
    $app = fsw_boot([['nome', 'email'], ['cep']], ['step_webhook' => 'fsw_record_step']);
    $t = fst_post($app, ['_step' => 1, 'nome' => 'Ana', 'email' => 'a@x.dev'])['body']['token'];
    fst_post($app, ['_step' => 2, '_step_token' => $t, 'cep' => '01001-000']);

    $c = $GLOBALS['fsw_calls'][1];
    check_same(['cep' => '01001-000'], $c['values'], 'values é só o que chegou agora');
    check_same(['nome' => 'Ana', 'email' => 'a@x.dev', 'cep' => '01001-000'], $c['context']['data'], 'data é o lead inteiro');
    check_same(true, $c['context']['completed'], 'a etapa 2 é a final aqui');
});

test('$context: submission_id é o MESMO em todas as etapas', function () {
    $app = fsw_boot([['nome', 'email'], ['cep']], ['step_webhook' => 'fsw_record_step']);
    $t = fst_post($app, ['_step' => 1, 'nome' => 'Ana', 'email' => 'a@x.dev'])['body']['token'];
    fst_post($app, ['_step' => 2, '_step_token' => $t, 'cep' => '01001-000']);

    $ids = array_map(static fn($c) => $c['context']['submission_id'], $GLOBALS['fsw_calls']);
    check_same(1, count(array_unique($ids)), 'é por ele que o CRM correlaciona as etapas');
});

test('$context: reenvio de etapa anterior tem completed false', function () {
    $app = fsw_boot([['nome', 'email'], ['cep'], ['x']], ['step_webhook' => 'fsw_record_step']);
    $t = fst_post($app, ['_step' => 1, 'nome' => 'Ana', 'email' => 'a@x.dev'])['body']['token'];
    fst_post($app, ['_step' => 2, '_step_token' => $t, 'cep' => '01001-000']);
    $GLOBALS['fsw_calls'] = [];

    fst_post($app, ['_step' => 1, '_step_token' => $t, 'nome' => 'Ana', 'email' => 'b@x.dev']);

    $c = $GLOBALS['fsw_calls'][0]['context'];
    check_same(1, $c['step']);
    check_same(3, $c['total_steps']);
    check_same(false, $c['completed'], 'step 1 de 3: nunca completa');
});

test('formulário SEM steps: o webhook antigo recebe as quatro chaves novas com valores coerentes', function () {
    $app = fsw_boot([], ['webhook' => 'fsw_record_final']);
    fst_post($app, ['nome' => 'Ana', 'email' => 'a@x.dev', 'cep' => '01001-000']);

    $c = $GLOBALS['fsw_calls'][0]['context'];
    check_same(1, $c['step']);
    check_same(1, $c['total_steps']);
    check_same(true, $c['completed']);
    check_same(['nome' => 'Ana', 'email' => 'a@x.dev', 'cep' => '01001-000'], $c['data']);
});

test('formulário sem steps ignora step_webhook — não há etapa para disparar', function () {
    $app = fsw_boot([], ['step_webhook' => 'fsw_record_step', 'webhook' => 'fsw_record_final']);
    fst_post($app, ['nome' => 'Ana', 'email' => 'a@x.dev', 'cep' => '01001-000']);
    check_same(['final'], fsw_kinds());
});
