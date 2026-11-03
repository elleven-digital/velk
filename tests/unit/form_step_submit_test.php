<?php

declare(strict_types=1);

use Velk\App;
use Velk\PublicFormController;
use Velk\Request;
use Velk\Session;

/**
 * `submit()` ponta a ponta com etapas (spec 2026-09-09 §4 e §5).
 *
 * Controller real + SQLite: é o único jeito de provar o que importa aqui, que
 * é ESTADO — qual linha nasceu, o que ela guarda, onde o ponteiro parou. Um
 * teste que afirmasse sobre o texto do método sobreviveria a qualquer inversão
 * de regra (foi o que aconteceu na Task 5 da ordem manual, e os 5 testes
 * tiveram que ser refeitos).
 *
 * Tudo em AJAX porque etapa intermediária EXIGE AJAX (decisão 12) — e há um
 * caso provando que sem `Accept: application/json` ela é recusada.
 */

require_once dirname(__DIR__) . '/fixtures/form_step_boot.php';

// ─── Etapa 1: nasce o lead ───────────────────────────────────────────────

test('etapa 1: cria o lead ABERTO, com token, params e sem e-mail', function () {
    $app = fst_boot(fst_steps(), fst_fields());
    $app->session->put('velk_params', ['utm_source' => 'google']);

    $r = fst_post($app, ['_step' => 1, 'nome' => 'Ana', 'email' => 'a@x.dev']);

    check_same(200, $r['status']);
    check_same('step_saved', $r['body']['code']);
    check_same(1, $r['body']['step']);
    check_same(2, $r['body']['next_step']);
    check_same(3, $r['body']['total_steps']);
    check_same(64, strlen((string) $r['body']['token']), 'o tema precisa do token para a etapa 2');

    $n = (int) $app->db->fetchColumn('SELECT COUNT(*) FROM form_submissions');
    check_same(1, $n);
    $row = (array) $app->db->fetch('SELECT * FROM form_submissions LIMIT 1');
    check_same('2', (string) $row['pending_step'], 'o ponteiro tem que estar na etapa 2');
    check_same($r['body']['token'], $row['step_token']);
    check_same(null, $row['email_status'], 'e-mail só no envio final');
    check_same(['nome' => 'Ana', 'email' => 'a@x.dev'], (array) json_decode($row['data'], true));
    check_same(['utm_source' => 'google'], (array) json_decode((string) $row['params'], true), 'atribuição vai na criação');
});

test('etapa 1: obrigatório de etapa FUTURA não reprova a atual', function () {
    // `cep` é obrigatório e está na etapa 2 — pedi-lo aqui inviabilizaria o
    // recurso inteiro.
    $app = fst_boot(fst_steps(), fst_fields());
    $r = fst_post($app, ['_step' => 1, 'nome' => 'Ana', 'email' => 'a@x.dev']);
    check_same('step_saved', $r['body']['code']);
});

test('etapa 1: obrigatório DA etapa em branco reprova', function () {
    $app = fst_boot(fst_steps(), fst_fields());
    $r = fst_post($app, ['_step' => 1, 'nome' => '', 'email' => 'a@x.dev']);
    check_same(422, $r['status']);
    check_same('validation_failed', $r['body']['code']);
    check_same('required', $r['body']['errors']['nome']['code']);
    check_same(0, (int) $app->db->fetchColumn('SELECT COUNT(*) FROM form_submissions'), 'nada gravado');
});

// ─── Etapa 2: mescla ─────────────────────────────────────────────────────

test('etapa 2: mescla no MESMO lead e avança o ponteiro', function () {
    $app = fst_boot(fst_steps(), fst_fields());
    $t = fst_post($app, ['_step' => 1, 'nome' => 'Ana', 'email' => 'a@x.dev'])['body']['token'];

    $r = fst_post($app, ['_step' => 2, '_step_token' => $t, 'cep' => '01001-000']);

    check_same('step_saved', $r['body']['code']);
    check_same(3, $r['body']['next_step']);
    check_same(1, (int) $app->db->fetchColumn('SELECT COUNT(*) FROM form_submissions'), 'nunca uma linha nova');

    $row = (array) $app->db->fetch('SELECT * FROM form_submissions LIMIT 1');
    check_same('3', (string) $row['pending_step']);
    check_same(
        ['nome' => 'Ana', 'email' => 'a@x.dev', 'cep' => '01001-000'],
        (array) json_decode($row['data'], true),
        'a etapa 1 não pode ser perdida'
    );
});

// ─── Etapa final: fecha ──────────────────────────────────────────────────

