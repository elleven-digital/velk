<?php

declare(strict_types=1);

use Velk\Services\FormUploadService;

/**
 * FormUploadService — campos de arquivo em formulários públicos (ver
 * docs/superpowers/specs/2026-07-28-form-file-uploads-design.md). Cobre a
 * lógica pura/de arquivo com arquivos temporários REAIS (não mocka o
 * filesystem): whitelist própria, `accept`/`max_size` por campo,
 * containment de absolutePath() (path traversal — obrigatório), e delete()
 * não-fatal.
 *
 * Boot: mesma técnica de field_file_test.php — uma App real com Config real
 * (só lê config/env.php + site.json, nunca toca banco), pra
 * App::instance()->config->path('form_uploads') resolver pro storage/
 * de verdade do projeto. Os arquivos gravados durante os testes são
 * limpos no final deste arquivo (cleanup best-effort, roda mesmo se algum
 * teste individual falhar, já que cada test() é independente).
 */

// tests/run.php (via harness.php) já registrou o autoloader antes de
// carregar este arquivo. Só falta a App: reconstruir o singleton com a
// Config real do projeto (só lê config/env.php + site.json, nunca toca
// banco) é seguro mesmo que outro arquivo de teste já tenha feito o boot
// antes — mesma técnica de field_file_test.php.
new \Velk\App(new \Velk\Config(dirname(__DIR__, 2)));

/** Registro dos arquivos/paths criados pelos testes, pra limpeza no final. */
$GLOBALS['__fus_tmp_sources'] = [];
$GLOBALS['__fus_dest_relpaths'] = [];

if (!function_exists('fus_tmp_file')) {
    /** Grava $bytes num arquivo temporário real e devolve o caminho. */
    function fus_tmp_file(string $bytes): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'velk-fus-');
        file_put_contents($path, $bytes);
        $GLOBALS['__fus_tmp_sources'][] = $path;
        return $path;
    }
}

if (!function_exists('fus_uploaded')) {
    /**
     * Monta uma entrada no formato $_FILES[<campo>] apontando pro arquivo
     * temporário. `$sizeOverride` permite simular um Content-Length maior
     * que os bytes reais em disco (como o `size` reportado pelo PHP vem do
     * header multipart, não é recomputado do arquivo — testar o teto de
     * max_size não precisa escrever megabytes de verdade).
     */
    function fus_uploaded(string $tmpPath, string $originalName, ?int $sizeOverride = null): array
    {
        return [
            'name' => $originalName,
            'type' => 'application/octet-stream', // nunca confiado — store() detecta pelos bytes
            'tmp_name' => $tmpPath,
            'error' => UPLOAD_ERR_OK,
            'size' => $sizeOverride ?? (int) filesize($tmpPath),
        ];
    }
}

if (!function_exists('fus_expect_rejected')) {
    /**
     * Espera que store() rejeite com um CÓDIGO — não com uma frase.
     *
     * A frase daqui ia direto pro campo na tela do visitante, em português
     * cravado no PHP, sem passar por `t()`. Quem decide as palavras agora é
     * o tema; o serviço só diz o que houve.
     */
    function fus_expect_rejected(array $uploaded, array $fieldDef, string $context): void
    {
        try {
            FormUploadService::store($uploaded, $fieldDef);
            check(false, "esperava rejeição ({$context}) mas store() aceitou o arquivo");
        } catch (\Velk\Services\FormUploadException $e) {
            check($e->errorCode !== '', "código de erro vazio ({$context})");
        }
    }
}

if (!function_exists('fus_track_dest')) {
    /** Registra o `path` retornado por um store() bem-sucedido pra limpeza final. */
    function fus_track_dest(array $meta): array
    {
        $GLOBALS['__fus_dest_relpaths'][] = (string) $meta['path'];
        return $meta;
    }
}

