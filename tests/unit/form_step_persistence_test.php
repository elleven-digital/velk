<?php

declare(strict_types=1);

use Velk\App;
use Velk\Config;
use Velk\Database;
use Velk\Models\FormSubmission;

/**
 * Persistência de etapas (spec 2026-09-09 §3): o lead nasce aberto, mescla,
 * e fecha zerando ponteiro e token no mesmo UPDATE.
 *
 * O caso do `create()` é o mais importante do arquivo. Ele monta a lista de
 * colunas do INSERT à mão, e coluna fora dessa lista é descartada em
 * SILÊNCIO — o lead nasceria completo e sem token já na primeira etapa, e
 * nada acusaria. Esse erro exato já aconteceu duas vezes aqui (`sort_order`
 * em Item::create e Term::create).
 */

if (!function_exists('fsp_app')) {
    function fsp_app(): App
    {
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, []);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, []);
        $app = new App($config);

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("CREATE TABLE form_submissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            form_id TEXT NOT NULL,
            blocked_by TEXT NULL,
            pending_step INTEGER NULL,
            step_token TEXT NULL,
            data TEXT NOT NULL,
            ip TEXT NULL, user_agent TEXT NULL, referer TEXT NULL,
            email_status TEXT NULL, email_error TEXT NULL, sent_to TEXT NULL,
            params TEXT NULL,
            created_at TEXT NOT NULL DEFAULT '2026-01-01 00:00:00'
        )");

        $db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Database::class, 'pdo'))->setValue($db, $pdo);
        $app->db = $db;
        return $app;
    }
}

if (!function_exists('fsp_open')) {
    /** Cria um lead ABERTO na etapa $pending com o token dado. */
    function fsp_open(array $data, int $pending, string $token, string $formId = 'orcamento'): FormSubmission
    {
        return FormSubmission::create([
            'form_id' => $formId,
            'data' => $data,
            'params' => ['utm_source' => 'google'],
            'ip' => '1.2.3.4',
            'pending_step' => $pending,
            'step_token' => $token,
        ]);
    }
}

// ─── create() ────────────────────────────────────────────────────────────

test('create: pending_step e step_token CHEGAM ao banco', function () {
    // A armadilha: a lista de colunas do INSERT é explícita, e coluna fora
    // dela é descartada em silêncio.
    fsp_app();
    $s = fsp_open(['nome' => 'Ana'], 2, 'tok-abc');

    check_same(2, $s->pendingStep, 'o lead tem que nascer ABERTO na etapa 2');
    check_same('tok-abc', $s->stepToken);

    $row = App::instance()->db->fetch('SELECT pending_step, step_token FROM form_submissions WHERE id = ?', [$s->id]);
    check_same('2', (string) $row['pending_step'], 'o valor tem que estar na LINHA, não só no objeto');
    check_same('tok-abc', $row['step_token']);
});

test('create: sem as chaves, a linha nasce completa e sem token — o de hoje', function () {
    fsp_app();
    $s = FormSubmission::create(['form_id' => 'contato', 'data' => ['nome' => 'Ana']]);
    check_same(null, $s->pendingStep);
    check_same(null, $s->stepToken);
});

// ─── findOpenByStepToken ─────────────────────────────────────────────────

test('findOpenByStepToken: acha o lead aberto do formulário certo', function () {
    fsp_app();
    $s = fsp_open(['nome' => 'Ana'], 2, 'tok-abc');
    check_same($s->id, FormSubmission::findOpenByStepToken('orcamento', 'tok-abc')?->id);
});

test('findOpenByStepToken: token de OUTRO formulário não resolve', function () {
    fsp_app();
    fsp_open(['nome' => 'Ana'], 2, 'tok-abc', 'orcamento');
    check_same(null, FormSubmission::findOpenByStepToken('contato', 'tok-abc'));
});

test('findOpenByStepToken: lead FECHADO não resolve, nem token vazio', function () {
    fsp_app();
    FormSubmission::create(['form_id' => 'orcamento', 'data' => [], 'step_token' => 'tok-morto']);
    check_same(null, FormSubmission::findOpenByStepToken('orcamento', 'tok-morto'), 'pending_step NULL = fechado');
    check_same(null, FormSubmission::findOpenByStepToken('orcamento', ''));
    check_same(null, FormSubmission::findOpenByStepToken('orcamento', 'nunca-existiu'));
});

