<?php

declare(strict_types=1);

use Velk\Admin\Controllers\FormController;
use Velk\App;
use Velk\Auth;
use Velk\Config;
use Velk\Database;
use Velk\Request;
use Velk\Session;

/**
 * Revisão final, achado 1: o lead incompleto não pode sumir — nem ficar
 * inapagável — quando o `steps` para de parsear.
 *
 * `Config::formSteps()` devolve [] para QUALQUER malformação estrutural, de
 * propósito e em silêncio: é a degradação segura, e adivinhar uma partição a
 * partir de lixo reprovaria envio legítimo. O preço estava no painel. Com
 * `formHasSteps()` falso o `<select>` de completude não era renderizado, um
 * `?completeness=all` montado à mão era forçado de volta ao default, e aí
 * TODA consulta do painel — lista, contagem, badge — carregava
 * `AND pending_step IS NULL`. Nome, e-mail e telefone de quem não terminou,
 * invisíveis e inapagáveis: o `AdminRouter` tem uma listagem só, sem ação em
 * lote, e o delete precisa do `sid` que a interface deixou de exibir. Sobrava
 * SQL na mão ou o `cleanup:incomplete-submissions`, que é opt-in.
 *
 * O mesmo arquivo já dizia o contrário 25 linhas abaixo, para spam: "dado
 * pessoal invisível e inapagável pelo painel é o pior desfecho possível,
 * ainda mais com LGPD no meio". A regra do `showSpamLink` acertou; a da
 * completude não. Aqui ela passa a ser a mesma: oferece quando o formulário
 * declara etapas OU quando já existe linha incompleta guardada.
 *
 * ─── Por que a TELA, e não `filterWhere()` ───────────────────────────────
 *
 * Afirmar sobre `filterWhere(['completeness' => 'all'])` seria tautologia: o
 * bug nunca esteve na cláusula, e sim em QUEM escolhe o valor que chega nela
 * — e o desfecho que dói ("inapagável") não é uma string de SQL, é um botão
 * que não está na página. Então o `show()` roda inteiro, com App, Config,
 * Auth, i18n e SQLite reais, renderizando as views de verdade, e as
 * asserções leem o HTML: o `<select>` está lá, o dado da pessoa está lá, e o
 * formulário de delete com o `sid` dela está lá.
 */

// admin_url()/base_path(), chamados por Controller::requireAuth() e pela
// montagem do $clearUrl, são helpers globais de core/helpers.php e não vêm
// pelo autoloader de classes.
require_once dirname(__DIR__, 2) . '/core/helpers.php';

if (!function_exists('fpc_boot')) {
    /**
     * @param mixed $steps  o valor CRU de `forms.orcamento.steps`; null omite
     *                      a chave, e uma string é a malformação do achado
     * @param list<array{0:int,1:?int,2:?string}> $subs [id, pending_step, blocked_by]
     */
    function fpc_boot(mixed $steps, array $subs): App
    {
        $rootPath = dirname(__DIR__, 2);

        $form = ['label' => 'Orçamento', 'keep_spam' => true, 'fields' => [
            ['name' => 'nome', 'type' => 'text', 'required' => true],
            ['name' => 'email', 'type' => 'email', 'required' => true],
            ['name' => 'cep', 'type' => 'text'],
        ]];
        if ($steps !== null) {
            $form['steps'] = $steps;
        }

        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, ['paths' => ['core' => $rootPath . '/core']]);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, ['forms' => ['orcamento' => $form]]);

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
        $pdo->exec('CREATE TABLE form_recipients (id INTEGER PRIMARY KEY, form_id TEXT, email TEXT, name TEXT NULL, created_at TEXT NULL)');
        $pdo->exec('CREATE TABLE form_settings (form_id TEXT PRIMARY KEY, user_data_fields TEXT NULL, conversion_snippet TEXT NULL, updated_at TEXT NULL)');
        $pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY, email TEXT, password_hash TEXT, name TEXT,
                role TEXT, two_factor_enabled INTEGER DEFAULT 0, locale TEXT, created_at TEXT
            )'
        );

        $ins = $pdo->prepare(
            "INSERT INTO form_submissions (id, form_id, blocked_by, pending_step, data, params, created_at)
             VALUES (?, 'orcamento', ?, ?, ?, '[]', '2026-01-01 00:00:00')"
        );
        foreach ($subs as [$id, $pending, $blocked]) {
            // Dado pessoal de verdade no `data`: é ele que o achado descreve
            // como invisível e inapagável, e é o que o teste quer alcançar.
            $ins->execute([$id, $blocked, $pending, '{"nome":"Ana","email":"a@b.c"}']);
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
        $app->session->put('user_id', 1);
        $app->adminI18n = new \Velk\Admin\I18n($rootPath . '/core/Admin/lang', 'en', 'en');

        return $app;
    }
}