// Bytes mínimos (só o cabeçalho já basta — MediaService::sniffMagic lê
// assinatura, getimagesize() falha graciosamente pra conteúdo não-imagem e
// cai pro sniff). Mesma técnica de tests/unit/media_service_mimes_test.php.
// define() (não const) porque o valor usa str_repeat(), que não é uma
// "constant expression" aceita por `const` no escopo top-level do arquivo.
define('FUS_PDF_BYTES', "%PDF-1.4\n%\xC3\xA2\xC3\xA3\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF");
define('FUS_PNG_HEADER', "\x89PNG\r\n\x1a\n" . "\x00\x00\x00\x0DIHDR" . str_repeat("\x00", 13));

// ─── 1. Extensão/MIME fora da whitelist → rejeita ──────────────────────

test('store: conteúdo não reconhecido (texto puro) é rejeitado', function () {
    $tmp = fus_tmp_file("isto é só um arquivo de texto qualquer, sem magic bytes de nada.");
    fus_expect_rejected(
        fus_uploaded($tmp, 'notas.txt'),
        ['name' => 'anexo'],
        'texto puro'
    );
});

test('store: tipo reconhecido mas fora da whitelist (vídeo) é rejeitado — "sem vídeo e sem áudio"', function () {
    // ftyp/isom → video/mp4 pro MediaService::detectMime (reconhecido e
    // permitido no admin), mas FormUploadService não inclui vídeo.
    $tmp = fus_tmp_file("\x00\x00\x00\x20ftyp" . 'isom' . str_repeat("\x00", 20));
    fus_expect_rejected(
        fus_uploaded($tmp, 'video.mp4'),
        ['name' => 'anexo'],
        'video/mp4 excluído da whitelist pública'
    );
});

// ─── 2. accept do campo restringe além da whitelist → rejeita ──────────

test('store: accept do campo restringe além da whitelist geral (PNG com accept=[pdf])', function () {
    $tmp = fus_tmp_file(FUS_PNG_HEADER);
    fus_expect_rejected(
        fus_uploaded($tmp, 'foto.png'),
        ['name' => 'anexo', 'accept' => ['pdf']],
        'png fora do accept do campo (só pdf)'
    );
});

test('store: accept do campo aceita quando a extensão está na lista', function () {
    $tmp = fus_tmp_file(FUS_PNG_HEADER);
    $meta = fus_track_dest(FormUploadService::store(
        fus_uploaded($tmp, 'foto.png'),
        ['name' => 'anexo', 'accept' => ['pdf', 'png']]
    ));
    check_same('image/png', $meta['mime']);
});

test('store: entrada de accept fora da whitelist geral é ignorada (não amplia, não quebra)', function () {
    // "exe" não existe em ALLOWED_MIMES — normalizeAccept descarta;
    // resultado é accept efetivo = ['pdf'], então o PNG segue rejeitado.
    $tmp = fus_tmp_file(FUS_PNG_HEADER);
    fus_expect_rejected(
        fus_uploaded($tmp, 'foto.png'),
        ['name' => 'anexo', 'accept' => ['pdf', 'exe']],
        'accept com entrada inválida (exe) não amplia a whitelist'
    );
});

// ─── 3. Acima do max_size do campo → rejeita ────────────────────────────

test('store: acima do max_size DO CAMPO (mesmo dentro do teto default de 10MB) é rejeitado', function () {
    $tmp = fus_tmp_file(FUS_PDF_BYTES);
    // Bytes reais são minúsculos; simula um upload de 6MB via o `size` do
    // $_FILES (é isso que o PHP reporta de verdade — o header multipart,
    // não uma nova leitura do arquivo) contra um campo com max_size=5.
    fus_expect_rejected(
        fus_uploaded($tmp, 'grande.pdf', 6 * 1024 * 1024),
        ['name' => 'anexo', 'max_size' => 5],
        'size=6MB > max_size=5'
    );
});

test('store: dentro do max_size do campo é aceito', function () {
    $tmp = fus_tmp_file(FUS_PDF_BYTES);
    $meta = fus_track_dest(FormUploadService::store(
        fus_uploaded($tmp, 'pequeno.pdf', 4 * 1024 * 1024),
        ['name' => 'anexo', 'max_size' => 5]
    ));
    check_same('application/pdf', $meta['mime']);
});

