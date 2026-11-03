<?php

declare(strict_types=1);

use Velk\Admin\Controllers\AuthController;
use Velk\Admin\Controllers\FormController;
use Velk\App;
use Velk\Request;
use Velk\Response;

/**
 * O bloco "Exportar" e a rota que ele chama, de ponta a ponta: App, Config,
 * Auth e i18n reais, SQLite em memória — montados pelo boot compartilhado
 * dos testes da exportação —, o `FormController` inteiro rodando e as views
 * de verdade renderizadas, no molde do `form_panel_completeness_test.php`.
 *
 * As regras do CSV têm testes próprios (form_export_*_test.php). O que só se
 * prova aqui é a costura: a tela oferece o que a rota entende, a rota exporta
 * só leads reais, e os três desfechos que não são arquivo — sem sessão,
 * formulário inexistente, nenhuma coluna — respondem o que devem.
 */

// admin_url()/base_path(), chamados pelo controller, são helpers globais de
// core/helpers.php e não vêm pelo autoloader de classes.
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__) . '/fixtures/form_export_boot.php';

if (!function_exists('fexp_boot')) {
    /**
     * Um painel com o formulário `contato` e cinco submissões: três leads
     * reais (ids 1, 2 e 5), um spam (3) e um em andamento (4).
     */
    function fexp_boot(bool $logged = true): App
    {
        $app = fexb_boot(
            ['label' => 'Contato', 'keep_spam' => true, 'fields' => [
                ['name' => 'nome', 'label' => 'Nome', 'type' => 'text', 'required' => true],
                ['name' => 'email', 'label' => 'E-mail', 'type' => 'email', 'required' => true],
            ]],
            [
                [1, null, null, '{"nome":"Ana","email":"ana@x.com"}', null, '2026-01-10 09:00:00'],
                [2, null, null, '{"nome":"=HYPERLINK(1)","email":"bia@x.com"}', '{"utm_source":"google"}', '2026-02-10 09:00:00'],
                [3, 'honeypot', null, '{"nome":"Bot","email":"bot@x.com"}', null, '2026-02-11 09:00:00'],
                [4, null, 2, '{"nome":"Caio","email":"caio@x.com"}', null, '2026-02-12 09:00:00'],
                [5, null, null, '{"nome":"Duda","email":"duda@x.com"}', null, '2026-03-10 09:00:00'],
            ]
        );
        fexb_admin($app, $logged);
        return $app;
    }
}

if (!function_exists('fexp_show')) {
    /** @param array<string,string> $query */
    function fexp_show(array $query = []): string
    {
        $request = new Request('GET', '/admin/forms/contato', $query, [], [], [], []);
        return (new FormController())->show($request, ['id' => 'contato'])->getBody();
    }
}

if (!function_exists('fexp_export')) {
    /** @param array<string,mixed> $query */
    function fexp_export(array $query = [], string $formId = 'contato'): Response
    {
        $request = new Request('GET', '/admin/forms/' . $formId . '/export', $query, [], [], [], []);
        return (new FormController())->export($request, ['id' => $formId]);
    }
}

if (!function_exists('fexp_body')) {
    /**
     * O corpo que o `stream()` emitiria. O streamer é privado e `send()`
     * mexeria em headers depois de o runner já ter escrito na saída — então
     * o teste chama o callback direto e captura o `php://output`.
     */
    function fexp_body(Response $response): string
    {
        $streamer = (new ReflectionProperty(Response::class, 'streamer'))->getValue($response);
        check($streamer instanceof Closure, 'a exportação responde em streaming');
        ob_start();
        $streamer();
        return (string) ob_get_clean();
    }
}

if (!function_exists('fexp_rows')) {
    /** @return list<list<string>> as linhas do CSV, sem o BOM */
    function fexp_rows(string $csv, string $sep = ';'): array
    {
        $csv = str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv;
        $rows = [];
        foreach (explode("\n", rtrim($csv, "\n")) as $line) {
            $rows[] = str_getcsv($line, $sep, '"', '');
        }
        return $rows;
    }
}

// ─────────────── a tela ───────────────

