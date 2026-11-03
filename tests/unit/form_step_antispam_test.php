<?php

declare(strict_types=1);

use Velk\App;
use Velk\Models\FormSubmission;
use Velk\PublicFormController;
use Velk\Request;

/**
 * Antispam com etapas (spec 2026-09-09, decisão 3).
 *
 * Duas coisas, e as duas são sobre não destruir dado bom.
 *
 * PRIMEIRA — honeypot e Turnstile só enquanto A LINHA NÃO EXISTE, ou seja,
 * quando o plano da etapa é `create`. Sem linha, pegar um bot é descartar,
 * como sempre foi. Existindo a linha, ela pode ser um lead REAL: o honeypot
 * tem falso positivo conhecido e documentado (autofill do Chrome, gerenciador
 * de senha), e a única escolha ali seria marcar como spam um lead que já era
 * válido ou descartar a atualização em silêncio. Um bot que chegou à etapa 2
 * já passou pelos dois filtros e segura um token inadivinhável — tudo que
 * consegue é sujar o próprio lead.
 *
 * "A linha não existe" NÃO é o mesmo que "o número da etapa é 1", e é aí que
 * esta regra já esteve errada: a navegação para trás resolve como `merge` com
 * `step` 1 contra um lead gravado. Os cinco primeiros testes daqui passavam
 * sob os DOIS predicados; os da seção "volta para a etapa 1", no fim do
 * arquivo, são os que separam um do outro.
 *
 * SEGUNDA — o sucesso falso precisa ter a MESMA FORMA da resposta verdadeira.
 * O `successPayload()` existe só para isso, e o comentário dele diz que
 * qualquer diferença entrega a armadilha a quem comparar as duas respostas.
 * Num formulário com etapas a verdadeira da etapa 1 é `step_saved` com token e
 * `next_step`; um `submitted` sem token entregaria tudo de graça.
 *
 * O que se perde, conscientemente: o token falso não resolve, então a etapa 2
 * do bot recebe `invalid_token`. Igualar as duas exigiria fazer token
 * desconhecido suceder falsamente — e aí um visitante real com token vencido
 * receberia "salvo!" e perderia os dados em silêncio. Preço alto demais.
 *
 * Usa o boot compartilhado de `tests/fixtures/form_step_boot.php`. Por
 * `require_once` e não por ordem do glob: este arquivo carrega ANTES de
 * `form_step_submit_test.php` no alfabeto, então depender da ordem seria
 * "call to undefined function".
 */

require_once dirname(__DIR__) . '/fixtures/form_step_boot.php';

if (!function_exists('fsa_boot')) {
    /** Três campos fixos; as etapas variam por caso. */
    function fsa_boot(array $steps): App
    {
        return fst_boot($steps, [
            ['name' => 'nome', 'type' => 'text', 'required' => true],
            ['name' => 'email', 'type' => 'email', 'required' => true],
            ['name' => 'cep', 'type' => 'text', 'required' => true],
        ]);
    }
}

if (!function_exists('fsa_post')) {
    /** @return array{status:int, body:array} */
    function fsa_post(App $app, array $post, bool $json = true): array
    {
        return fst_post($app, $post, $json);
    }
}

test('honeypot na etapa 1: sucesso FALSO com a mesma forma da verdadeira', function () {
    $app = fsa_boot([['nome', 'email'], ['cep']]);
    $r = fsa_post($app, ['_step' => 1, '_hp' => 'bot', 'nome' => 'Bot', 'email' => 'b@x.dev']);

    check_same(200, $r['status'], 'um 422 aqui entregaria a armadilha');
    check_same('step_saved', $r['body']['code'], 'submitted revelaria que não avançou');
    check_same(1, $r['body']['step']);
    check_same(2, $r['body']['next_step']);
    check_same(2, $r['body']['total_steps']);
    check_same(64, strlen((string) $r['body']['token']), 'sem token, bastava comparar as respostas');

    check_same(0, (int) $app->db->fetchColumn('SELECT COUNT(*) FROM form_submissions'), 'nada gravado — keep_spam desligado');
});

test('honeypot: o token falso NÃO resolve — a etapa 2 do bot é recusada', function () {
    $app = fsa_boot([['nome', 'email'], ['cep']]);
    $t = fsa_post($app, ['_step' => 1, '_hp' => 'bot', 'nome' => 'Bot', 'email' => 'b@x.dev'])['body']['token'];

    $r = fsa_post($app, ['_step' => 2, '_step_token' => $t, 'cep' => '01001-000']);
    check_same('invalid_token', $r['body']['code']);
});

test('honeypot num formulário SEM etapas: a forma antiga, intacta', function () {
    $app = fsa_boot([]);
    $r = fsa_post($app, ['_hp' => 'bot', 'nome' => 'Bot', 'email' => 'b@x.dev', 'cep' => '01001-000']);

    check_same(200, $r['status']);
    check_same('submitted', $r['body']['code']);
    check(!isset($r['body']['token']), 'formulário sem etapas não tem token');
    check_same(0, (int) $app->db->fetchColumn('SELECT COUNT(*) FROM form_submissions'));
});

