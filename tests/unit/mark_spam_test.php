<?php

declare(strict_types=1);

use Velk\Admin\Controllers\FormController;
use Velk\App;
use Velk\Auth;
use Velk\Config;
use Velk\Database;
use Velk\Models\FormSubmission;
use Velk\Request;
use Velk\Session;

/**
 * v3.7.6: o link "É spam" — marcar à mão o que os filtros deixaram passar.
 *
 * Terceira categoria de `blocked_by`, ao lado de 'honeypot' e 'turnstile'.
 * Até a v3.7.5 este caminho não existia de propósito (ver o docblock do
 * markSpam para por que a decisão mudou).
 *
 * ─── Por que estes testes exercitam o controller de verdade ─────────────
 *
 * O not_spam_test.php, irmão deste, afirma sobre o TEXTO do controller
 * (str_contains procurando 'requireAuth', '403'). Uma revisão nesta mesma
 * sessão provou que essa forma não protege: removendo o `return` de uma
 * guarda de auth — o que deixa o endpoint ABERTO — testes assim continuam
 * verdes, porque a string continua lá.
 *
 * Então aqui o controller é instanciado de verdade (App, Config, Session e
 * Auth reais, SQLite via Reflection) e cada caso afirma a DUPLA: o código
 * de resposta E o estado do banco depois. Só a resposta deixaria passar uma
 * guarda que não bloqueia; só o banco deixaria passar um erro que grava
 * mesmo assim.
 *
 * O caso que mais importa é o de não sobrescrever bloqueio existente: um
 * 'honeypot' virando 'manual' apagaria a procedência, que é justamente o
 * dado que a lista de spam existe para guardar.
 */

// admin_url()/base_path(), chamados por Controller::requireAuth(), são
// helpers globais de core/helpers.php e não vêm pelo autoloader de classes.
require_once dirname(__DIR__, 2) . '/core/helpers.php';

if (!function_exists('ms_boot')) {
    /**
     * @param array<string,mixed> $forms  o bloco `forms` do site.json
     * @param list<array{0:int,1:string,2:?string}> $subs [id, form_id, blocked_by]
     */
    function ms_boot(array $forms, array $subs): App
    {
        $rootPath = dirname(__DIR__, 2);

        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, ['paths' => ['core' => $rootPath . '/core']]);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, ['forms' => $forms]);

        $app = new App($config);
        $app->basePath = '';

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec(
            'CREATE TABLE form_submissions (
                id INTEGER PRIMARY KEY, form_id TEXT NOT NULL, blocked_by TEXT,
                pending_step INTEGER NULL, step_token TEXT NULL,
                data TEXT, params TEXT, ip TEXT, user_agent TEXT, referer TEXT,
                email_status TEXT, email_error TEXT, sent_to TEXT, created_at TEXT
            )'
        );
        $pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY, email TEXT, password_hash TEXT, name TEXT,
                role TEXT, two_factor_enabled INTEGER DEFAULT 0, locale TEXT, created_at TEXT
            )'
        );
        $ins = $pdo->prepare(
            "INSERT INTO form_submissions (id, form_id, blocked_by, data, params, created_at)
             VALUES (?, ?, ?, '[]', '[]', '2026-01-01 00:00:00')"
        );
        foreach ($subs as [$id, $formId, $blockedBy]) {
            $ins->execute([$id, $formId, $blockedBy]);
        }
        $pdo->exec(
            "INSERT INTO users (id, email, password_hash, name, role, two_factor_enabled, locale, created_at)
             VALUES (1, 'admin@teste.dev', '', 'Admin', 'admin', 0, NULL, '2026-01-01 00:00:00')"
        );

        $db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Database::class, 'pdo'))->setValue($db, $pdo);
        $app->db = $db;

        $_SESSION = [];
        $app->session = new Session();
        $app->auth = new Auth($app->db, $app->session);
        $app->adminI18n = new \Velk\Admin\I18n($rootPath . '/core/Admin/lang', 'en', 'en');

        return $app;
    }
}

if (!function_exists('ms_post')) {
    /** Chama markSpam() como o router chamaria. */
    function ms_post(App $app, string $formId, int $sid, bool $logado = true, bool $csrfOk = true): \Velk\Response
    {
        if ($logado) {
            $app->session->put('user_id', 1);
        }
        $token = $app->session->csrfToken();

        $request = new Request(
            'POST',
            '/admin/forms/' . $formId . '/submissions/' . $sid . '/spam',
            [],
            ['_csrf' => $csrfOk ? $token : 'token-errado'],
            [],
            [],
            []
        );

        return (new FormController($app))->markSpam($request, ['id' => $formId, 'sid' => $sid]);
    }
}

if (!function_exists('ms_blocked')) {
    function ms_blocked(App $app, int $id): ?string
    {
        $row = $app->db->fetch('SELECT blocked_by FROM form_submissions WHERE id = ?', [$id]);
        return $row['blocked_by'] ?? null;
    }
}