test('tela: o bloco Exportar oferece um checkbox por campo, marcado, e os dois grupos, desmarcados', function () {
    fexp_boot();
    $html = fexp_show();

    check(str_contains($html, 'action="/admin/forms/contato/export"'), 'o formulário aponta para a rota de exportação');
    check(str_contains($html, '<input type="hidden" name="p" value="1">'), 'o marcador que separa "desmarquei tudo" do link nu');
    foreach (['nome', 'email'] as $col) {
        check(
            str_contains($html, '<input type="checkbox" name="cols[]" value="' . $col . '" checked>'),
            "checkbox marcado para {$col}"
        );
    }
    // Metadados e rastreamento são opcionais: vêm desmarcados.
    foreach (['@meta', '@params'] as $col) {
        check(
            str_contains($html, '<input type="checkbox" name="cols[]" value="' . $col . '">'),
            "checkbox desmarcado para {$col}"
        );
    }
    check(str_contains($html, '<option value=";" selected>'), 'ponto e vírgula por padrão');
    // A legenda sai da mesma lista que o cabeçalho: se os grupos da view
    // divergissem do FormCsv::columns(), ela diria "Metadata: . Tracking: 0".
    check(
        str_contains($html, 'Metadata: ID, Date, IP, Browser, Referrer, Email status, Sent to. Tracking: '
            . count(\Velk\Params::WHITELIST) . ' UTM and click ID columns.'),
        'a legenda nomeia os metadados e conta as colunas de rastreamento'
    );
});

test('tela: no painel de spam o bloco Exportar não aparece', function () {
    // A exportação leva só leads reais — ali não há o que exportar.
    fexp_boot();
    check(!str_contains(fexp_show(['spam' => '1']), '/forms/contato/export'));
});

// ─────────────── a rota ───────────────

test('rota: link nu exporta os campos do formulário, só os leads reais, do mais novo', function () {
    fexp_boot();
    $response = fexp_export();

    check_same(200, $response->getStatus());
    check_same('text/csv; charset=UTF-8', $response->getHeader('Content-Type'));
    check_same('private, no-store', $response->getHeader('Cache-Control'), 'o arquivo é dado pessoal');
    check(
        str_starts_with((string) $response->getHeader('Content-Disposition'), 'attachment; filename="contato-' . date('Y-m-d') . '.csv"'),
        (string) $response->getHeader('Content-Disposition')
    );

    $csv = fexp_body($response);
    check(str_starts_with($csv, "\xEF\xBB\xBF"), 'com BOM, para o Excel ler os acentos');
    $rows = fexp_rows($csv);
    check_same(['Nome', 'E-mail'], $rows[0], 'o padrão: só os campos, sem metadados nem rastreamento');
    // Spam (3) e em andamento (4) ficam de fora; o resto, do mais novo (5, 2, 1).
    check_same(['duda@x.com', 'bia@x.com', 'ana@x.com'], array_column(array_slice($rows, 1), 1));
    check_same("'=HYPERLINK(1)", $rows[2][0], 'o escape de fórmula chega ao arquivo');
});

test('rota: colunas, separador e período escolhidos', function () {
    fexp_boot();
    $csv = fexp_body(fexp_export([
        'p' => '1', 'cols' => ['email', '@params'], 'sep' => ',',
        'date_from' => '2026-02-01', 'date_to' => '2026-02-28',
    ]));
    $rows = fexp_rows($csv, ',');

    check_same(array_merge(['E-mail'], \Velk\Params::WHITELIST), $rows[0]);
    check_same(2, count($rows), 'cabeçalho + o único lead real de fevereiro');
    check_same('bia@x.com', $rows[1][0]);
    check_same('google', $rows[1][array_search('utm_source', $rows[0], true)]);
});

test('rota: formulário enviado sem nenhuma coluna volta para a tela com erro', function () {
    fexp_boot();
    $response = fexp_export(['p' => '1', 'sep' => ';']);
    check_same(302, $response->getStatus());
    check_same('/admin/forms/contato', $response->getHeader('Location'));
    check_same(__('forms.export_no_columns'), $_SESSION['_flash']['error'] ?? null, 'o flash diz o que faltou');
});

test('rota: link salvo cujas colunas sumiram do site.json também é erro, não arquivo vazio', function () {
    fexp_boot();
    $response = fexp_export(['cols' => ['campo_que_sumiu']]);
    check_same(302, $response->getStatus());
    check_same('/admin/forms/contato', $response->getHeader('Location'));
    check_same(__('forms.export_no_columns'), $_SESSION['_flash']['error'] ?? null);
});

test('rota: data inválida, de ou até, é ignorada (só chega de URL montada à mão)', function () {
    fexp_boot();
    $rows = fexp_rows(fexp_body(fexp_export(['date_from' => 'ontem', 'cols' => ['email']])));
    check_same(4, count($rows), 'cabeçalho + os três leads reais: o filtro inválido não recortou nada');
    $rows = fexp_rows(fexp_body(fexp_export(['date_to' => '01/12/2026', 'cols' => ['email']])));
    // 01/12/2026 e não 31/12: sem a validação, texto comparado com texto,
    // '2026-…' <= '01/12…' é falso e o recorte levaria tudo — é o que
    // prova que a data inválida foi ignorada, e não só inofensiva.
    check_same(4, count($rows), 'o mesmo com a data final');
});

