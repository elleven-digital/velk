<?php

declare(strict_types=1);

/**
 * Guarda ESTRUTURAL: quem recebe o lead inteiro e quem recebe só a etapa.
 *
 * No fechamento da etapa final, `$values` traz apenas os campos da ÚLTIMA
 * etapa. Três consumidores nascem ali e a regra não é a mesma para os três:
 *
 *   - `ClientEvents::buildUserData()` recebe `$leadValues` (o lead inteiro).
 *     O e-mail e o telefone quase sempre moram na etapa 1; com `$values` o
 *     `user_data` sai vazio e o enhanced conversions do Google para de casar
 *     sem sintoma nenhum.
 *   - `dispatchEmail()` recebe `$leadValues`. Com `$values`, o dono do site
 *     recebe um aviso de lead novo mostrando um campo só.
 *   - `dispatchWebhook()` recebe `$values`, e isso é DELIBERADO: ali "os
 *     campos que chegaram agora" é o contrato declarado, e o lead acumulado
 *     chega pelo `$context`. Uma "correção" bem-intencionada que uniformizasse
 *     os três quebraria o webhook — por isso a terceira regra está aqui.
 *
 * ─── Por que uma guarda de texto ─────────────────────────────────────────
 *
 * O `buildUserData` TEM observável honesto e está coberto por um teste de
 * comportamento em `form_step_submit_test.php` (o `user_data` da conversão
 * carregando o e-mail da etapa 1). O corpo do e-mail não tem: o `Mailer` não
 * expõe seam de transporte, então nada no processo consegue ler o que foi
 * montado. Enquanto for assim, a posição do argumento na chamada é a única
 * coisa observável — mesmo atalho de `spam_submissions_test.php`, que o
 * docblock de `findOpenByStepToken` já cita.
 *
 * Quem injetar o transporte do `Mailer` pode APAGAR este arquivo e escrever o
 * teste de verdade: a regra do e-mail passa a ter observável próprio.
 *
 * A asserção prende no ARGUMENTO da chamada, nunca em texto solto: uma guarda
 * que casasse com o comentário acima da linha sobreviveria à mutação e não
 * valeria nada.
 *
 * O scanner lê o arquivo como TEXTO, então é cego a comentário nos dois
 * sentidos: uma chamada comentada ainda conta (a guarda ficaria verde com a
 * chamada morta), e um comentário em prosa que cite `dispatchEmail(...)` com
 * a mesma forma faz a contagem dar 2 e a guarda falha. Se este arquivo ficar
 * vermelho logo depois de alguém escrever um comentário, é isto.
 *
 * ─── v3.8.0: `dispatchWebhook()` ganhou `$step`/`$totalSteps` ────────────
 *
 * A Task 8 (`step_webhook`) acrescentou dois parâmetros DEPOIS de `$values`
 * na chamada de `dispatchWebhook()` — "último argumento" parou de identificar
 * o argumento de valores só para essa chamada. `fslv_arg_at()` trava a
 * POSIÇÃO (índice 3, o 4º argumento) em vez do fim da lista, e trava também a
 * CONTAGEM de argumentos. A contagem não é zelo: `$step` e `$totalSteps`
 * vêm DEPOIS de `$values`, então removê-los não desloca o índice 3 — a
 * comparação continuaria certa e a guarda ficaria verde enquanto a chamada
 * perdeu um argumento. É a contagem que pega isso, e pega também o 7º
 * argumento acrescentado no fim, que a regra antiga do "último" pegava de
 * graça e a posição sozinha deixaria passar. Quem MEXER de propósito na
 * assinatura atualiza o número aqui — é para doer. As outras duas chamadas
 * (`dispatchEmail`, `buildUserData`) não mudaram: seguem em `fslv_last_arg()`.
 */