// ─────────────────────────── o model ───────────────────────────

test('markSpam(): grava manual na coluna e no objeto', function () {
    $app = ms_boot(['contato' => ['keep_spam' => true]], [[1, 'contato', null]]);
    $s = FormSubmission::find(1);

    $s->markSpam();

    check_same('manual', $s->blockedBy, 'o objeto em memória precisa refletir a gravação');
    check_same('manual', ms_blocked($app, 1), 'a linha no banco precisa ter mudado');
});

test('markSpam() e markNotSpam() fazem a volta completa', function () {
    // O que torna marcar seguro: errar custa um clique, não o lead.
    $app = ms_boot(['contato' => ['keep_spam' => true]], [[1, 'contato', null]]);
    $s = FormSubmission::find(1);

    $s->markSpam();
    check_same('manual', ms_blocked($app, 1));

    $s->markNotSpam();
    check_same(null, ms_blocked($app, 1), 'devolver precisa zerar de volta para NULL');
});

test('markSpam(): mata a CREDENCIAL e preserva a POSIÇÃO — step_token zerado, pending_step intacto', function () {
    // A revisão final DESFEZ a decisão da Task 10, que zerava as duas
    // colunas. O `step_token` continua morrendo aqui: um lead bloqueado não
    // pode seguir aceitando etapas do visitante. O `pending_step` fica,
    // porque era a única informação que o markNotSpam() não conseguia
    // devolver — ver o teste da volta completa logo abaixo.
    //
    // ms_boot() não modela etapas: grava a etapa direto na linha, como
    // create() faria na primeira etapa de verdade.
    $app = ms_boot(['orcamento' => ['keep_spam' => true]], [[1, 'orcamento', null]]);
    $app->db->update('form_submissions', ['pending_step' => 2, 'step_token' => 'tok-abc'], ['id' => 1]);
    $s = FormSubmission::find(1);
    check_same(2, $s->pendingStep, 'pré-condição: lead em andamento');

    $s->markSpam();

    check_same('manual', $s->blockedBy);
    check_same(null, $s->stepToken, 'a credencial morre no mesmo UPDATE — no OBJETO');
    check_same(2, $s->pendingStep, 'a posição do lead sobrevive ao bloqueio');

    $row = App::instance()->db->fetch('SELECT blocked_by, pending_step, step_token FROM form_submissions WHERE id = ?', [1]);
    check_same('manual', $row['blocked_by']);
    check_same(null, $row['step_token'], 'e também na LINHA — sem isto o objeto mentiria sobre o banco');
    check_same(2, (int) $row['pending_step'], 'idem para a posição');
});

test('markNotSpam(): devolve um lead EM ANDAMENTO, não um lead falsamente completo', function () {
    // O achado "Menor A" da revisão final, e a razão inteira de a Task 10 ter
    // sido desfeita. Com o pending_step zerado no bloqueio, o falso positivo
    // recuperado voltava como CONCLUÍDO: passava a contar no badge e na lista
    // de formulários, aparecia como pronto, sem a marcação "aguardando
    // conclusão", sem nunca ter saído e-mail, sem nunca ter virado conversão,
    // e fora do alcance do cleanup:incomplete-submissions. Recuperar não pode
    // inventar que o lead terminou.
    $app = ms_boot(['orcamento' => ['keep_spam' => true]], [[1, 'orcamento', null]]);
    $app->db->update('form_submissions', ['pending_step' => 2, 'step_token' => 'tok-abc'], ['id' => 1]);
    $s = FormSubmission::find(1);

    $s->markSpam();
    $s->markNotSpam();

    check_same(null, ms_blocked($app, 1), 'a volta continua completa');

    $voltou = FormSubmission::find(1);
    check_same(2, $voltou->pendingStep, 'o lead volta para a etapa em que estava');
    check_same(null, $voltou->stepToken, 'mas sem a credencial: a visitante segue barrada');

    // E por isso ele NÃO conta como lead pronto — é o mesmo recorte do badge
    // do menu e da coluna da lista de formulários.
    check_same(0, FormSubmission::count('orcamento'), 'lead recuperado em andamento não é lead pronto');
    check_same(1, FormSubmission::countIncomplete('orcamento'), 'é um incompleto, e é assim que a limpeza o alcança');
});