test('findOpenByStepToken: lead marcado como SPAM não resolve mais', function () {
    // Cenário: o lead está EM ANDAMENTO (pending_step aberto) quando o admin
    // olha a lista, reconhece bot e clica "É spam". Este teste nasceu quando
    // markSpam() só gravava blocked_by = 'manual' na linha existente — sem
    // tocar pending_step nem step_token — e era só o recorte de blocked_by
    // aqui embaixo que impedia o visitante com o token ainda na sessão de
    // mandar a próxima etapa para uma linha em quarentena.
    //
    // A revisão final desfez metade daquilo: markSpam() zera o step_token
    // (a credencial não pode sobreviver ao bloqueio) mas PRESERVA o
    // pending_step, porque apagá-lo destruía a única informação que o
    // markNotSpam() não conseguia devolver — ver o docblock dele.
    //
    // Então a asserção abaixo continua verdadeira, e é honesto dizer por
    // quê: o que a segura hoje é a coluna step_token zerada, contra a qual
    // nenhum valor casa. A linha SEGUE casando com pending_step IS NOT NULL.
    // O recorte de blocked_by na consulta é defesa em profundidade: o que
    // resta de pé se um markSpam() futuro voltar a esquecer de matar a
    // credencial, que é exatamente a situação de antes da v3.8.0.
    fsp_app();
    $s = fsp_open(['nome' => 'Ana'], 2, 'tok-abc');
    $s->markSpam();

    check_same(null, FormSubmission::findOpenByStepToken('orcamento', 'tok-abc'), 'lead bloqueado não pode ser alcançado por token');
});

test('findOpenByStepToken: bloqueado com token VIVO também não resolve', function () {
    // O teste acima passa hoje pela razão do vizinho: o markSpam() zera o
    // token, e nenhum valor casa com NULL. Ou seja, ele deixou de distinguir
    // qual das duas metades está segurando — apagar o `blocked_by IS NULL` da
    // consulta o mantinha VERDE (a revisão final mediu).
    //
    // Este isola. O bloqueio é gravado direto, SEM passar pelo markSpam(),
    // então o token continua vivo e a etapa continua aberta: é exatamente o
    // estado de antes da v3.8.0, e o único jeito de provar que a cláusula de
    // blocked_by sustenta alguma coisa sozinha.
    fsp_app();
    $s = fsp_open(['nome' => 'Ana'], 2, 'tok-viva');
    App::instance()->db->update('form_submissions', ['blocked_by' => 'manual'], ['id' => $s->id]);

    check_same(
        null,
        FormSubmission::findOpenByStepToken('orcamento', 'tok-viva'),
        'a quarentena vale mesmo com credencial viva'
    );
});

// ─── mergeStep ───────────────────────────────────────────────────────────

test('mergeStep: mescla por nome de campo e move o ponteiro', function () {
    fsp_app();
    $s = fsp_open(['nome' => 'Ana', 'email' => 'a@x.dev'], 2, 'tok-abc');
    $s->mergeStep(['cep' => '01001-000'], 3);

    check_same(['nome' => 'Ana', 'email' => 'a@x.dev', 'cep' => '01001-000'], $s->data);
    check_same(3, $s->pendingStep);

    $fresh = FormSubmission::find($s->id);
    check_same('01001-000', $fresh->data['cep'], 'tem que estar gravado, não só no objeto');
    check_same('Ana', $fresh->data['nome'], 'a etapa anterior não pode ser perdida');
    check_same(3, $fresh->pendingStep);
});

test('mergeStep: reenvio SOBRESCREVE o campo e preserva os outros', function () {
    fsp_app();
    $s = fsp_open(['nome' => 'Ana', 'email' => 'errado@x.dev'], 3, 'tok-abc');
    $s->mergeStep(['email' => 'certo@x.dev'], 3);

    $fresh = FormSubmission::find($s->id);
    check_same('certo@x.dev', $fresh->data['email']);
    check_same('Ana', $fresh->data['nome']);
    check_same(3, $fresh->pendingStep, 'reenvio não move o ponteiro');
});

test('mergeStep: params, ip e o token NÃO são tocados', function () {
    fsp_app();
    $s = fsp_open(['nome' => 'Ana'], 2, 'tok-abc');
    $s->mergeStep(['cep' => '01001-000'], 3);

    $fresh = FormSubmission::find($s->id);
    check_same(['utm_source' => 'google'], $fresh->params, 'a atribuição é a do primeiro contato');
    check_same('1.2.3.4', $fresh->ip);
    check_same('tok-abc', $fresh->stepToken, 'o token vive até o lead fechar');
});

// ─── completeWith ────────────────────────────────────────────────────────

