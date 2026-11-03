<?php

declare(strict_types=1);

use Velk\Models\FormSubmission;
use Velk\PublicFormController;
use Velk\Services\WebhookDispatcher;

/**
 * WebhookDispatcher — dispatch genérico de webhooks de tema, extraído da
 * metade genérica de PublicFormController::dispatchWebhook (task 1 do
 * payment-webhook; ver docs/superpowers/specs/2026-07-25-payment-webhook-design.md
 * "Dispatch semantics"). Comportamento espelha exatamente o que já existia
 * pros forms: normalização de $handlers (null/''/[] → no-op; string → um
 * handler; array → cada entrada precisa ser string não-vazia após trim),
 * function_exists() por handler, invoke posicional (`$name(...$args)`) e
 * try/catch(\Throwable) por handler — nunca lança, nunca interrompe os
 * handlers seguintes.
 */

if (!function_exists('wd_reset')) {
    function wd_reset(): void
    {
        $GLOBALS['__wd_calls'] = [];
    }
}

if (!function_exists('wd_calls')) {
    /** @return list<array{handler: string, args: array}> */
    function wd_calls(): array
    {
        return $GLOBALS['__wd_calls'] ?? [];
    }
}

if (!function_exists('wd_capture_log')) {
    /**
     * Redireciona error_log() pra um arquivo temporário enquanto $fn roda,
     * devolve as linhas capturadas SEM o prefixo de timestamp que o PHP
     * antepõe a toda linha de error_log() (ambiente-dependente — não é
     * parte do que queremos verificar aqui).
     *
     * @return list<string>
     */
    function wd_capture_log(callable $fn): array
    {
        $file = sys_get_temp_dir() . '/velk-wd-test-' . uniqid() . '.log';
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

if (!function_exists('wd_handler_ok')) {
    function wd_handler_ok(...$args): void
    {
        $GLOBALS['__wd_calls'][] = ['handler' => 'wd_handler_ok', 'args' => $args];
    }
}

if (!function_exists('wd_handler_ok2')) {
    function wd_handler_ok2(...$args): void
    {
        $GLOBALS['__wd_calls'][] = ['handler' => 'wd_handler_ok2', 'args' => $args];
    }
}

if (!function_exists('wd_handler_throws')) {
    function wd_handler_throws(...$args): void
    {
        $GLOBALS['__wd_calls'][] = ['handler' => 'wd_handler_throws', 'args' => $args];
        throw new \RuntimeException('boom');
    }
}

// --- (a) handler único, chamado uma vez, com os args exatos ---

test('dispatch: handler string único é chamado exatamente uma vez, com os args exatos (spread posicional)', function () {
    wd_reset();
    WebhookDispatcher::dispatch('wd_handler_ok', 'Test', [1, ['a' => 1], ['b' => 2]]);
    check_same(1, count(wd_calls()));
    check_same('wd_handler_ok', wd_calls()[0]['handler']);
    check_same([1, ['a' => 1], ['b' => 2]], wd_calls()[0]['args']);
});

test('dispatch: nome de handler com espaços é aparado (trim) antes de resolver — mesmo mirror do forms', function () {
    wd_reset();
    WebhookDispatcher::dispatch('  wd_handler_ok  ', 'Test', []);
    check_same(1, count(wd_calls()));
    check_same('wd_handler_ok', wd_calls()[0]['handler']);
});

// --- (b) dois handlers, ambos chamados, na ordem declarada ---

test('dispatch: array com dois handlers válidos — ambos chamados, na ordem declarada', function () {
    wd_reset();
    WebhookDispatcher::dispatch(['wd_handler_ok', 'wd_handler_ok2'], 'Test', ['x']);
    check_same(2, count(wd_calls()));
    check_same('wd_handler_ok', wd_calls()[0]['handler']);
    check_same('wd_handler_ok2', wd_calls()[1]['handler']);
    check_same(['x'], wd_calls()[0]['args']);
    check_same(['x'], wd_calls()[1]['args']);
});

// --- (c) função inexistente → logada, não lança, outros handlers seguem ---

test('dispatch: função inexistente é logada (fn=... function not defined) e pulada, sem lançar', function () {
    wd_reset();
    $lines = wd_capture_log(function () {
        WebhookDispatcher::dispatch('wd_nao_existe_xyz', 'Test', []);
    });
    check_same(0, count(wd_calls()));
    check_same(1, count($lines));
    check_same(
        '[Test] fn=wd_nao_existe_xyz: function not defined (check theme/functions.php)',
        $lines[0]
    );
});

test('dispatch: função inexistente não impede os handlers seguintes de rodar', function () {
    wd_reset();
    $lines = wd_capture_log(function () {
        WebhookDispatcher::dispatch(['wd_nao_existe_abc', 'wd_handler_ok'], 'Test', ['y']);
    });
    check_same(1, count(wd_calls()));
    check_same('wd_handler_ok', wd_calls()[0]['handler']);
    check_same(1, count($lines));
});

// --- (d) handler que lança → capturado, não lança, próximo handler roda ---

test('dispatch: handler que lança (\\Throwable) é capturado, logado com a mensagem, e não propaga', function () {
    wd_reset();
    $lines = wd_capture_log(function () {
        WebhookDispatcher::dispatch('wd_handler_throws', 'Test', []);
    });
    check_same(1, count(wd_calls()), 'o handler que lança ainda deveria ter rodado até o throw');
    check_same(1, count($lines));
    check_same('[Test] fn=wd_handler_throws: boom', $lines[0]);
});

test('dispatch: handler seguinte roda mesmo depois de um anterior lançar', function () {
    wd_reset();
    wd_capture_log(function () {
        WebhookDispatcher::dispatch(['wd_handler_throws', 'wd_handler_ok'], 'Test', []);
    });
    check_same(2, count(wd_calls()));
    check_same('wd_handler_throws', wd_calls()[0]['handler']);
    check_same('wd_handler_ok', wd_calls()[1]['handler']);
});

// --- (e) null / '' / [] → no-op, sem chamada, sem log ---

test('dispatch: null, "" e [] são no-op — nenhuma chamada, nenhum log', function () {
    foreach ([null, '', []] as $handlers) {
        wd_reset();
        $lines = wd_capture_log(function () use ($handlers) {
            WebhookDispatcher::dispatch($handlers, 'Test', []);
        });
        check_same(0, count(wd_calls()), 'handlers=' . var_export($handlers, true));
        check_same(0, count($lines), 'handlers=' . var_export($handlers, true));
    }
});

// --- (f) array com entrada não-string → pulada e logada; entradas válidas seguem rodando ---

test('dispatch: array com entradas não-string/vazias é pulado e logado; entradas válidas continuam rodando', function () {
    wd_reset();
    $lines = wd_capture_log(function () {
        WebhookDispatcher::dispatch(['wd_handler_ok', 123, null, '   ', 'wd_handler_ok2'], 'Test', []);
    });
    check_same(2, count(wd_calls()));
    check_same('wd_handler_ok', wd_calls()[0]['handler']);
    check_same('wd_handler_ok2', wd_calls()[1]['handler']);

    // 3 entradas inválidas: int, null, string em branco (vira '' após trim).
    check_same(3, count($lines));
    foreach ($lines as $line) {
        check(str_contains($line, 'empty handler name'), 'linha deveria mencionar "empty handler name": ' . $line);
    }
});

test('dispatch: nunca lança — mesmo com combinação de handler inexistente, handler que lança, e entrada inválida', function () {
    wd_reset();
    $threw = false;
    wd_capture_log(function () use (&$threw) {
        try {
            WebhookDispatcher::dispatch(['nao_existe', 'wd_handler_throws', 42, 'wd_handler_ok'], 'Test', []);
        } catch (\Throwable $e) {
            $threw = true;
        }
    });
    check(!$threw, 'dispatch() nunca deveria deixar uma exceção escapar');
    check_same(2, count(wd_calls()));
    check_same('wd_handler_throws', wd_calls()[0]['handler']);
    check_same('wd_handler_ok', wd_calls()[1]['handler']);
});

// --- (g) $handlers de tipo inválido (nem string, nem array, nem null) →
// logado e tratado como no-op, NUNCA TypeError. Regressão do bug real: um
// autor de site.json escreve `"webhook": 42` numa forma numérica e, antes
// desse fix, o parâmetro tipado `string|array|null` do dispatcher (sob
// strict_types=1) lançava TypeError — que propagava e virava 500 pro
// visitante DEPOIS que o submit já tinha sido salvo+emailado.

test('dispatch: $handlers=42 (int) não lança, não chama handler, e loga o valor inválido', function () {
    wd_reset();
    $threw = false;
    $lines = wd_capture_log(function () use (&$threw) {
        try {
            WebhookDispatcher::dispatch(42, 'Test', []);
        } catch (\Throwable $e) {
            $threw = true;
        }
    });
    check(!$threw, 'dispatch(42, ...) nunca deveria lançar (TypeError ou qualquer outro)');
    check_same(0, count(wd_calls()));
    check_same(1, count($lines));
    check_same('[Test] invalid webhook value in site.json (expected string or list of strings)', $lines[0]);
});

test('dispatch: $handlers=true (bool) não lança, não chama handler, e loga o valor inválido', function () {
    wd_reset();
    $threw = false;
    $lines = wd_capture_log(function () use (&$threw) {
        try {
            WebhookDispatcher::dispatch(true, 'Test', []);
        } catch (\Throwable $e) {
            $threw = true;
        }
    });
    check(!$threw, 'dispatch(true, ...) nunca deveria lançar');
    check_same(0, count(wd_calls()));
    check_same(1, count($lines));
    check_same('[Test] invalid webhook value in site.json (expected string or list of strings)', $lines[0]);
});

test('dispatch: $handlers=3.14 (float) não lança, não chama handler, e loga o valor inválido', function () {
    wd_reset();
    $threw = false;
    $lines = wd_capture_log(function () use (&$threw) {
        try {
            WebhookDispatcher::dispatch(3.14, 'Test', []);
        } catch (\Throwable $e) {
            $threw = true;
        }
    });
    check(!$threw, 'dispatch(3.14, ...) nunca deveria lançar');
    check_same(0, count(wd_calls()));
    check_same(1, count($lines));
    check_same('[Test] invalid webhook value in site.json (expected string or list of strings)', $lines[0]);
});

test('dispatch: $handlers=false (bool) continua tratado como falsy — sem log, sem chamada (mesmo caminho de null/\'\'/[])', function () {
    // false não é null/''/[] textualmente, mas cai no mesmo === check? Não —
    // false !== null/''/[], então passa pro branch de tipo inválido. Cobre
    // o caso pra deixar explícito que bool false também é logado e nunca
    // vira TypeError (mesma garantia do caso true acima).
    wd_reset();
    $threw = false;
    $lines = wd_capture_log(function () use (&$threw) {
        try {
            WebhookDispatcher::dispatch(false, 'Test', []);
        } catch (\Throwable $e) {
            $threw = true;
        }
    });
    check(!$threw, 'dispatch(false, ...) nunca deveria lançar');
    check_same(0, count(wd_calls()));
    check_same(1, count($lines));
});

// --- (h) integração: PublicFormController::dispatchWebhook com
// $form['webhook'] = 42 — o call site real que expôs o bug (lê o valor CRU
// de site.json, sem normalização prévia pelo Config). Alcançado via
// Reflection por ser método privado de instância (mesmo padrão de
// tests/unit/public_form_select_validation_test.php).

if (!function_exists('wd_make_submission')) {
    function wd_make_submission(): FormSubmission
    {
        $s = new FormSubmission();
        $s->id = 1;
        $s->formId = 'contato';
        $s->createdAt = '2026-07-25 12:00:00';
        return $s;
    }
}

test('PublicFormController::dispatchWebhook com webhook=42 no site.json não lança (TypeError) — só loga e retorna', function () {
    wd_reset();
    $method = new ReflectionMethod(PublicFormController::class, 'dispatchWebhook');
    $threw = false;
    $lines = wd_capture_log(function () use ($method, &$threw) {
        try {
            $method->invoke(
                new PublicFormController(),
                'contato',
                ['webhook' => 42],
                wd_make_submission(),
                []
            );
        } catch (\Throwable $e) {
            $threw = true;
        }
    });
    check(!$threw, 'dispatchWebhook() com webhook=42 nunca deveria lançar TypeError');
    check_same(0, count(wd_calls()));
    check_same(1, count($lines));
    check(
        str_contains($lines[0], 'invalid webhook value in site.json'),
        'linha deveria mencionar o valor inválido: ' . $lines[0]
    );
});