test('store: acima do teto DEFAULT (10MB) quando o campo não declara max_size é rejeitado', function () {
    $tmp = fus_tmp_file(FUS_PDF_BYTES);
    fus_expect_rejected(
        fus_uploaded($tmp, 'grande.pdf', 11 * 1024 * 1024),
        ['name' => 'anexo'], // sem max_size → default 10MB
        'size=11MB > default 10MB'
    );
});

// ─── 4. Caso bom: grava, metadados corretos, arquivo no destino, nome é token ─

test('store: caso bom — grava no destino, metadados corretos, nome em disco é um token (não o original)', function () {
    $tmp = fus_tmp_file(FUS_PDF_BYTES);
    $meta = fus_track_dest(FormUploadService::store(
        fus_uploaded($tmp, 'Currículo Final (2026).pdf'),
        ['name' => 'curriculo']
    ));

    check_same(true, $meta['_file']);
    check_same('Currículo Final (2026).pdf', $meta['name'], 'name preserva o original, não desliza pro slug');
    check_same('application/pdf', $meta['mime']);
    check_same(strlen(FUS_PDF_BYTES), $meta['size']);

    $shard = date('Y/m');
    check(str_starts_with($meta['path'], $shard . '/'), "path deveria começar com {$shard}/, veio {$meta['path']}");
    check(str_ends_with($meta['path'], '.pdf'), 'extensão do path é .pdf');

    $token = basename($meta['path'], '.pdf');
    check_same(32, strlen($token), 'nome em disco tem 32 caracteres (16 bytes em hex)');
    check(preg_match('/^[0-9a-f]{32}$/', $token) === 1, 'nome em disco é hex puro — não é "curriculo" nem o original');

    $base = \Velk\App::instance()->config->path('form_uploads');
    $absPath = $base . '/' . $meta['path'];
    check(is_file($absPath), 'arquivo existe fisicamente no destino');
    check_same(FUS_PDF_BYTES, (string) file_get_contents($absPath), 'conteúdo gravado bate com o enviado');
});

// ─── SVG: exige opt-in explícito; quando aceito, é sanitizado ────────────────

test('store: SVG é REJEITADO quando o campo não declara accept (seguro por padrão)', function () {
    // Regra de produto: SVG é XML executável e quem envia aqui é anônimo, então
    // um formulário que não pediu SVG não deve receber um por acidente.
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>';
    $tmp = fus_tmp_file($svg);
    fus_expect_rejected(fus_uploaded($tmp, 'icone.svg'), ['name' => 'anexo'], 'svg sem opt-in');
});

test('store: SVG com accept: ["svg"] é aceito e tem o <script> removido no arquivo salvo', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="1" height="1"/></svg>';
    $tmp = fus_tmp_file($svg);
    $meta = fus_track_dest(FormUploadService::store(
        fus_uploaded($tmp, 'icone.svg'),
        ['name' => 'anexo', 'accept' => ['svg']]
    ));
    check_same('image/svg+xml', $meta['mime']);

    $base = \Velk\App::instance()->config->path('form_uploads');
    $saved = (string) file_get_contents($base . '/' . $meta['path']);
    check(!str_contains($saved, '<script'), 'script removido do SVG salvo em disco');
    check(str_contains($saved, '<rect'), 'conteúdo legítimo do SVG preservado');
});

test('store: SVG malformado (XML inválido) é rejeitado mesmo com opt-in', function () {
    $tmp = fus_tmp_file('<svg xmlns="http://www.w3.org/2000/svg"><rect>não fecha');
    fus_expect_rejected(
        fus_uploaded($tmp, 'quebrado.svg'),
        ['name' => 'anexo', 'accept' => ['svg']],
        'svg malformado'
    );
});

// ─── 5. absolutePath(): path traversal → null (obrigatório) ────────────

test('absolutePath: caminho válido de um arquivo recém-gravado resolve pro absoluto correto', function () {
    $tmp = fus_tmp_file(FUS_PDF_BYTES);
    $meta = fus_track_dest(FormUploadService::store(
        fus_uploaded($tmp, 'ok.pdf'),
        ['name' => 'anexo']
    ));

    $resolved = FormUploadService::absolutePath($meta['path']);
    check($resolved !== null, 'path válido não deveria resolver pra null');
    check(is_file((string) $resolved), 'resolvido aponta pra um arquivo real');
    check(str_ends_with((string) $resolved, $meta['path']), 'resolvido termina com o relpath gravado');
});