test('honeypot na etapa 2 é IGNORADO — não destrói um lead que já era válido', function () {
    // Autofill preenchendo `_hp` na etapa 3 não pode custar o lead inteiro.
    $app = fsa_boot([['nome', 'email'], ['cep']]);
    $t = fsa_post($app, ['_step' => 1, 'nome' => 'Ana', 'email' => 'a@x.dev'])['body']['token'];

    $r = fsa_post($app, ['_step' => 2, '_step_token' => $t, '_hp' => 'autofill', 'cep' => '01001-000']);

    check_same('submitted', $r['body']['code'], 'a etapa 2 é a final aqui: tinha que concluir');
    $row = (array) $app->db->fetch('SELECT * FROM form_submissions LIMIT 1');
    check_same(null, $row['blocked_by'], 'nunca marcar como spam um lead já gravado');
    check_same('01001-000', ((array) json_decode($row['data'], true))['cep'], 'o dado da etapa tem que estar lá');
});

// ─── Turnstile também depende da linha não existir ──────────────────────
//
// A brief não trouxe teste de Turnstile — só de honeypot —, e por um motivo
// real: sem chave configurada, `TurnstileService::verify()` libera sempre
// (`turnstile_service_test.php`: "sem configuração, sempre libera"), então
// não há como observar a guarda sem bater na rede da Cloudflare. Mas aquele
// mesmo arquivo mostra a saída: configurado e SEM token, o `verify()` reprova
// de cara, sem round-trip nenhum. É o atalho usado daqui para baixo.
//
// O helper de ambiente é `fst_with_turnstile_env()`, do fixture — era uma
// cópia local daqui, e virou fixture porque `turnstile_service_test.php`
// precisa da mesma coisa e carrega DEPOIS deste arquivo no alfabeto.

test('turnstile na etapa 2 é PULADO — configurado e sem token, a etapa completa igual', function () {
    // Etapa 1 roda SEM Turnstile configurado, como nos outros testes deste
    // arquivo — é só a etapa 2 que precisa estar sob a chave, para isolar o
    // que está sendo provado.
    $app = fsa_boot([['nome', 'email'], ['cep']]);
    $t = fsa_post($app, ['_step' => 1, 'nome' => 'Ana', 'email' => 'a@x.dev'])['body']['token'];

    $r = fst_with_turnstile_env('site', 'secret', fn() => fsa_post($app, ['_step' => 2, '_step_token' => $t, 'cep' => '01001-000']));

    // Se o gate vazasse para a etapa 2, isto voltaria captcha_failed — a
    // etapa 2 nem tem campo de captcha para preencher.
    check_same('submitted', $r['body']['code'], 'a etapa 2 não pode ter perguntado ao Turnstile');
    $row = (array) $app->db->fetch('SELECT * FROM form_submissions LIMIT 1');
    check_same(null, $row['pending_step'], 'o lead tinha que ter fechado normalmente');
});

// ─── Volta para a etapa 1: onde "etapa 1" e "linha não existe" divergem ──
//
// Os cinco testes acima passam sob os DOIS predicados — o certo
// (`action === 'create'`) e o errado (`step === 1`) —, porque em todos eles a
// etapa 1 é sempre a requisição que CRIA a linha. Os dois de baixo são os que
// separam um do outro: navegação para trás, que resolve como `merge` com
// `step` 1 contra um lead que já existe. Sob o predicado errado, o primeiro
// perde a correção da visitante e o segundo devolve `captcha_failed`.

test('volta para a etapa 1 com `_hp`: a correção é gravada e o lead segue vivo', function () {
    // O autofill do Chrome preenchendo `_hp` na re-edição não pode custar o
    // lead. Sob o predicado errado, a correção era descartada em silêncio, a
    // visitante recebia um token DESCARTÁVEL, e o POST seguinte morria com
    // `invalid_token` — lead encalhado em `pending_step` para sempre.
    $app = fst_boot(fst_steps(), fst_fields());
    $t1 = fsa_post($app, ['_step' => 1, 'nome' => 'Ana', 'email' => 'errado@x.dev'])['body']['token'];
    $t2 = fsa_post($app, ['_step' => 2, '_step_token' => $t1, 'cep' => '01001-000'])['body']['token'];

    $r = fsa_post($app, [
        '_step' => 1, '_step_token' => $t2, '_hp' => 'autofill',
        'nome' => 'Ana', 'email' => 'certo@x.dev',
    ]);

    check_same(200, $r['status']);
    check_same('step_saved', $r['body']['code']);
    check_same(1, $r['body']['step']);
    check_same(3, $r['body']['next_step'], 'o ponteiro não anda para trás');
    check_same($t2, $r['body']['token'], 'o token tem que ser o DO LEAD, não um descartável');

    check_same(1, (int) $app->db->fetchColumn('SELECT COUNT(*) FROM form_submissions'), 'nenhuma linha de spam nasce');
    $row = fst_row(1);
    check_same(null, $row['blocked_by'], 'o lead em andamento não vira spam');
    check_same(3, (int) $row['pending_step'], 'continua exatamente onde estava');
    check_same('certo@x.dev', ((array) json_decode($row['data'], true))['email'], 'a correção não pode ser descartada');

    // E o token devolvido ainda abre a etapa seguinte — era isto que o token
    // descartável destruía, e sem barulho nenhum.
    $fim = fsa_post($app, ['_step' => 3, '_step_token' => $r['body']['token'], 'budget' => '10k']);
    check_same('submitted', $fim['body']['code'], 'o lead tinha que conseguir fechar');
});