test('etapa final: fecha o lead, zera as duas colunas, marca e-mail e devolve submitted', function () {
    $app = fst_boot(fst_steps(), fst_fields());
    $t = fst_post($app, ['_step' => 1, 'nome' => 'Ana', 'email' => 'a@x.dev'])['body']['token'];
    fst_post($app, ['_step' => 2, '_step_token' => $t, 'cep' => '01001-000']);

    $r = fst_post($app, ['_step' => 3, '_step_token' => $t, 'budget' => '10k']);

    check_same(200, $r['status']);
    check_same('submitted', $r['body']['code'], 'o envio final não muda de código');
    check_same('Recebido.', $r['body']['message'], 'a message do site.json continua vindo');
    check(isset($r['body']['event']), 'a conversão tem que vir no corpo no modo AJAX');
    // array_key_exists, não isset: o token morre virando NULL, então um
    // payload final que passasse a carregar `'token' => $submission->stepToken`
    // escaparia do isset() e o tema guardaria um token morto. A regra de
    // verdade é a coluna zerada, checada logo abaixo.
    check(
        !array_key_exists('token', $r['body']) && !array_key_exists('next_step', $r['body']),
        'o encanamento de etapa não volta no envio final, nem como chave nula'
    );

    $row = (array) $app->db->fetch('SELECT * FROM form_submissions LIMIT 1');
    check_same(null, $row['pending_step'], 'NULL = completo');
    check_same(null, $row['step_token'], 'o token morre com o lead');
    check_same('skipped', $row['email_status'], 'sem destinatário, mas o e-mail RODOU nesta etapa');
    check_same('10k', ((array) json_decode($row['data'], true))['budget']);
});

// ─── Lead inteiro no envio final (e-mail e conversão) ────────────────────

test('etapa final: user_data da conversão carrega e-mail de uma etapa anterior, não só a última', function () {
    // `email` mora na etapa 1; o envio que fecha o lead é o da etapa 3, que só
    // traz `budget`. Sem a correção, buildUserData() recebe só esse `$values`
    // da última etapa, `email` nunca é encontrado, e `user_data` sai vazio —
    // o enhanced conversions do Google para de casar, sem nenhum erro visível
    // pra ninguém notar.
    $app = fst_boot(fst_steps(), fst_fields());
    $app->db->insert('form_settings', [
        'form_id'          => 'orcamento',
        'user_data_fields' => json_encode(['email' => 'email']),
    ]);

    $t = fst_post($app, ['_step' => 1, 'nome' => 'Ana', 'email' => 'a@x.dev'])['body']['token'];
    fst_post($app, ['_step' => 2, '_step_token' => $t, 'cep' => '01001-000']);
    $r = fst_post($app, ['_step' => 3, '_step_token' => $t, 'budget' => '10k']);

    check_same(
        'a@x.dev',
        $r['body']['event']['data']['user_data']['email'] ?? null,
        'o e-mail submetido na etapa 1 tem que chegar no user_data da conversão final'
    );
});

// ─── Reedita ─────────────────────────────────────────────────────────────

test('reenvio de etapa anterior sobrescreve e devolve o visitante para onde estava', function () {
    $app = fst_boot(fst_steps(), fst_fields());
    $t = fst_post($app, ['_step' => 1, 'nome' => 'Ana', 'email' => 'errado@x.dev'])['body']['token'];
    fst_post($app, ['_step' => 2, '_step_token' => $t, 'cep' => '01001-000']);

    $r = fst_post($app, ['_step' => 1, '_step_token' => $t, 'nome' => 'Ana', 'email' => 'certo@x.dev']);

    check_same('step_saved', $r['body']['code']);
    check_same(3, $r['body']['next_step'], 'volta para a etapa em que estava');
    $row = (array) $app->db->fetch('SELECT * FROM form_submissions LIMIT 1');
    check_same('3', (string) $row['pending_step'], 'o ponteiro não anda para trás');
    $data = (array) json_decode($row['data'], true);
    check_same('certo@x.dev', $data['email']);
    check_same('01001-000', $data['cep'], 'a etapa 2 não pode ser perdida');
});

// ─── Recusas ─────────────────────────────────────────────────────────────

test('pular etapa é recusado — a guarda contra POST forjado', function () {
    $app = fst_boot(fst_steps(), fst_fields());
    $t = fst_post($app, ['_step' => 1, 'nome' => 'Ana', 'email' => 'a@x.dev'])['body']['token'];

    $r = fst_post($app, ['_step' => 3, '_step_token' => $t, 'budget' => '10k']);

    check_same(422, $r['status']);
    check_same('invalid_step', $r['body']['code']);
    $row = (array) $app->db->fetch('SELECT * FROM form_submissions LIMIT 1');
    check_same('2', (string) $row['pending_step'], 'o lead não pode ter avançado');
});

