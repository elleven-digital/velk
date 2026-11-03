<?php

declare(strict_types=1);

use Velk\Models\FormSubmission;
use Velk\PublicFormController;

/**
 * v3.7.2 (spam retido): submissão reprovada pelo antispam vira linha marcada
 * com `blocked_by`, em vez de sumir.
 *
 * ─── O que estes testes seguram ──────────────────────────────────────────
 *
 * A regra de ouro é o DEFAULT: qualquer consulta que não peça spam recebe só
 * aprovados. Errar isso não quebra nada visivelmente — a lista de leads do
 * cliente simplesmente enche de bot, e ninguém percebe até ele reclamar. É
 * exatamente o tipo de regressão que teste existe para pegar.
 *
 * A segunda é o gate: sem `keep_spam` no site.json, nada é gravado. Um
 * vazamento aqui faz o site reter dado pessoal de quem foi classificado como
 * bot sem que ninguém tenha pedido — problema de LGPD, não de disco.
 *
 * A terceira é que webhook, email e evento de conversão NUNCA rodam para
 * spam. Isso é estrutural (os três vivem depois do create() do fluxo normal,
 * e os dois returns do antispam nunca chegam lá), mas estrutura se perde num
 * refactor distraído — daí a guarda textual no fim.
 */

if (!function_exists('spam_values')) {
    /** @return array<string,string> */
    function spam_values(array $fields, array $post, array $files = []): array
    {
        $request = new Velk\Request('POST', '/forms/x', [], $post, $files, ['REQUEST_METHOD' => 'POST'], []);
        $m = new ReflectionMethod(PublicFormController::class, 'spamValues');
        return $m->invoke(null, $fields, $request);
    }
}

test('filterWhere: sem pedir spam, só aprovadas', function () {
    // O default seguro. Se isto virar string vazia, a lista de leads passa a
    // mostrar bot — e nada mais no sistema acusa.
    [$sql] = FormSubmission::filterWhere([]);
    check(str_contains($sql, 'blocked_by IS NULL'), "esperava recorte de aprovadas, veio: {$sql}");
});

test('filterWhere: pedindo spam, só bloqueadas', function () {
    [$sql] = FormSubmission::filterWhere(['spam' => true]);
    check(str_contains($sql, 'blocked_by IS NOT NULL'), "esperava recorte de spam, veio: {$sql}");
});

test('filterWhere: os dois recortes são mutuamente exclusivos', function () {
    // Nunca podem somar: "quantos leads eu tenho" não inclui bot, e a soma
    // das duas listas tem que ser o total da tabela, sem sobreposição.
    [$normal] = FormSubmission::filterWhere([]);
    [$spam] = FormSubmission::filterWhere(['spam' => true]);
    check_same(false, $normal === $spam);
    check(!str_contains($normal, 'IS NOT NULL'), 'lista normal não pode trazer bloqueadas');
});

test('filterWhere: o recorte convive com o filtro de data', function () {
    [$sql, $params] = FormSubmission::filterWhere(['spam' => true, 'date_from' => '2026-01-01']);
    check(str_contains($sql, 'blocked_by IS NOT NULL'), 'perdeu o recorte de spam ao filtrar por data');
    check(str_contains($sql, 'created_at >='), 'perdeu o filtro de data');
    check_same('2026-01-01 00:00:00', $params[0]);
});

test('spamValues: só campos declarados — csrf, honeypot e captcha ficam fora', function () {
    // `_hp` no registro seria ruído; `_csrf` seria um token de sessão gravado
    // em texto no banco, que é pior.
    $valores = spam_values(
        [['name' => 'nome', 'type' => 'text'], ['name' => 'email', 'type' => 'email']],
        ['nome' => 'Maria', 'email' => 'm@x.com', '_csrf' => 'segredo', '_hp' => 'bot',
         'cf-turnstile-response' => 'tok', 'campo_nao_declarado' => 'lixo']
    );

    check_same(['nome' => 'Maria', 'email' => 'm@x.com'], $valores);
});

test('spamValues: arquivo de bot nunca é gravado, só o nome declarado', function () {
    // Guardar upload de quem o antispam reprovou é deixar um bot escrever no
    // disco do servidor — exatamente o que ele queria.
    $valores = spam_values(
        [['name' => 'anexo', 'type' => 'file']],
        [],
        ['anexo' => ['name' => 'payload.pdf', 'tmp_name' => '/tmp/x', 'size' => 999, 'error' => 0]]
    );

    check_same('payload.pdf', $valores['anexo']);
    check(!isset($valores['anexo']['tmp_name']), 'não pode carregar metadado de upload');
});

test('spamValues: valor gigante é truncado', function () {
    // Sem teto, um bot grava megabytes por submissão.
    $valores = spam_values([['name' => 'msg', 'type' => 'text']], ['msg' => str_repeat('a', 50000)]);
    check_same(2000, mb_strlen($valores['msg']));
});