if (!function_exists('fpc_show')) {
    /**
     * Roda `show()` de verdade e devolve o HTML da página do painel.
     *
     * @param array<string,string> $query a query string do painel
     */
    function fpc_show(App $app, array $query = []): string
    {
        $request = new Request('GET', '/admin/forms/orcamento', $query, [], [], [], []);
        return (new FormController())->show($request, ['id' => 'orcamento'])->getBody();
    }
}

if (!function_exists('fpc_has_select')) {
    /** A página oferece o `<select>` de completude? */
    function fpc_has_select(string $html): bool
    {
        return str_contains($html, 'name="completeness"');
    }
}

if (!function_exists('fpc_selected')) {
    /** Qual opção do `<select>` está marcada; '' é o default "só completos". */
    function fpc_selected(string $html): ?string
    {
        if (preg_match('/<option value="([^"]*)" selected>/', $html, $m) === 1) {
            return $m[1];
        }
        return null;
    }
}

if (!function_exists('fpc_rows')) {
    /**
     * Os ids de submissão que a lista realmente RENDERIZOU, lidos pela URL do
     * formulário de exclusão — que é o `sid` sem o qual não há como apagar.
     * Ler o botão, e não uma contagem, é o que amarra a asserção ao desfecho
     * do achado: o dado ficou alcançável, não só contado.
     *
     * @return list<int>
     */
    function fpc_rows(string $html): array
    {
        preg_match_all('#/forms/orcamento/submissions/(\d+)/delete#', $html, $m);
        $ids = array_map('intval', $m[1]);
        sort($ids);
        return array_values(array_unique($ids));
    }
}

// ─────────────── o gate: quando o recorte é oferecido ───────────────

test('steps malformado: o <select> de completude continua na tela enquanto houver lead incompleto', function () {
    // A malformação do achado — `steps` virou string. formSteps() devolve []
    // em silêncio, e antes disso levava embora o acesso ao lead.
    $app = fpc_boot('nome,email', [[1, 2, null]]);

    check_same(false, $app->config->formHasSteps('orcamento'), 'pré-condição: a declaração parou de parsear');
    check_same(true, fpc_has_select(fpc_show($app)), 'sem o <select> não há como pedir o recorte');
});

test('steps malformado: ?completeness=all é HONRADO e o lead incompleto volta a ser apagável', function () {
    // O coração do achado. Sem a correção o gate forçava o valor de volta a
    // '' e a consulta saía com `AND pending_step IS NULL`: a linha sumia da
    // tela, e com ela o único `sid` que o delete aceita.
    $app = fpc_boot('nome,email', [[1, 2, null]]);
    $html = fpc_show($app, ['completeness' => 'all']);

    check_same('all', fpc_selected($html), 'o valor da query não pode ser forçado de volta ao default');
    check_same([1], fpc_rows($html), 'o lead incompleto tem que ter o próprio botão de apagar na página');
    check(str_contains($html, 'a@b.c'), 'e o dado pessoal dele tem que estar visível para quem decide apagar');
});