test('completeWith: mescla e zera ponteiro E token no mesmo UPDATE', function () {
    fsp_app();
    $s = fsp_open(['nome' => 'Ana'], 3, 'tok-abc');
    $s->completeWith(['budget' => '10k']);

    check_same(null, $s->pendingStep);
    check_same(null, $s->stepToken);

    $fresh = FormSubmission::find($s->id);
    check_same('10k', $fresh->data['budget']);
    check_same('Ana', $fresh->data['nome']);
    check_same(null, $fresh->pendingStep, 'NULL = completo');
    check_same(null, $fresh->stepToken, 'o token morre com o lead — vazado, não tem como ser procurado');
});

test('completeWith: depois de fechar, o token não resolve mais', function () {
    fsp_app();
    $s = fsp_open(['nome' => 'Ana'], 3, 'tok-abc');
    $s->completeWith([]);
    check_same(null, FormSubmission::findOpenByStepToken('orcamento', 'tok-abc'));
});

test('completeWith: quem fecha devolve true; quem chega depois devolve false e não reescreve nada', function () {
    // Revisão final, item 4: "exatamente uma vez na etapa final" era um
    // UPDATE incondicional, e o submit() relê o lead fora de transação. Dois
    // POSTs finais sobrepostos com o mesmo token resolviam os dois como
    // completos — dois e-mails, dois velk_form_success, dois webhooks. Hoje
    // não se sobrepõem só porque o handler de sessão em ARQUIVO os serializa,
    // o que é verdade por acidente e evapora num handler Redis com lock
    // desligado. Quem decide agora é o banco, pelo pending_step IS NOT NULL
    // no WHERE.
    //
    // Dois objetos sobre a MESMA linha é o que modela a corrida em processo
    // único: os dois leram o lead aberto, e só um consegue fechá-lo.
    fsp_app();
    $vencedor = fsp_open(['nome' => 'Ana'], 3, 'tok-abc');
    $perdedor = FormSubmission::find($vencedor->id);
    check_same(3, $perdedor->pendingStep, 'pré-condição: os dois leram o lead ABERTO');

    check_same(true, $vencedor->completeWith(['budget' => '10k']), 'quem chega primeiro fecha');
    check_same(false, $perdedor->completeWith(['budget' => 'reenvio duplicado']), 'o segundo não fecha nada');

    $fresh = FormSubmission::find($vencedor->id);
    check_same('10k', $fresh->data['budget'], 'o UPDATE do perdedor não pode ter passado por cima');
    check_same(null, $fresh->pendingStep);
    check_same(null, $fresh->stepToken);
});

test('completeWith: o objeto do perdedor não mente sobre o banco', function () {
    // Devolver false e ainda assim zerar o estado em memória faria o chamador
    // acreditar que fechou. É o objeto do perdedor que o controller consulta
    // logo depois, para montar a resposta.
    fsp_app();
    $vencedor = fsp_open(['nome' => 'Ana'], 3, 'tok-abc');
    $perdedor = FormSubmission::find($vencedor->id);

    $vencedor->completeWith([]);
    $perdedor->completeWith(['budget' => 'x']);

    check_same(3, $perdedor->pendingStep, 'quem não gravou continua achando que o lead está aberto');
    check(!array_key_exists('budget', $perdedor->data), 'e não pode ter mesclado o que não gravou');
});

// ─── filterWhere: completude ─────────────────────────────────────────────

test('filterWhere: sem pedir nada, só COMPLETOS — o default seguro', function () {
    [$sql] = FormSubmission::filterWhere([]);
    check(str_contains($sql, 'pending_step IS NULL'), "esperava recorte de completos, veio: {$sql}");
});

test('filterWhere: completeness=incomplete inverte', function () {
    [$sql] = FormSubmission::filterWhere(['completeness' => 'incomplete']);
    check(str_contains($sql, 'pending_step IS NOT NULL'), $sql);
});

test('filterWhere: completeness=all não recorta por completude', function () {
    [$sql] = FormSubmission::filterWhere(['completeness' => 'all']);
    check(!str_contains($sql, 'pending_step'), "não deveria recortar, veio: {$sql}");
});

test('filterWhere: completude convive com spam e com data', function () {
    [$sql, $params] = FormSubmission::filterWhere([
        'spam' => true, 'completeness' => 'incomplete', 'date_from' => '2026-01-01',
    ]);
    check(str_contains($sql, 'blocked_by IS NOT NULL'), 'perdeu o recorte de spam');
    check(str_contains($sql, 'pending_step IS NOT NULL'), 'perdeu o de completude');
    check(str_contains($sql, 'created_at >='), 'perdeu o de data');
    check_same('2026-01-01 00:00:00', $params[0]);
});