test('rota: período sem leads baixa só o cabeçalho', function () {
    // O recorte vazio é um arquivo válido, e não um erro: o cliente que pede
    // a semana sem leads recebe a planilha com as colunas e nenhuma linha.
    fexp_boot();
    check_same("\xEF\xBB\xBFE-mail\n", fexp_body(fexp_export(['cols' => ['email'], 'date_from' => '2030-01-01'])));
});

test('rota: separador fora dos dois cai no ponto e vírgula', function () {
    fexp_boot();
    $rows = fexp_rows(fexp_body(fexp_export(['cols' => ['nome', 'email'], 'sep' => '|'])), ';');
    check_same(['Nome', 'E-mail'], $rows[0], 'as duas colunas separadas por ponto e vírgula, não por |');
});

test('rota: banco fora do ar é erro antes da resposta, não um download com a página de erro dentro', function () {
    // A primeira consulta roda antes de montar a resposta: dentro do stream()
    // ela rodaria depois do 200 com cabeçalho de CSV, e o 500 sairia colado
    // no arquivo baixado.
    $app = fexp_boot();
    $app->db->pdo()->exec('DROP TABLE form_submissions');
    $threw = false;
    try {
        fexp_export();
    } catch (PDOException $e) {
        $threw = true;
    }
    check($threw, 'a primeira consulta tem de rodar, e falhar, antes da resposta de download');
});

test('rota: registrada como GET em forms/{id}/export', function () {
    // GET, como o download de anexo: é leitura, e a URL é a exportação salva.
    // Como POST ela passaria a exigir CSRF, e o link salvo deixaria de abrir.
    $src = file_get_contents(dirname(__DIR__, 2) . '/core/Admin/AdminRouter.php');
    check(
        str_contains($src, "\$router->get(\$prefix . '/forms/{id}/export', [FormController::class, 'export']);"),
        'rota de exportação ausente ou não é GET'
    );
});

test('rota: formulário que não existe é 404', function () {
    fexp_boot();
    check_same(404, fexp_export([], 'nao-existe')->getStatus());
});

test('rota: sem sessão vai para o login — e o login devolve para as mesmas escolhas', function () {
    fexp_boot(false);
    $choices = ['p' => '1', 'cols' => ['email'], 'date_from' => '2026-02-01', 'sep' => ','];
    $response = fexp_export($choices);
    check_same(302, $response->getStatus());
    check_same('/admin/login', $response->getHeader('Location'));
    // Um link de exportação salvo, aberto com a sessão vencida, tem de voltar
    // depois do login com o mesmo recorte — e não como "todas as colunas,
    // todas as datas".
    $intended = (string) ($_SESSION['intended'] ?? '');
    check(str_starts_with($intended, '/admin/forms/contato/export?'), $intended);
    parse_str((string) parse_url($intended, PHP_URL_QUERY), $back);
    check_same($choices, $back);

    // E a outra metade: depois da senha, o login redireciona para o endereço
    // guardado — com a query. O login() inteiro não roda no SQLite (o
    // limitador de tentativas usa DATE_SUB, só do MySQL), então o teste chama
    // direto o passo final dele, o redirectToIntended(), que é privado.
    $login = (new ReflectionMethod(AuthController::class, 'redirectToIntended'))->invoke(new AuthController());
    $location = (string) $login->getHeader('Location');
    check_same('/admin/forms/contato/export', parse_url($location, PHP_URL_PATH), $location);
    parse_str((string) parse_url($location, PHP_URL_QUERY), $after);
    check_same($choices, $after, 'o redirect depois do login leva as escolhas junto');
});

test('rota: sem sessão, um POST guarda para depois do login só o caminho', function () {
    // A query só volta junto num GET: o redirect depois do login é sempre um
    // GET, e refazer um POST como GET, com os campos na URL, seria outra
    // requisição. A rota de exportação é só o veículo — a regra é do
    // requireAuth(), e vale para todo o painel.
    fexp_boot(false);
    $request = new Request('POST', '/admin/forms/contato/export', ['p' => '1', 'cols' => ['email']], [], [], [], []);
    check_same(302, (new FormController())->export($request, ['id' => 'contato'])->getStatus());
    check_same('/admin/forms/contato/export', $_SESSION['intended'] ?? null);
});