test('steps malformado: ?completeness=incomplete também é honrado', function () {
    $app = fpc_boot('nome,email', [[1, 2, null], [2, null, null]]);
    $html = fpc_show($app, ['completeness' => 'incomplete']);

    check_same('incomplete', fpc_selected($html));
    check_same([1], fpc_rows($html), 'só o que está em andamento');
});

test('steps malformado e NENHUM lead incompleto: o recorte some, como antes', function () {
    // A outra metade da regra do showSpamLink: o controle não aparece só
    // porque poderia. Sem etapas e sem linha incompleta ele não filtra nada,
    // e um filtro que nunca filtra é ruído em todo painel do mundo.
    $app = fpc_boot(null, [[1, null, null]]);
    $html = fpc_show($app, ['completeness' => 'all']);

    check_same(false, fpc_has_select($html), 'nada para recortar, nenhum controle');
    check_same([1], fpc_rows($html), 'e a lista normal segue mostrando o que sempre mostrou');
    check(!str_contains($html, __('forms.filtered_suffix')), 'o painel não pode dizer "· filtrado"');
});

test('formulário com steps VÁLIDO segue oferecendo o recorte mesmo sem lead incompleto', function () {
    // O caminho que já funcionava não pode passar a depender da contagem nova.
    $app = fpc_boot([['nome', 'email'], ['cep']], [[1, null, null]]);

    check_same(true, fpc_has_select(fpc_show($app)));
});

test('o default continua sendo "só completos" quando ninguém pede nada', function () {
    // O recorte seguro não muda: quem quer lead em andamento pede.
    $app = fpc_boot('nome,email', [[1, 2, null], [2, null, null]]);
    $html = fpc_show($app);

    check_same('', fpc_selected($html));
    check_same([2], fpc_rows($html), 'a lista padrão mostra só o concluído');
});

// ─────────────── o painel de spam pede 'all' explicitamente ───────────────

test('painel de spam: o lead bloqueado EM ANDAMENTO aparece na consulta padrão', function () {
    // Revisão final, seção 3. O markSpam() voltou a PRESERVAR o pending_step,
    // então existe linha bloqueada e incompleta ao mesmo tempo. O painel de
    // spam não pode depender de invariante nenhuma para mostrá-la: ele pede
    // 'all'. Sem isso volta o "badge diz 1, página diz nada" da Task 10.
    $app = fpc_boot([['nome', 'email'], ['cep']], [[1, 2, 'manual']]);
    $html = fpc_show($app, ['spam' => '1']);

    check_same([1], fpc_rows($html), 'a linha que o badge anuncia tem que estar na tela');
    check_same(false, fpc_has_select($html), 'e o controle nunca é oferecido ali');
});

test('painel de spam: nem uma query montada à mão consegue esconder a linha', function () {
    // `?spam=1&completeness=` era o recorte que sumia com ela. O 'all' é
    // fixo, então não há query capaz de reproduzir o desfecho antigo.
    $app = fpc_boot([['nome', 'email'], ['cep']], [[1, 2, 'manual']]);

    foreach (['', 'incomplete', 'lixo'] as $pedido) {
        check_same([1], fpc_rows(fpc_show($app, ['spam' => '1', 'completeness' => $pedido])), "spam + completeness={$pedido}");
    }
});

test('a lista NORMAL não passa a mostrar o que foi bloqueado', function () {
    // O 'all' fixo é do painel de spam, e só dele: o recorte de blocked_by
    // continua separando as duas listas. Sem esta asserção, um 'all' que
    // vazasse para a lista normal passaria despercebido.
    $app = fpc_boot([['nome', 'email'], ['cep']], [[1, 2, 'manual'], [2, null, null]]);

    check_same([2], fpc_rows(fpc_show($app, ['completeness' => 'all'])), 'bloqueada é outra lista');
});