test('absolutePath: "../" (path traversal) sempre retorna null — mesmo com a base já populada', function () {
    check_same(null, FormUploadService::absolutePath('../../../../../../../../etc/passwd'));
    check_same(null, FormUploadService::absolutePath('../config/env.php'));
    check_same(null, FormUploadService::absolutePath('2026/08/../../../config/env.php'));
    check_same(null, FormUploadService::absolutePath('..'));
});

test('absolutePath: caminho fora de form_uploads sem literal ".." (ex.: absoluto de outro diretório) retorna null', function () {
    // Sem ".." no valor, mas fora da base: o join vira um caminho que não
    // existe sob form_uploads/ (realpath falha) OU, se por acaso existisse,
    // o containment por prefixo barraria. Aqui garantimos o caso comum:
    // caminho inexistente sob a base.
    check_same(null, FormUploadService::absolutePath('/etc/passwd'));
    check_same(null, FormUploadService::absolutePath('2026/08/nao-existe-' . bin2hex(random_bytes(8)) . '.pdf'));
});

test('absolutePath: vazio retorna null', function () {
    check_same(null, FormUploadService::absolutePath(''));
});

// ─── 6. delete(): remove e é não-fatal em arquivo ausente ──────────────

test('delete: remove um arquivo existente do disco', function () {
    $tmp = fus_tmp_file(FUS_PDF_BYTES);
    $meta = FormUploadService::store(fus_uploaded($tmp, 'apagar.pdf'), ['name' => 'anexo']);
    // NÃO registra em __fus_dest_relpaths — o próprio teste é a exclusão.

    $base = \Velk\App::instance()->config->path('form_uploads');
    $absPath = $base . '/' . $meta['path'];
    check(is_file($absPath), 'sanity: arquivo existe antes do delete');

    FormUploadService::delete($meta['path']);
    check(!is_file($absPath), 'arquivo não existe mais depois do delete');
});

test('delete: arquivo ausente/path inválido é não-fatal (não lança)', function () {
    FormUploadService::delete('2026/08/' . bin2hex(random_bytes(16)) . '.pdf'); // nunca existiu
    FormUploadService::delete('../../etc/passwd'); // traversal — absolutePath já barra
    FormUploadService::delete(''); // vazio
    check(true, 'delete() completou sem lançar pra nenhum dos três casos');
});

// ─── Bônus: humanSize() — formatação usada nos pontos de renderização ──

test('humanSize: formata B/KB/MB na mesma convenção de Media::humanSize()', function () {
    check_same('500 B', FormUploadService::humanSize(500));
    check_same('1.0 KB', FormUploadService::humanSize(1024));
    check_same('245.3 KB', FormUploadService::humanSize((int) round(245.3 * 1024)));
    check_same('2.0 MB', FormUploadService::humanSize(2 * 1024 * 1024));
});

// ─── Limpeza — best-effort, roda incondicionalmente (fora de qualquer
// test()), mesmo que algum teste acima tenha falhado no meio do caminho ──

foreach ($GLOBALS['__fus_tmp_sources'] as $src) {
    @unlink($src);
}

$formUploadsBase = \Velk\App::instance()->config->path('form_uploads');
$monthsTouched = [];
foreach ($GLOBALS['__fus_dest_relpaths'] as $rel) {
    @unlink($formUploadsBase . '/' . $rel);
    $monthsTouched[dirname($rel)] = true;
}
// Remove os diretórios YYYY/MM criados pelo teste SE ficaram vazios — não
// mexe no diretório base (tem .gitkeep versionado) nem em nada que não
// tenha sido esvaziado por completo aqui (@rmdir não-fatal, silencioso).
foreach (array_keys($monthsTouched) as $monthDir) {
    @rmdir($formUploadsBase . '/' . $monthDir);
    @rmdir($formUploadsBase . '/' . dirname($monthDir));
}