test('spamValues: campo multi-valor vira texto, sem quebrar', function () {
    $valores = spam_values(
        [['name' => 'temas', 'type' => 'checkbox']],
        ['temas' => ['seo', 'ads']]
    );
    check_same('seo, ads', $valores['temas']);
});

test('spamValues: campo ausente no POST vira string vazia, não some', function () {
    // Some seria pior: no falso positivo, a tela mostraria um formulário
    // pela metade e ninguém saberia se o campo existia.
    $valores = spam_values([['name' => 'nome', 'type' => 'text'], ['name' => 'tel', 'type' => 'text']], ['nome' => 'X']);
    check_same('', $valores['tel']);
});

test('keepsSpamFor: ausente é false — o gate fecha por padrão', function () {
    $config = new Velk\Config(dirname(__DIR__, 2));
    $ref = new ReflectionProperty(Velk\Config::class, 'site');
    $ref->setValue($config, ['forms' => ['a' => [], 'b' => ['keep_spam' => true]]]);

    check_same(false, $config->keepsSpamFor('a'));
    check_same(false, $config->keepsSpamFor('inexistente'));
    check_same(true, $config->keepsSpamFor('b'));
});

test('estrutural: email, webhook e evento vivem DEPOIS do retorno do antispam', function () {
    // A garantia de que spam nunca dispara webhook não é uma checagem — é a
    // posição do código. Este teste prova a posição, que é o que pode se
    // perder num refactor.
    $src = file_get_contents(dirname(__DIR__, 2) . '/core/PublicFormController.php');

    // A agulha FECHA o parêntese de propósito. Ela prende a posição das duas
    // chamadas, mas o `)` no fim é um segundo serviço: mexer na lista de
    // argumentos do recordSpam() deixa este teste vermelho e obriga alguém a
    // reler a ordem que ele protege. Já valeu uma vez — não afrouxe para
    // calar; atualize a agulha junto com a chamada.
    $honeypot  = strpos($src, "recordSpam(\$formId, \$form, \$request, 'honeypot')");
    $turnstile = strpos($src, "recordSpam(\$formId, \$form, \$request, 'turnstile')");
    $webhook   = strpos($src, '$this->dispatchWebhook(');
    $evento    = strpos($src, "'name' => 'velk_form_success'");

    check($honeypot !== false && $turnstile !== false, 'os dois pontos de gravação precisam existir');
    check($honeypot < $webhook, 'honeypot deve retornar antes do dispatchWebhook');
    check($turnstile < $webhook, 'turnstile deve retornar antes do dispatchWebhook');
    check($honeypot < $evento, 'honeypot deve retornar antes do evento de conversão');
    check($turnstile < $evento, 'turnstile deve retornar antes do evento de conversão');
});

test('estrutural: nenhuma contagem de submissão esqueceu o recorte', function () {
    // Cada `FROM form_submissions` sem recorte é uma porta pro spam aparecer
    // como lead. A busca por id é a exceção legítima: o detalhe e o delete
    // precisam alcançar uma linha bloqueada.
    $arquivos = [
        'core/Models/FormSubmission.php',
        'core/Admin/views/layout/main.php',
    ];
    foreach ($arquivos as $rel) {
        $src = file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
        foreach (explode("\n", $src) as $n => $linha) {
            if (!str_contains($linha, 'FROM form_submissions')) {
                continue;
            }
            $ok = str_contains($linha, 'blocked_by')
                || str_contains($linha, 'WHERE id = ?')
                || str_contains($linha, '$where');
            check($ok, "{$rel}:" . ($n + 1) . ' consulta form_submissions sem recorte de spam');
        }
    }
});

test('estrutural: quem conta LEAD também recorta por etapa', function () {
    // Irmã da guarda acima, na dimensão que a v3.8.0 acrescentou. `blocked_by
    // IS NULL` é a assinatura de "esta consulta conta lead": quem a usa tem
    // que dizer alguma coisa sobre `pending_step`, porque quem parou na etapa
    // 2 ainda não é contato.
    //
    // A regra é "mencionar", não "exigir IS NULL", de propósito: o
    // `findOpenByStepToken()` conta lead ABERTO e precisa do `IS NOT NULL`. O
    // que não pode é a coluna não aparecer — foi assim que o badge da sidebar
    // ficou dizendo 7 enquanto a página dizia 3.
    //
    // O `countSpam()` não entra: não tem `blocked_by IS NULL`, e linha de spam
    // é contada esteja como estiver.
    $arquivos = [
        'core/Models/FormSubmission.php',
        'core/Admin/views/layout/main.php',
    ];
    foreach ($arquivos as $rel) {
        $src = file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
        foreach (explode("\n", $src) as $n => $linha) {
            if (!str_contains($linha, 'FROM form_submissions')) {
                continue;
            }
            if (!str_contains($linha, 'blocked_by IS NULL')) {
                continue;
            }
            check(
                str_contains($linha, 'pending_step'),
                "{$rel}:" . ($n + 1) . ' conta lead sem dizer nada sobre pending_step'
            );
        }
    }
});