test('token de OUTRA sessão não mescla, mesmo com o lead aberto', function () {
    // O caso que o revisor executou. A sessão B posta com o CSRF DELA, então
    // a requisição chega inteira até a resolução de etapa — só a lista de
    // tokens da sessão a barra.
    $app = fst_boot(fst_steps(), fst_fields());
    $t = fst_post($app, ['_step' => 1, 'nome' => 'Ana', 'email' => 'a@x.dev'])['body']['token'];

    // Sessão B: mesmo banco, sessão nova — é exatamente o que outro visitante é.
    $_SESSION = [];
    $app->session = new Session();

    $r = fst_post($app, ['_step' => 2, '_step_token' => $t, 'cep' => 'injetado']);

    check_same('invalid_token', $r['body']['code'], 'o token da sessão A não vale na sessão B');
    $row = fst_row(1);
    check_same('2', (string) $row['pending_step'], 'o lead da vítima não pode ter andado');
    check_same($t, (string) $row['step_token'], 'nem pode ter perdido o token');
    check_same(
        ['nome' => 'Ana', 'email' => 'a@x.dev'],
        (array) json_decode((string) $row['data'], true),
        'nada da sessão B entra no lead'
    );
});

test('token de OUTRA sessão não FECHA o lead — nem e-mail nem conversão disparam', function () {
    // A ponta cara do mesmo furo: fechando o lead alheio, o e-mail e o evento
    // de conversão do dono do site sairiam com o conteúdo injetado, e o token
    // morreria — a visitante de verdade voltaria para um `invalid_token`.
    $app = fst_boot(fst_steps(), fst_fields());
    $t = fst_post($app, ['_step' => 1, 'nome' => 'Ana', 'email' => 'a@x.dev'])['body']['token'];
    fst_post($app, ['_step' => 2, '_step_token' => $t, 'cep' => '01001-000']);

    $_SESSION = [];
    $app->session = new Session();

    $r = fst_post($app, ['_step' => 3, '_step_token' => $t, 'budget' => 'injetado']);

    check_same('invalid_token', $r['body']['code']);
    check(!isset($r['body']['event']), 'nenhuma conversão pode ter sido montada');
    $row = fst_row(1);
    check_same('3', (string) $row['pending_step'], 'o lead continua aberto na etapa final');
    check_same(null, $row['email_status'], 'o e-mail não pode ter rodado');
    check(
        !array_key_exists('budget', (array) json_decode((string) $row['data'], true)),
        'o campo injetado não pode ter entrado no lead'
    );
});

test('token desconhecido é invalid_token', function () {
    $app = fst_boot(fst_steps(), fst_fields());
    fst_post($app, ['_step' => 1, 'nome' => 'Ana', 'email' => 'a@x.dev']);

    $r = fst_post($app, ['_step' => 2, '_step_token' => str_repeat('f', 64), 'cep' => '01001-000']);
    check_same('invalid_token', $r['body']['code']);
});

test('lead já concluído recusa reenvio com form_completed', function () {
    $app = fst_boot(fst_steps(), fst_fields());
    $t = fst_post($app, ['_step' => 1, 'nome' => 'Ana', 'email' => 'a@x.dev'])['body']['token'];
    fst_post($app, ['_step' => 2, '_step_token' => $t, 'cep' => '01001-000']);
    fst_post($app, ['_step' => 3, '_step_token' => $t, 'budget' => '10k']);

    $r = fst_post($app, ['_step' => 3, '_step_token' => $t, 'budget' => '20k']);

    check_same('form_completed', $r['body']['code'], 'a sessão emitiu o token, então sabemos que ele FECHOU');
    $data = (array) json_decode((string) fst_row(1)['data'], true);
    check_same('10k', $data['budget'], 'o dado que o time já recebeu não pode ser alterado');
});

test('etapa intermediária sem Accept: application/json é recusada com ajax_required', function () {
    // Em POST nativo o tema não tem como receber o token da etapa seguinte:
    // continuar em silêncio deixaria o visitante preso.
    $app = fst_boot(fst_steps(), fst_fields());
    $res = (new PublicFormController())->submit(
        new Request('POST', '/forms/orcamento', [], [
            '_csrf' => $app->session->csrfToken(), '_step' => 1, 'nome' => 'Ana', 'email' => 'a@x.dev',
        ], [], ['REQUEST_METHOD' => 'POST', 'HTTP_HOST' => 'site.com', 'HTTP_REFERER' => 'https://site.com/orcamento'], []),
        ['id' => 'orcamento']
    );
    check_same(302, $res->getStatus(), 'modo redirect responde 302');
    check_same('ajax_required', $_SESSION['_form_status_orcamento']['code'] ?? null);
    check_same(0, (int) $app->db->fetchColumn('SELECT COUNT(*) FROM form_submissions'), 'nada gravado');
});