if (!function_exists('fslv_calls')) {
    /**
     * Os argumentos de nível superior de cada chamada que começa com `$call`.
     *
     * Consciente de parênteses: um argumento que passe a ser uma chamada
     * aninhada continua contando como UM argumento, e não como dois pedaços
     * partidos na vírgula de dentro.
     *
     * @return list<list<string>> uma lista de argumentos por chamada encontrada
     */
    function fslv_calls(string $src, string $call): array
    {
        $out = [];
        $len = strlen($src);
        $from = 0;

        while (($i = strpos($src, $call, $from)) !== false) {
            $j = $i + strlen($call);
            $depth = 1;
            $args = [];
            $buf = '';

            while ($j < $len && $depth > 0) {
                $c = $src[$j];
                if ($c === '(' || $c === '[') {
                    $depth++;
                } elseif ($c === ')' || $c === ']') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                } elseif ($c === ',' && $depth === 1) {
                    $args[] = trim($buf);
                    $buf = '';
                    $j++;
                    continue;
                }
                $buf .= $c;
                $j++;
            }

            if (trim($buf) !== '') {
                $args[] = trim($buf);
            }
            $out[] = $args;
            $from = $j + 1;
        }

        return $out;
    }
}

if (!function_exists('fslv_last_arg')) {
    /** O último argumento da chamada ÚNICA que começa com `$call`. */
    function fslv_last_arg(string $call): string
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/core/PublicFormController.php');
        $calls = fslv_calls($src, $call);

        check_same(1, count($calls), "esperava exatamente uma chamada de `{$call}...)` no controller");
        check($calls[0] !== [], "`{$call}...)` sem argumento nenhum");

        return (string) end($calls[0]);
    }
}

if (!function_exists('fslv_arg_at')) {
    /**
     * O argumento na POSIÇÃO `$index` da chamada ÚNICA que começa com
     * `$call`, com o total de argumentos travado em `$expectedCount`.
     *
     * Irmã de `fslv_last_arg()`, para quando o argumento de interesse NÃO é
     * mais o último — caso de `dispatchWebhook()` depois da v3.8.0, que
     * ganhou `$step`/`$totalSteps` depois de `$values`. A posição sozinha
     * pega inserção e reordenação ANTES do índice 3; a contagem é o que
     * cobre o resto — argumento removido ou acrescentado depois dele, que
     * não desloca nada e por isso passaria calado.
     */
    function fslv_arg_at(string $call, int $index, int $expectedCount): string
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/core/PublicFormController.php');
        $calls = fslv_calls($src, $call);

        check_same(1, count($calls), "esperava exatamente uma chamada de `{$call}...)` no controller");
        check_same(
            $expectedCount,
            count($calls[0]),
            "esperava {$expectedCount} argumentos em `{$call}...)`, veio " . count($calls[0])
        );

        return $calls[0][$index];
    }
}

test('guarda: o e-mail do envio final recebe o LEAD inteiro, não a última etapa', function () {
    check_same(
        '$leadValues',
        fslv_last_arg('$this->dispatchEmail('),
        'com $values o dono do site recebe um aviso de lead novo com um campo só'
    );
});

test('guarda: o user_data da conversão recebe o LEAD inteiro', function () {
    // Esta regra tem teste de comportamento (form_step_submit_test.php); está
    // aqui porque as três andam juntas — quem uniformizar as chamadas mexe
    // nas três de uma vez.
    check_same(
        '$leadValues',
        fslv_last_arg('ClientEvents::buildUserData('),
        'com $values o e-mail da etapa 1 some do user_data e o enhanced conversions para de casar'
    );
});

test('guarda: o webhook continua recebendo SÓ a etapa — é o contrato dele', function () {
    // Índice 3 (4º argumento) e não `fslv_last_arg()`: desde a v3.8.0 a
    // chamada tem mais dois argumentos DEPOIS de `$values` (`$step`,
    // `$totalSteps`) — ver o docblock no topo do arquivo.
    check_same(
        '$values',
        fslv_arg_at('$this->dispatchWebhook(', 3, 6),
        'o webhook declara "os campos que chegaram agora"; o lead acumulado vai pelo $context'
    );
});