// ─── count(): o badge ────────────────────────────────────────────────────

test('count: o badge conta só completos', function () {
    fsp_app();
    FormSubmission::create(['form_id' => 'orcamento', 'data' => ['a' => '1']]);          // completo
    fsp_open(['b' => '2'], 2, 'tok-1');                                                  // incompleto
    fsp_open(['c' => '3'], 1, 'tok-2');                                                  // incompleto

    check_same(1, FormSubmission::count('orcamento'), 'dizer "3 novos" numa tela que abre mostrando 1 é mentira');
});

// ─── retenção: qual limpeza varre qual linha ─────────────────────────────

if (!function_exists('fsp_cleanup_body')) {
    /**
     * O corpo de um comando `cleanup:*` em `bin/velk`, do nome dele até o
     * `echo` que o fecha.
     *
     * Guarda ESTRUTURAL, e o mesmo atalho que `form_step_lead_values_guard_test.php`
     * documenta: os comandos são closures dentro de um script de CLI que fixa
     * `$rootPath`, e a consulta usa `NOW() - INTERVAL ? DAY`, que é sintaxe de
     * MySQL — não roda no SQLite em memória que estes testes usam. Executá-la
     * de verdade exigiria o banco de desenvolvimento, que um teste não pode
     * tocar. Enquanto for assim, o texto da cláusula é o único observável.
     *
     * O scanner TIRA os comentários antes de olhar (`token_get_all`), e a
     * asserção prende a cláusula INTEIRA, em ordem. As duas coisas foram
     * pedidas pela revisão final, que quebrou a versão anterior desta guarda
     * duas vezes com a suíte verde: trocando o `AND` por `OR` — o que fazia o
     * comando apagar todo lead concluído com mais de 30 dias — e movendo a
     * cláusula para dentro de um comentário. Fragmento solto não prende
     * precedência, e texto em comentário não é código.
     */
    function fsp_cleanup_body(string $nome): string
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/velk');

        // Só código: um `// ... blocked_by IS NULL ...` em prosa satisfaria a
        // asserção enquanto o SQL de verdade não tem nada.
        $limpo = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $limpo .= str_repeat("\n", substr_count($t[1], "\n"));
                continue;
            }
            $limpo .= is_array($t) ? $t[1] : $t;
        }
        $src = $limpo;

        $i = strpos($src, "'{$nome}' => function");
        check($i !== false, "o comando {$nome} não existe mais em bin/velk");
        $j = strpos($src, 'echo "Removed', (int) $i);
        check($j !== false, "não achei o fim do comando {$nome}");
        return substr($src, (int) $i, (int) $j - (int) $i);
    }
}

test('cleanup:incomplete-submissions NÃO varre linha bloqueada', function () {
    // Revisão final, seção 3, item 3. A Task 10 tinha resolvido isto zerando
    // o `pending_step` no markSpam() — o que destruía a posição do lead. A
    // cláusula certa é esta: um lead que o admin marcou como spam DE
    // PROPÓSITO é assunto da retenção de spam, não da limpeza de incompletos.
    // Sem ela, ele era varrido aos 30 dias ignorando o `keep_spam` do
    // formulário.
    $corpo = fsp_cleanup_body('cleanup:incomplete-submissions');

    // A cláusula INTEIRA, em ordem, e não três fragmentos soltos: com
    // fragmentos, trocar o `AND` por `OR` passa verde e o comando apaga todo
    // lead concluído com mais de 30 dias.
    check(
        str_contains(
            $corpo,
            'WHERE pending_step IS NOT NULL AND blocked_by IS NULL AND created_at < (NOW() - INTERVAL ? DAY)'
        ),
        'a regra inteira: incompletos, não bloqueados, mais velhos que o corte'
    );
});

test('cleanup:spam-submissions é quem varre a linha bloqueada, e os dois não se sobrepõem', function () {
    // A outra metade: o recorte tem que existir do lado de lá, senão a linha
    // bloqueada e incompleta não seria varrida por ninguém e ficaria para
    // sempre. Os dois `blocked_by` são complementares — nenhuma linha é
    // alcançada pelas duas limpezas, e nenhuma escapa das duas.
    $spam = fsp_cleanup_body('cleanup:spam-submissions');

    check(str_contains($spam, 'blocked_by IS NOT NULL'), 'a retenção de spam é a dona da linha bloqueada');
    check(
        !str_contains($spam, 'pending_step'),
        'e ela não olha completude: linha bloqueada é varrida esteja como estiver'
    );
});
