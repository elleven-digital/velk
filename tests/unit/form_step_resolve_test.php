<?php

declare(strict_types=1);

use Velk\Models\FormSubmission;

/**
 * `resolveStep()` (spec 2026-09-09 §4): a ÚNICA definição de "o que fazer
 * com esta etapa". Pura, então a tabela inteira de regras é testável sem
 * banco, sessão nem controller.
 *
 * Três regras carregam segurança e não conveniência:
 *
 *   - token que ESTA sessão não emitiu é recusado em TODOS os ramos, não só
 *     quando o lead não é encontrado. O token diz qual lead é; a sessão prova
 *     ser a mesma visitante. Sem isso, um token vazado completava o lead
 *     alheio — e-mail e conversão do dono do site saíam com o que o invasor
 *     injetou, e a visitante de verdade voltava para um `invalid_token`.
 *   - `_step` maior que o pendente é RECUSADO. É o que fecha o pulo de etapa
 *     por POST forjado: sem isso, alguém posta direto na etapa final e
 *     completa um lead sem passar pela validação das intermediárias.
 *   - token DESTA sessão que não resolve para lead aberto é `form_completed`,
 *     não `invalid_token`. O token morre no envio final, então um lead
 *     concluído não tem token para achar — sem a lista da sessão, o visitante
 *     que reenviasse receberia "token inválido", que é mentira sobre o que
 *     aconteceu.
 */

if (!function_exists('fsr_resolve')) {
    function fsr_resolve(
        int $total,
        ?int $step,
        bool $hasToken = false,
        ?int $pending = null,
        bool $ours = false
    ): array {
        return FormSubmission::resolveStep($total, $step, $hasToken, $pending, $ours);
    }
}

if (!function_exists('fsr_code')) {
    /** O código de recusa, ou null quando não foi recusa. */
    function fsr_code(array $r): ?string
    {
        return ($r['action'] ?? '') === 'reject' ? ($r['code'] ?? '') : null;
    }
}

// ─── Etapa 1: nasce o lead ───────────────────────────────────────────────

test('resolveStep: sem token e sem _step, cria a etapa 1', function () {
    $r = fsr_resolve(3, null);
    check_same('create', $r['action']);
    check_same(1, $r['step']);
    check_same(false, $r['completes']);
    check_same(2, $r['next_step']);
});

test('resolveStep: sem token com _step=1 é o mesmo que sem _step', function () {
    check_same(fsr_resolve(3, null), fsr_resolve(3, 1));
});

test('resolveStep: sem token e _step > 1 é recusado — não há lead para continuar', function () {
    check_same('invalid_step', fsr_code(fsr_resolve(3, 2)));
    check_same('invalid_step', fsr_code(fsr_resolve(3, 3)));
});

test('resolveStep: formulário de UMA etapa cria e fecha na mesma requisição', function () {
    $r = fsr_resolve(1, null);
    check_same('create', $r['action']);
    check_same(true, $r['completes']);
    check_same(null, $r['next_step']);
});

// ─── Fora do intervalo ───────────────────────────────────────────────────

test('resolveStep: _step fora do intervalo declarado é recusado, com ou sem token', function () {
    check_same('invalid_step', fsr_code(fsr_resolve(3, 0)), 'etapa é 1-based: zero não existe');
    check_same('invalid_step', fsr_code(fsr_resolve(3, 4)));
    check_same('invalid_step', fsr_code(fsr_resolve(3, -1)));
    check_same(
        'invalid_step',
        fsr_code(fsr_resolve(3, 4, true, 2)),
        'a faixa é checada ANTES da sessão: token de fora não vira invalid_token aqui'
    );
    check_same('invalid_step', fsr_code(fsr_resolve(3, 4, true, 2, true)), 'nem com token desta sessão');
});

// ─── Avança ──────────────────────────────────────────────────────────────

test('resolveStep: etapa igual à pendente avança o ponteiro', function () {
    $r = fsr_resolve(3, 2, true, 2, true);
    check_same('merge', $r['action']);
    check_same(2, $r['step']);
    check_same(false, $r['completes']);
    check_same(3, $r['next_step']);
});

test('resolveStep: a última etapa FECHA o lead', function () {
    $r = fsr_resolve(3, 3, true, 3, true);
    check_same('merge', $r['action']);
    check_same(true, $r['completes']);
    check_same(null, $r['next_step']);
});

// ─── Reedita ─────────────────────────────────────────────────────────────

test('resolveStep: reenvio de etapa anterior mescla e NÃO move o ponteiro', function () {
    // O visitante voltou para a 1 estando na 3. Sobrescreve aqueles campos e
    // volta para onde estava.
    $r = fsr_resolve(3, 1, true, 3, true);
    check_same('merge', $r['action']);
    check_same(1, $r['step']);
    check_same(false, $r['completes'], 'reeditar a 1 não pode fechar um lead de 3 etapas');
    check_same(3, $r['next_step'], 'devolve o visitante para a etapa em que estava');
});

// ─── Pular é recusado ────────────────────────────────────────────────────

test('resolveStep: etapa ALÉM da pendente é recusada — é o pulo de etapa', function () {
    check_same('invalid_step', fsr_code(fsr_resolve(3, 3, true, 2, true)), 'não se salta da 2 para a 3');
    check_same('invalid_step', fsr_code(fsr_resolve(3, 2, true, 1, true)));
});

// ─── Token que não resolve ───────────────────────────────────────────────