// ─── A INVARIANTE ────────────────────────────────────────────────────────

test('INVARIANTE: formulário SEM steps grava linha indistinguível da de hoje', function () {
    $app = fst_boot([], fst_fields());
    $r = fst_post($app, ['nome' => 'Ana', 'email' => 'a@x.dev', 'cep' => '01001-000', 'budget' => '10k']);

    check_same('submitted', $r['body']['code']);
    $row = (array) $app->db->fetch('SELECT * FROM form_submissions LIMIT 1');
    check_same(null, $row['pending_step'], 'as duas colunas ficam NULL');
    check_same(null, $row['step_token']);
    check_same('skipped', $row['email_status'], 'e-mail rodou, como sempre rodou');
});

test('INVARIANTE: formulário sem steps ignora _step e _step_token forjados', function () {
    $app = fst_boot([], fst_fields());
    $r = fst_post($app, [
        '_step' => 3, '_step_token' => str_repeat('a', 64),
        'nome' => 'Ana', 'email' => 'a@x.dev', 'cep' => '01001-000',
    ]);
    check_same('submitted', $r['body']['code'], 'sem steps, o encanamento de etapa não existe');
    check_same(null, ((array) $app->db->fetch('SELECT * FROM form_submissions LIMIT 1'))['pending_step']);
});

test('formulário de UMA etapa cria e fecha na mesma requisição', function () {
    $app = fst_boot([['nome', 'email', 'cep']], [
        ['name' => 'nome', 'type' => 'text', 'required' => true],
        ['name' => 'email', 'type' => 'email', 'required' => true],
        ['name' => 'cep', 'type' => 'text', 'required' => true],
    ]);
    $r = fst_post($app, ['_step' => 1, 'nome' => 'Ana', 'email' => 'a@x.dev', 'cep' => '01001-000']);

    check_same('submitted', $r['body']['code']);
    $row = (array) $app->db->fetch('SELECT * FROM form_submissions LIMIT 1');
    check_same(null, $row['pending_step']);
    check_same(null, $row['step_token']);
});

test('`_step` esquecido no POST não reescreve a etapa 1 do lead', function () {
    // Achado 5 da revisão final, ponta a ponta. O tema manda o `_step_token`
    // e esquece o `_step` — o erro que a própria documentação convidava,
    // porque só o token aparecia numa linha de código de verdade. Com o
    // `?? 1`, isto resolvia `merge` na etapa 1 e o `mergeStep` gravava string
    // vazia por cima do nome e do e-mail que a visitante já tinha dado.
    $app = fst_boot(fst_steps(), fst_fields());
    $t = fst_post($app, ['_step' => 1, 'nome' => 'Ana', 'email' => 'ana@x.dev'])['body']['token'];

    $r = fst_post($app, ['_step_token' => $t, 'cep' => '01001-000']);

    check_same('error', $r['body']['status']);
    check_same('invalid_step', $r['body']['code'], 'dizer o que faltou, em vez de adivinhar a etapa');

    $data = json_decode(fst_row(1)['data'], true);
    check_same('Ana', $data['nome'], 'a etapa 1 da visitante não pode ter sido sobrescrita');
    check_same('ana@x.dev', $data['email']);
    check_same('2', (string) fst_row(1)['pending_step'], 'e o lead continua onde estava');
});

test('`_step` esquecido: com etapa 1 obrigatória, o erro não culpa a visitante', function () {
    // A outra metade do achado. Quando a etapa 1 tem obrigatório, o desfecho
    // antigo era `validation_failed` citando `nome`/`email` — campos que ela
    // preencheu e que estão gravados. Reclamar do que a pessoa já fez é pior
    // do que dizer que faltou o `_step`.
    $app = fst_boot(fst_steps(), fst_fields());
    $t = fst_post($app, ['_step' => 1, 'nome' => 'Ana', 'email' => 'ana@x.dev'])['body']['token'];

    $r = fst_post($app, ['_step_token' => $t]);

    check_same('invalid_step', $r['body']['code']);
    check(!isset($r['body']['errors']), 'não pode vir lista de campos reprovados — nenhum campo reprovou');
});
