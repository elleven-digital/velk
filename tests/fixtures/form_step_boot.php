<?php

declare(strict_types=1);

/**
 * Boot compartilhado dos testes de formulário em etapas: App real + SQLite em
 * memória + sessão + i18n stub, um atalho para postar uma etapa, e o helper
 * de ambiente do Turnstile — que não é de etapas, mas tem exatamente o mesmo
 * problema de ordem e a mesma solução (ver o docblock dele).
 *
 * Vive em `tests/fixtures/` e não dentro de um `*_test.php` por causa da ordem
 * do `glob()`: `event_queue_optin_test.php` e `form_step_antispam_test.php`
 * vêm antes de `form_step_submit_test.php` no alfabeto, então um helper
 * definido lá não existiria quando eles rodassem. `run.php` só varre
 * `tests/unit/*_test.php`, então este arquivo nunca é carregado sozinho — quem
 * precisa dele faz `require_once`.
 *
 * O catálogo do i18n é pré-semeado vazio por reflexão para o `t()` do
 * `successPayload()` não tentar ler `theme_strings`, que o fixture não cria.
 */

use Velk\App;
use Velk\Config;
use Velk\Database;
use Velk\I18n;
use Velk\PublicFormController;
use Velk\Request;
use Velk\Session;

// t() vive em core/helpers.php, carregado sob demanda (não pelo autoloader,
// que só resolve classes) — mesmo require que subscribe_guard_test.php já
// faz. Sem isto, os três casos sem `steps` (que chegam a successPayload())
// morrem com "Call to undefined function Velk\t()": são os únicos, neste
// arquivo, cuja submissão sobrevive até ali.
require_once dirname(__DIR__, 2) . '/core/helpers.php';

if (!function_exists('fst_boot')) {
    /**
     * @param list<list<string>> $steps  as etapas; [] = formulário sem etapas
     * @param list<array<string,mixed>> $fields  a declaração de campos
     * @param array<string,mixed> $extra  chaves extras de `forms.<id>`, para
     *                                    o caso testar `keep_spam` e afins
     */
    function fst_boot(array $steps, array $fields, array $extra = []): App
    {
        $form = array_merge(
            ['label' => 'Orçamento', 'fields' => $fields, 'success_message' => 'Recebido.'],
            $extra
        );
        if ($steps !== []) {
            $form['steps'] = $steps;
        }

        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, []);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, [
            'forms' => ['orcamento' => $form],
            'i18n' => ['enabled' => false, 'default' => 'pt-BR'],
        ]);

        $app = new App($config);
        $app->basePath = '';

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("CREATE TABLE form_submissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT, form_id TEXT NOT NULL, blocked_by TEXT NULL,
            pending_step INTEGER NULL, step_token TEXT NULL, data TEXT NOT NULL,
            ip TEXT NULL, user_agent TEXT NULL, referer TEXT NULL,
            email_status TEXT NULL, email_error TEXT NULL, sent_to TEXT NULL, params TEXT NULL,
            created_at TEXT NOT NULL DEFAULT '2026-01-01 00:00:00'
        )");
        // Vazia de propósito: sem destinatário o dispatchEmail marca
        // 'skipped' e nenhum e-mail é montado.
        $pdo->exec("CREATE TABLE form_recipients (id INTEGER PRIMARY KEY AUTOINCREMENT, form_id TEXT, email TEXT, name TEXT NULL, created_at TEXT NULL)");
        $pdo->exec("CREATE TABLE form_settings (form_id TEXT PRIMARY KEY, user_data_fields TEXT NULL, conversion_snippet TEXT NULL, updated_at TEXT NULL)");

        $db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Database::class, 'pdo'))->setValue($db, $pdo);
        $app->db = $db;

        $i18nRef = new ReflectionClass(I18n::class);
        $i18n = $i18nRef->newInstanceWithoutConstructor();
        $i18nRef->getProperty('config')->setValue($i18n, $config);
        $i18nRef->getProperty('activeLanguage')->setValue($i18n, 'pt-BR');
        $i18nRef->getProperty('catalog')->setValue($i18n, []);
        $app->i18n = $i18n;

        $_SESSION = [];
        $app->session = new Session();
        return $app;
    }
}

if (!function_exists('fst_post')) {
    /**
     * Envia uma etapa. @return array{status:int, body:array}
     * @param array<string,mixed> $post  campos do formulário (sem _csrf)
     * @param array<string,mixed> $files o $_FILES da requisição, quando a
     *                                   etapa tem campo `file`
     */
    function fst_post(App $app, array $post, bool $json = true, array $files = []): array
    {
        $post['_csrf'] = $app->session->csrfToken();
        $server = ['REQUEST_METHOD' => 'POST', 'HTTP_HOST' => 'site.com', 'HTTP_REFERER' => 'https://site.com/orcamento'];
        if ($json) {
            $server['HTTP_ACCEPT'] = 'application/json';
        }
        $req = new Request('POST', '/forms/orcamento', [], $post, $files, $server, []);
        $res = (new PublicFormController())->submit($req, ['id' => 'orcamento']);
        return [
            'status' => $res->getStatus(),
            'body' => $json ? (array) json_decode($res->getBody(), true) : [],
        ];
    }
}

if (!function_exists('fst_steps')) {
    /** Três etapas: [nome,email] · [cep] · [budget]. */
    function fst_steps(): array
    {
        return [['nome', 'email'], ['cep'], ['budget']];
    }
}

if (!function_exists('fst_fields')) {
    function fst_fields(): array
    {
        return [
            ['name' => 'nome', 'type' => 'text', 'required' => true],
            ['name' => 'email', 'type' => 'email', 'required' => true],
            ['name' => 'cep', 'type' => 'text', 'required' => true],
            ['name' => 'budget', 'type' => 'text'],
        ];
    }
}

if (!function_exists('fst_with_turnstile_env')) {
    /**
     * Roda `$fn` com as chaves do Turnstile dadas e restaura o ambiente
     * depois; `null` apaga a variável.
     *
     * Não é helper de etapas, mas mora aqui pelo mesmo motivo que o resto do
     * arquivo: dois `*_test.php` precisam dele, e `run.php` só varre
     * `tests/unit/*_test.php` em ordem alfabética — definido em
     * `turnstile_service_test.php`, ainda não existiria quando
     * `form_step_antispam_test.php` rodasse. `tests/fixtures/` não é varrido,
     * então quem precisa faz `require_once` e a ordem do glob deixa de
     * importar. Uma cópia em cada arquivo resolveria o mesmo e é justamente
     * o que este diretório existe para não precisar.
     */
    function fst_with_turnstile_env(?string $site, ?string $secret, callable $fn): mixed
    {
        $keys = ['TURNSTILE_SITE_KEY' => $site, 'TURNSTILE_SECRET_KEY' => $secret];
        $prev = [];
        foreach ($keys as $k => $v) {
            $prev[$k] = getenv($k);
            if ($v === null) {
                putenv($k);
                unset($_ENV[$k]);
            } else {
                putenv("{$k}={$v}");
                $_ENV[$k] = $v;
            }
        }

        try {
            return $fn();
        } finally {
            foreach ($prev as $k => $v) {
                if ($v === false) { putenv($k); unset($_ENV[$k]); }
                else { putenv("{$k}={$v}"); $_ENV[$k] = $v; }
            }
        }
    }
}

if (!function_exists('fst_row')) {
    function fst_row(int $id): array
    {
        return (array) App::instance()->db->fetch('SELECT * FROM form_submissions WHERE id = ?', [$id]);
    }
}