test('resolveStep: token de OUTRA sessão é recusado mesmo abrindo um lead', function () {
    // O caso que o revisor executou: a sessão B posta `_step_token` da sessão
    // A e, sem esta regra, recebia `merge` — seguia até o fim, disparava o
    // e-mail e a conversão com o lead da vítima mais o que injetou, e matava
    // o token, deixando a visitante real com `invalid_token` ao voltar.
    check_same('invalid_token', fsr_code(fsr_resolve(3, 2, true, 2, false)), 'etapa igual à pendente');
    check_same('invalid_token', fsr_code(fsr_resolve(3, 1, true, 3, false)), 'reedição de etapa anterior');
    check_same('invalid_token', fsr_code(fsr_resolve(3, 3, true, 3, false)), 'a etapa que FECHARIA o lead');
});

test('resolveStep: token desta sessão que não abre lead é form_completed', function () {
    check_same('form_completed', fsr_code(fsr_resolve(3, 2, true, null, true)));
});

test('resolveStep: token que esta sessão não emitiu é invalid_token', function () {
    check_same('invalid_token', fsr_code(fsr_resolve(3, 2, true, null, false)));
});

// ─── `_step` ausente ─────────────────────────────────────────────────────

test('resolveStep: COM token, `_step` ausente é invalid_step — nunca a etapa 1', function () {
    // Achado 5 da revisão final. O `$step = $requestedStep ?? 1` tratava a
    // ausência como etapa 1: com lead em `pending_step = 2`, um POST sem
    // `_step` resolvia `merge` na etapa 1, e o engine validava os campos da
    // etapa 1 contra um corpo que traz os da 2. Obrigatório na 1 =
    // validation_failed citando campos que a visitante já preencheu; etapa 1
    // toda opcional = string vazia gravada por cima das respostas dela.
    check_same('invalid_step', fsr_code(fsr_resolve(3, null, true, 2, true)), 'lead na etapa 2');
    check_same('invalid_step', fsr_code(fsr_resolve(3, null, true, 1, true)), 'e também com o lead na 1');
    check_same('invalid_step', fsr_code(fsr_resolve(3, null, true, 3, true)), 'e na final');
});

test('resolveStep: SEM token, `_step` ausente continua sendo a etapa 1', function () {
    // O default `?? 1` está certo onde ele nasceu: sem token não há lead, e
    // só a primeira etapa pode nascer. Sem esta asserção, a correção do caso
    // com token poderia ter levado junto o formulário de uma etapa só, que
    // não manda `_step` nenhum.
    $r = fsr_resolve(3, null);
    check_same('create', $r['action']);
    check_same(1, $r['step']);
});

test('resolveStep: token alheio sem `_step` continua respondendo invalid_token', function () {
    // A ordem das guardas importa: a tranca de sessão vem primeiro, e um
    // token que não é desta sessão não ganha um segundo código capaz de
    // distinguir alguma coisa sobre ele.
    check_same('invalid_token', fsr_code(fsr_resolve(3, null, true, 2, false)));
});

// ─── missingRequired ─────────────────────────────────────────────────────

if (!function_exists('fsr_fields')) {
    function fsr_fields(): array
    {
        return [
            ['name' => 'nome', 'type' => 'text', 'required' => true],
            ['name' => 'email', 'type' => 'email', 'required' => true],
            ['name' => 'msg', 'type' => 'textarea'],
            ['name' => 'anexo', 'type' => 'file', 'required' => true],
            ['name' => 'aceite', 'type' => 'checkbox', 'required' => true],
        ];
    }
}

test('missingRequired: lead completo não tem faltas', function () {
    check_same([], FormSubmission::missingRequired(fsr_fields(), [
        'nome' => 'Ana', 'email' => 'a@x.dev', 'aceite' => '1',
        'anexo' => ['_file' => true, 'path' => '2026/09/x.pdf'],
    ]));
});

test('missingRequired: devolve os obrigatórios ausentes ou vazios, na ordem de declaração', function () {
    check_same(['nome', 'email', 'anexo', 'aceite'], FormSubmission::missingRequired(fsr_fields(), []));
    check_same(['email'], FormSubmission::missingRequired(fsr_fields(), [
        'nome' => 'Ana', 'email' => '   ', 'aceite' => '1',
        'anexo' => ['_file' => true, 'path' => 'x'],
    ]), 'só espaço é vazio');
});

test('missingRequired: campo opcional em branco nunca falta', function () {
    check_same([], FormSubmission::missingRequired(
        [['name' => 'msg', 'type' => 'textarea']],
        []
    ));
});

test('missingRequired: campo file conta como preenchido pelo marcador _file', function () {
    $fields = [['name' => 'anexo', 'type' => 'file', 'required' => true]];
    check_same([], FormSubmission::missingRequired($fields, ['anexo' => ['_file' => true, 'path' => 'x']]));
    check_same(['anexo'], FormSubmission::missingRequired($fields, ['anexo' => ['path' => 'x']]), 'array sem _file não é upload');
});

// ─── newStepToken ────────────────────────────────────────────────────────

test('newStepToken: 64 hex, e não repete', function () {
    $a = FormSubmission::newStepToken();
    check_same(64, strlen($a), 'a coluna é VARCHAR(64)');
    check_same(1, preg_match('/^[0-9a-f]{64}$/D', $a), "esperava hex puro, veio: {$a}");
    check_same(false, $a === FormSubmission::newStepToken());
});