test('markSpam(): a linha bloqueada e INCOMPLETA continua alcançável pelo recorte do painel de spam', function () {
    // Achado "Menor B". O painel de spam pede completeness='all'
    // explicitamente (ver form_panel_completeness_test.php, que exercita o
    // controller inteiro); aqui fica travado que o recorte 'all' realmente
    // alcança a linha, para o caso de alguém mexer no filterWhere(). Sem os
    // dois juntos volta o "badge diz 1, página diz nada" da Task 10.
    $app = ms_boot(['orcamento' => ['keep_spam' => true]], [[1, 'orcamento', null]]);
    $app->db->update('form_submissions', ['pending_step' => 2, 'step_token' => 'tok-abc'], ['id' => 1]);
    FormSubmission::find(1)->markSpam();

    [$where, $extraParams] = FormSubmission::filterWhere(['spam' => true, 'completeness' => 'all']);
    $count = (int) $app->db->fetchColumn(
        'SELECT COUNT(*) FROM form_submissions WHERE form_id = ?' . $where,
        array_merge(['orcamento'], $extraParams)
    );
    check_same(1, $count, 'lead marcado como spam não pode sumir da lista de spam');

    // O controle positivo: sem o 'all' ela sumiria mesmo. É esta linha que
    // prova que o 'all' do controller está segurando alguma coisa de verdade.
    [$padrao, $padraoParams] = FormSubmission::filterWhere(['spam' => true]);
    check_same(0, (int) $app->db->fetchColumn(
        'SELECT COUNT(*) FROM form_submissions WHERE form_id = ?' . $padrao,
        array_merge(['orcamento'], $padraoParams)
    ), 'o default recorta por completos — é dele que o painel de spam tem que fugir');
});

// ─────────────────────────── o controller ───────────────────────────

test('markSpam: caminho feliz marca e redireciona', function () {
    $app = ms_boot(['contato' => ['keep_spam' => true]], [[7, 'contato', null]]);

    $r = ms_post($app, 'contato', 7);

    check_same(302, $r->getStatus(), 'deveria redirecionar de volta para a lista');
    check_same('manual', ms_blocked($app, 7));
});

test('markSpam: formulário SEM keep_spam devolve 404 e não grava', function () {
    // Gravar spam num formulário que declarou não guardá-lo seria criar
    // registro que a configuração diz não querer.
    $app = ms_boot(['contato' => ['keep_spam' => false]], [[7, 'contato', null]]);

    $r = ms_post($app, 'contato', 7);

    check_same(404, $r->getStatus());
    check_same(null, ms_blocked($app, 7), 'nada pode ter sido gravado');
});

test('markSpam: formulário sem a chave nenhuma também devolve 404', function () {
    $app = ms_boot(['contato' => []], [[7, 'contato', null]]);

    check_same(404, ms_post($app, 'contato', 7)->getStatus());
    check_same(null, ms_blocked($app, 7));
});

test('markSpam: submissão de OUTRO formulário não é marcada', function () {
    // Sem a conferência de formId, o id de uma submissão alheia seria
    // marcável por esta rota.
    $app = ms_boot(
        ['contato' => ['keep_spam' => true], 'orcamento' => ['keep_spam' => true]],
        [[7, 'orcamento', null]]
    );

    $r = ms_post($app, 'contato', 7);

    check_same(302, $r->getStatus(), 'redireciona igual, sem revelar que o id existe');
    check_same(null, ms_blocked($app, 7), 'a submissão do outro formulário não pode mudar');
});

test('markSpam: NÃO sobrescreve bloqueio que já existe', function () {
    // O caso mais importante do arquivo. Um 'honeypot' virando 'manual'
    // apagaria a procedência — o dado que a lista de spam existe pra guardar.
    $app = ms_boot(['contato' => ['keep_spam' => true]], [[7, 'contato', 'honeypot']]);

    ms_post($app, 'contato', 7);

    check_same('honeypot', ms_blocked($app, 7), 'a origem original tem que sobreviver');
});

test('markSpam: sem autenticação não grava', function () {
    // CSRF válido de propósito, pra isolar a guarda de auth: sem isso um 403
    // de CSRF mascararia um requireAuth() que não bloqueia mais nada.
    $app = ms_boot(['contato' => ['keep_spam' => true]], [[7, 'contato', null]]);

    $r = ms_post($app, 'contato', 7, logado: false);

    check_same(302, $r->getStatus(), 'deveria mandar para o login');
    check_same(null, ms_blocked($app, 7), 'requisição não autenticada não pode gravar');
});

test('markSpam: CSRF inválido não grava', function () {
    $app = ms_boot(['contato' => ['keep_spam' => true]], [[7, 'contato', null]]);

    $r = ms_post($app, 'contato', 7, csrfOk: false);

    // Formulário comum (não AJAX): desde a v3.10 a falha de CSRF volta para a
    // tela com o aviso de sessão expirada, em vez da página crua de 403.
    check_same(303, $r->getStatus());
    check_same(null, ms_blocked($app, 7));
});

test('a rota existe e é POST', function () {
    // GET permitiria marcar por link — inclusive um <img src> numa página
    // que o admin abrisse. Mesma preocupação de not_spam_test.php.
    $src = file_get_contents(dirname(__DIR__, 2) . '/core/Admin/AdminRouter.php');

    check(
        str_contains($src, "\$router->post(\$prefix . '/forms/{id}/submissions/{sid}/spam'"),
        'rota /spam ausente ou não é POST'
    );
});
