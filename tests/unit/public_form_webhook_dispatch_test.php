<?php

declare(strict_types=1);

use Velk\Models\FormSubmission;
use Velk\PublicFormController;

/**
 * Regressão do refactor pra WebhookDispatcher (task 1 do payment-webhook —
 * ver core/Services/WebhookDispatcher.php): PublicFormController::dispatchWebhook
 * precisa continuar chamando os handlers declarados em `forms.<id>.webhook`
 * com EXATAMENTE os mesmos argumentos posicionais de antes ($formId,
 * $values, $context montado a partir da submission), preservar o no-op
 * quando não há webhook declarado, e manter as linhas de log reconhecíveis
 * (formato NOVO "[Velk webhook form=<id>] fn=<name>: ..." — o fechamento
 * do colchete se deslocou pra depois de "form=<id>" porque o dispatcher é
 * genérico e compartilhado; mesma informação, mesma ordem, ver
 * task-1-report.md pro antes/depois completo).
 *
 * dispatchWebhook é INSTANCE method privado, e só lê campos públicos de
 * FormSubmission — sem tocar banco — então a instância é montada à mão
 * (mesmo padrão de reflection de public_form_select_validation_test.php,
 * sem precisar da fixture de banco usada em form_submission_filter_where_test.php).
 */

if (!function_exists('pfc_dispatch_webhook')) {
    function pfc_dispatch_webhook(string $formId, array $form, FormSubmission $submission, array $values): void
    {
        $method = new ReflectionMethod(PublicFormController::class, 'dispatchWebhook');
        $method->invoke(new PublicFormController(), $formId, $form, $submission, $values);
    }
}

if (!function_exists('pfc_test_submission')) {
    function pfc_test_submission(): FormSubmission
    {
        $s = new FormSubmission();
        $s->id = 42;
        $s->formId = 'contato';
        $s->ip = '203.0.113.9';
        $s->userAgent = 'TestAgent/1.0';
        $s->referer = 'https://example.com/contato';
        $s->params = [
            'utm_source'   => 'google',
            'utm_medium'   => 'cpc',
            'utm_campaign' => 'inverno',
            'gclid'        => 'EAIaIQobChMI123',
        ];
        $s->createdAt = '2026-07-25 12:00:00';
        return $s;
    }
}

if (!function_exists('pfc_webhook_handler')) {
    function pfc_webhook_handler(...$args): void
    {
        $GLOBALS['__pfc_webhook_calls'][] = $args;
    }
}

if (!function_exists('pfc_capture_log')) {
    /** @return list<string> Linhas capturadas, sem o prefixo de timestamp do PHP. */
    function pfc_capture_log(callable $fn): array
    {
        $file = sys_get_temp_dir() . '/velk-pfc-wh-' . uniqid() . '.log';
        $prev = ini_set('error_log', $file);
        try {
            $fn();
        } finally {
            ini_set('error_log', $prev === false ? '' : $prev);
        }
        if (!is_file($file)) {
            return [];
        }
        $raw = (string) file_get_contents($file);
        @unlink($file);
        $lines = array_values(array_filter(explode("\n", $raw), fn($l) => $l !== ''));
        return array_map(fn($l) => preg_replace('/^\[[^\]]*\]\s/', '', $l, 1), $lines);
    }
}

test('dispatchWebhook: handler declarado em forms.<id>.webhook roda com (formId, values, context) intactos', function () {
    $GLOBALS['__pfc_webhook_calls'] = [];
    $submission = pfc_test_submission();
    $values = ['nome' => 'Ana', 'email' => 'ana@example.com'];
    $form = ['webhook' => 'pfc_webhook_handler'];

    pfc_dispatch_webhook('contato', $form, $submission, $values);

    check_same(1, count($GLOBALS['__pfc_webhook_calls']));
    [$formId, $gotValues, $context] = $GLOBALS['__pfc_webhook_calls'][0];
    check_same('contato', $formId);
    check_same($values, $gotValues);
    check_same([
        'submission_id' => 42,
        'ip'            => '203.0.113.9',
        'user_agent'    => 'TestAgent/1.0',
        'referer'       => 'https://example.com/contato',
        'params'        => [
            'utm_source'   => 'google',
            'utm_medium'   => 'cpc',
            'utm_campaign' => 'inverno',
            'gclid'        => 'EAIaIQobChMI123',
        ],
        'created_at'    => '2026-07-25 12:00:00',
        // v3.8.0: as quatro do preenchimento por etapas. Num formulário sem
        // `steps` — que é o caso deste teste — valem etapa 1 de 1, concluído,
        // e `data` igual ao que a submissão guarda (aqui, vazio, porque o
        // fixture não define).
        'step'          => 1,
        'total_steps'   => 1,
        'completed'     => true,
        'data'          => [],
    ], $context);
});

test('dispatchWebhook: array de handlers roda todos, na ordem declarada', function () {
    $GLOBALS['__pfc_webhook_calls'] = [];
    $submission = pfc_test_submission();
    pfc_dispatch_webhook('contato', ['webhook' => ['pfc_webhook_handler', 'pfc_webhook_handler']], $submission, []);
    check_same(2, count($GLOBALS['__pfc_webhook_calls']));
});

test('dispatchWebhook: sem webhook declarado (null/""/[]) é no-op — nenhum handler roda', function () {
    $submission = pfc_test_submission();
    foreach ([null, '', []] as $webhookValue) {
        $GLOBALS['__pfc_webhook_calls'] = [];
        pfc_dispatch_webhook('contato', ['webhook' => $webhookValue], $submission, []);
        check_same(0, count($GLOBALS['__pfc_webhook_calls']), 'webhook=' . var_export($webhookValue, true));
    }
});

test('dispatchWebhook: função inexistente é logada no formato novo, sem lançar', function () {
    $submission = pfc_test_submission();
    $lines = pfc_capture_log(function () use ($submission) {
        pfc_dispatch_webhook('contato', ['webhook' => 'pfc_funcao_que_nao_existe'], $submission, []);
    });
    check_same(1, count($lines));
    check_same(
        '[Velk webhook form=contato] fn=pfc_funcao_que_nao_existe: function not defined (check theme/functions.php)',
        $lines[0]
    );
});
