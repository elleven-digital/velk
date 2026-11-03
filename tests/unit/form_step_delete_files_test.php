<?php

declare(strict_types=1);

use Velk\App;
use Velk\Config;
use Velk\Database;
use Velk\Models\FormSubmission;

/**
 * Apagar submissão tem que levar o arquivo junto (spec 2026-09-09 §8).
 *
 * O delete do painel apagava os uploads na mão antes de remover a linha; o
 * `cleanup:spam-submissions` NÃO — fazia um DELETE cru e deixava o arquivo
 * órfão em storage/form-uploads/. Um lead incompleto pode perfeitamente ter
 * arquivo (campo `file` na etapa 1), então o comando novo herdaria o
 * vazamento se copiasse aquele.
 *
 * Um caminho só, usado pelos três pontos, é o que impede a terceira cópia de
 * nascer errada.
 */

if (!function_exists('fsd_app')) {
    function fsd_app(): App
    {
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        // `paths.form_uploads` precisa apontar pro storage/ real (mesmo
        // diretório que fsd_upload()/fsd_exists() usam) — é dali que
        // FormUploadService::absolutePath() resolve o path gravado. Com
        // `env` vazio, Config::path('form_uploads') volta "" e delete()
        // não acha o arquivo pra apagar: não-fatal por design, então o
        // teste passaria "verde" sem ter provado que o arquivo saiu do
        // disco — o mesmo risco que o docblock de fsd_upload() já evita
        // do lado da criação do arquivo.
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, [
            'paths' => ['form_uploads' => dirname(__DIR__, 2) . '/storage/form-uploads'],
        ]);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, []);
        $app = new App($config);

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

        $db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Database::class, 'pdo'))->setValue($db, $pdo);
        $app->db = $db;
        return $app;
    }
}

if (!function_exists('fsd_upload')) {
    /**
     * Cria um arquivo de verdade dentro do diretório de uploads de formulário
     * e devolve o `path` relativo que o metadado guardaria.
     *
     * Precisa ser dentro do diretório real porque `FormUploadService::delete()`
     * resolve o caminho a partir dele — um path inventado simplesmente não
     * apaga nada, e o teste passaria sem provar coisa alguma.
     */
    function fsd_upload(): string
    {
        $base = dirname(__DIR__, 2) . '/storage/form-uploads/2026/09';
        if (!is_dir($base)) { mkdir($base, 0775, true); }
        $name = 'fsd-' . bin2hex(random_bytes(8)) . '.pdf';
        file_put_contents($base . '/' . $name, 'x');
        return '2026/09/' . $name;
    }
}

if (!function_exists('fsd_exists')) {
    function fsd_exists(string $path): bool
    {
        return is_file(dirname(__DIR__, 2) . '/storage/form-uploads/' . $path);
    }
}

if (!function_exists('fsd_cleanup')) {
    /**
     * Apaga um arquivo de teste criado por fsd_upload().
     *
     * Necessário quando o teste prova que o arquivo SOBREVIVE, ao contrário
     * do teste que prova a remoção (deleteWithFiles apaga direto como efeito
     * colateral). Sem limpeza manual, cada rodada deixa um arquivo órfão em
     * storage/form-uploads/, invisível ao git status porque storage/ é
     * gitignored — o leak cresce automaticamente.
     */
    function fsd_cleanup(string $path): void
    {
        @unlink(dirname(__DIR__, 2) . '/storage/form-uploads/' . $path);
    }
}

test('deleteWithFiles: apaga a linha E o arquivo do disco', function () {
    fsd_app();
    $path = fsd_upload();
    check_same(true, fsd_exists($path), 'o fixture tinha que ter criado o arquivo');

    $s = FormSubmission::create(['form_id' => 'x', 'data' => [
        'nome' => 'Ana',
        'anexo' => ['_file' => true, 'path' => $path, 'name' => 'cv.pdf'],
    ]]);
    $s->deleteWithFiles();

    check_same(null, FormSubmission::find($s->id), 'a linha tinha que sair');
    check_same(false, fsd_exists($path), 'o arquivo tinha que sair do disco — é o vazamento que existia');
});

test('deleteWithFiles: submissão sem arquivo apaga normal', function () {
    fsd_app();
    $s = FormSubmission::create(['form_id' => 'x', 'data' => ['nome' => 'Ana']]);
    $s->deleteWithFiles();
    check_same(null, FormSubmission::find($s->id));
});

test('deleteWithFiles: valor de array SEM o marcador _file não é tratado como arquivo', function () {
    // Um campo pode guardar array por outro motivo; só o marcador `_file`
    // autoriza mexer no disco. Um caminho para arquivo que DE VERDADE EXISTE
    // é essencial aqui: sem FormUploadService::delete() nunca teria coisa
    // pra apagar, e a mutação que remove a checagem `!empty($value['_file'])`
    // não mataria o teste — o mesmo risco que o docblock de fsd_upload() já
    // evita do lado da criação do arquivo.
    fsd_app();
    $path = fsd_upload();
    check_same(true, fsd_exists($path), 'o fixture tinha que ter criado o arquivo');

    $s = FormSubmission::create(['form_id' => 'x', 'data' => ['algo' => ['path' => $path]]]);
    $s->deleteWithFiles();

    check_same(null, FormSubmission::find($s->id), 'a linha tinha que sair');
    check_same(true, fsd_exists($path), 'o arquivo tinha que estar de pé — só `_file` marca pro delete');

    // Diferente do teste 1, que prova apagar (e limpa sozinho), este prova
    // que o arquivo SOBREVIVE — precisa deletar na mão ou acumula um arquivo
    // por rodada de testes, invisível ao git status.
    fsd_cleanup($path);
});