test('volta para a etapa 1 com Turnstile configurado: passa — o token já foi gasto', function () {
    // A re-edição não traz `cf-turnstile-response` novo, e mesmo se trouxesse
    // o token já teria sido gasto no primeiro `siteverify`. Sob o predicado
    // errado, voltar para a etapa 1 era 422 em 100% das vezes, para todo
    // visitante de todo formulário com etapas e Turnstile ligado.
    $app = fst_boot(fst_steps(), fst_fields());
    $t1 = fsa_post($app, ['_step' => 1, 'nome' => 'Ana', 'email' => 'errado@x.dev'])['body']['token'];
    $t2 = fsa_post($app, ['_step' => 2, '_step_token' => $t1, 'cep' => '01001-000'])['body']['token'];

    $r = fst_with_turnstile_env('site', 'secret', fn() => fsa_post($app, [
        '_step' => 1, '_step_token' => $t2, 'nome' => 'Ana', 'email' => 'certo@x.dev',
    ]));

    check_same(200, $r['status'], 'era 422 captcha_failed com a guarda no número da etapa');
    check_same('step_saved', $r['body']['code']);
    check_same('certo@x.dev', ((array) json_decode(fst_row(1)['data'], true))['email']);
});

// ─── A forma do `step_saved` é uma só ───────────────────────────────────

test('a forma do `step_saved` é UMA — a real e a falsa não podem divergir', function () {
    // O invariante do `successPayload()`: "as duas respostas precisam ser
    // byte a byte iguais". Enquanto a intermediária real era um literal à
    // parte, a primeira chave a nascer só nela — o `step_webhook` da Task 8 —
    // entregaria a armadilha a quem comparasse as duas.
    $app = fsa_boot([['nome', 'email'], ['cep']]);
    $real = fsa_post($app, ['_step' => 1, 'nome' => 'Ana', 'email' => 'a@x.dev'])['body'];

    // Boot novo: o honeypot precisa da MESMA situação, não do lead anterior.
    $app = fsa_boot([['nome', 'email'], ['cep']]);
    $falsa = fsa_post($app, ['_step' => 1, '_hp' => 'bot', 'nome' => 'Bot', 'email' => 'b@x.dev'])['body'];

    check_same(array_keys($real), array_keys($falsa), 'uma chave a mais de um lado entrega o honeypot');

    // O token é o único valor que pode diferir: um é do lead, o outro é lixo
    // de propósito — e os dois têm que ter a mesma cara.
    check_same(64, strlen((string) $real['token']));
    check_same(64, strlen((string) $falsa['token']));
    unset($real['token'], $falsa['token']);
    check_same($real, $falsa, 'fora o token, as duas respostas são idênticas');
});

// ─── A linha de spam mostra o que a visitante viu ───────────────────────

test('linha de spam guarda TUDO que a visitante preencheu, não só a etapa', function () {
    // Com `keep_spam`, o falso positivo é um lead de verdade e esta linha é o
    // único registro que sobra dele — o `recordSpam()` é terminal, não existe
    // segunda chance de recuperar o que ficou de fora.
    //
    // Quem decide o que vai no POST é o tema: `new FormData(form)` manda o
    // formulário inteiro, que é a forma comum. Recortar pela etapa aqui
    // apagaria para sempre o `cep` e o `budget` que ela digitou, justamente
    // no caminho que o `keep_spam` existe para salvar.
    $app = fst_boot(fst_steps(), fst_fields(), ['keep_spam' => true]);
    fsa_post($app, [
        '_step' => 1,
        '_hp'   => 'bot',
        'nome'  => 'Ana',
        'email' => 'ana@x.dev',
        'cep'   => '01001-000',
        'budget' => '50k',
    ]);

    $row = fst_row(1);
    check_same('honeypot', $row['blocked_by']);
    check_same(
        ['nome' => 'Ana', 'email' => 'ana@x.dev', 'cep' => '01001-000', 'budget' => '50k'],
        (array) json_decode($row['data'], true),
        'nada do que ela digitou pode sumir'
    );
});
