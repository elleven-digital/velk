<?php

declare(strict_types=1);

use Velk\App;
use Velk\Config;

/**
 * Envio recusado — ou etapa reenviada — NÃO deixa arquivo no disco.
 *
 * (spec 2026-09-09 §5, mais o achado 2 da revisão final.)
 *
 * `FormUploadService::store()` roda dentro do `validate()`, ou seja, o
 * arquivo já foi para o armazenamento definitivo antes das checagens que
 * ainda podem recusar o envio. São duas: `validation_failed` (outro campo do
 * mesmo POST reprovou) e `incomplete_lead` (o lead acumulado não tem todos os
 * obrigatórios). A segunda nasceu com as etapas e não limpava nada.
 *
 * Não é caso de laboratório: nada valida que `steps` cubra todo campo
 * obrigatório, então um obrigatório que ficou de fora das etapas derruba a
 * etapa final em `incomplete_lead` TODA vez — com um campo `file` na etapa
 * final, cada tentativa da mesma visitante gravava um arquivo que ninguém
 * referencia e ninguém apaga.
 *
 * O terceiro caso não é envio recusado, e é o que faltava: a etapa que
 * SUBSTITUI um arquivo já gravado. `mergeStep()` fazia `array_merge`, o
 * metadado `_file` antigo era descartado calado, e o arquivo em disco ficava
 * sem ninguém que o referenciasse — `deleteWithFiles()` percorre o `data`, e
 * o `data` já não fala dele. O órfão sobrevivia ao apagar do painel e aos
 * dois `cleanup:*`. Não é disco: é um currículo ou um documento com foto que
 * sobrevive à exclusão do lead a que pertencia.
 *
 * Os arquivos vão para um diretório temporário PRÓPRIO, não para
 * `storage/form-uploads/`: o teste tem que poder varrer o diretório inteiro e
 * apagá-lo, e um teste que vaza dentro de `storage/` não aparece no
 * `git status` (é gitignored) — já aconteceu nesta branch. A varredura roda
 * ANTES das asserções de propósito: `test()` captura a exceção da primeira
 * asserção que falha, então limpar depois delas seria limpar só quando passa.
 */

require_once dirname(__DIR__) . '/fixtures/form_step_boot.php';

if (!function_exists('fsu_root')) {
    /** Raiz descartável do teste: `uploads/` (destino) e `tmp/` (origem). */
    function fsu_root(): string
    {
        $root = sys_get_temp_dir() . '/velk-fsu-' . bin2hex(random_bytes(6));
        mkdir($root . '/uploads', 0775, true);
        mkdir($root . '/tmp', 0775, true);
        return $root;
    }
}

if (!function_exists('fsu_app')) {
    /**
     * App do fixture com `paths.form_uploads` apontando para a raiz do teste.
     *
     * O `fst_boot()` deixa `env` vazio, e aí `path('form_uploads')` volta ""
     * — o `store()` tentaria gravar em `/YYYY/MM` e falharia com
     * `store_failed`, que vira `validation_failed`: o teste passaria sem
     * nunca ter chegado ao ramo que quer provar.
     *
     * @param list<list<string>>        $steps
     * @param list<array<string,mixed>> $fields
     */
    function fsu_app(string $root, array $steps, array $fields): App
    {
        $app = fst_boot($steps, $fields);
        (new ReflectionProperty(Config::class, 'env'))->setValue($app->config, [
            'paths' => ['form_uploads' => $root . '/uploads'],
        ]);
        return $app;
    }
}

if (!function_exists('fsu_files')) {
    /**
     * Um `$_FILES` com PDF de verdade: o `store()` detecta o MIME pelos magic
     * bytes, então um arquivo de conteúdo inventado seria recusado antes de
     * gravar — e o teste provaria nada.
     *
     * @return array<string,array<string,mixed>>
     */
    function fsu_files(string $root, string $field): array
    {
        $tmp = $root . '/tmp/' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($tmp, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");

        return [$field => [
            'name'     => 'briefing.pdf',
            'type'     => 'application/pdf',
            'tmp_name' => $tmp,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($tmp),
        ]];
    }
}

if (!function_exists('fsu_list')) {
    /**
     * Os arquivos que existem em `uploads/` AGORA, sem apagar nada — para os
     * casos que precisam olhar o disco no meio do fluxo e continuar.
     *
     * @return list<string> caminhos relativos a `uploads/`
     */
    function fsu_list(string $root): array
    {
        $base = $root . '/uploads';
        $sobrou = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            if ($item->isFile()) {
                $sobrou[] = substr($item->getPathname(), strlen($base) + 1);
            }
        }
        sort($sobrou);
        return $sobrou;
    }
}

if (!function_exists('fsu_sweep')) {
    /**
     * Os arquivos que sobraram em `uploads/`, e apaga a raiz inteira.
     *
     * @return list<string> caminhos relativos a `uploads/`
     */
    function fsu_sweep(string $root): array
    {
        $sobrou = fsu_list($root);
        fsu_rm($root);
        return $sobrou;
    }
}

if (!function_exists('fsu_rm')) {
    function fsu_rm(string $dir): void
    {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}

test('etapa final: incomplete_lead não deixa o arquivo que o validate() acabou de gravar', function () {
    // `telefone` é obrigatório e não está em `steps` — nenhuma etapa o valida,
    // e o missingRequired do envio final o cobra. É o caminho que uma
    // visitante sozinha percorre a cada tentativa.
    $root = fsu_root();
    $app = fsu_app($root, [['nome'], ['anexo']], [
        ['name' => 'nome', 'type' => 'text', 'required' => true],
        ['name' => 'telefone', 'type' => 'text', 'required' => true],
        ['name' => 'anexo', 'type' => 'file'],
    ]);

    $t = fst_post($app, ['_step' => 1, 'nome' => 'Ana'])['body']['token'];
    $r = fst_post($app, ['_step' => 2, '_step_token' => $t], true, fsu_files($root, 'anexo'));

    $sobrou = fsu_sweep($root);

    check_same('incomplete_lead', $r['body']['code'], 'é o ramo que se quer exercitar');
    check_same(['telefone'], array_keys((array) $r['body']['errors']), 'o obrigatório fora das etapas');
    check_same([], $sobrou, 'o arquivo gravado por um envio que não virou lead tem que sair do disco');

    check_same('2', (string) fst_row(1)['pending_step'], 'o lead continua aberto na etapa final');
});

test('validation_failed continua limpando o arquivo do mesmo POST', function () {
    // O laço que apagava aqui virou helper compartilhado com o
    // `incomplete_lead`; sem este caso, a extração poderia ter levado a
    // limpeza embora e só o ramo novo seguraria a regra.
    $root = fsu_root();
    $app = fsu_app($root, [['nome', 'anexo']], [
        ['name' => 'nome', 'type' => 'text', 'required' => true],
        ['name' => 'anexo', 'type' => 'file'],
    ]);

    $r = fst_post($app, ['_step' => 1, 'nome' => ''], true, fsu_files($root, 'anexo'));

    $sobrou = fsu_sweep($root);

    check_same('validation_failed', $r['body']['code']);
    // Sem esta linha o teste passa VAZIO: se o `anexo` também reprovasse
    // (whitelist de MIME, `accept`, tamanho), o `store()` teria recusado o
    // arquivo, não haveria nada no disco para limpar, e a asserção de baixo
    // continuaria verde sem exercitar o `discardUploads()`. Prender o erro
    // ao `nome` sozinho é o que prova que o upload chegou a acontecer.
    check_same(['nome'], array_keys((array) $r['body']['errors']), 'só o obrigatório vazio reprova');
    check_same([], $sobrou, 'o upload do POST reprovado não pode ficar no disco');
    check(
        !array_key_exists('anexo', (array) $r['body']['values']),
        'o metadado do arquivo apagado não pode voltar pro tema: `path` não aponta mais para nada'
    );
});

// ─── achado 2: a etapa reenviada substitui o arquivo ─────────────────────

test('reenvio de etapa com arquivo: o upload ANTERIOR sai do disco', function () {
    // O caminho que a documentação apoia explicitamente ("Going back a
    // step"): a visitante volta à etapa 1 e troca o anexo. Sem a limpeza, o
    // array_merge trocava o metadado e o arquivo antigo ficava no disco para
    // sempre — sem nenhuma linha que o referenciasse.
    $root = fsu_root();
    $app = fsu_app($root, [['nome', 'anexo'], ['cep']], [
        ['name' => 'nome', 'type' => 'text', 'required' => true],
        ['name' => 'anexo', 'type' => 'file'],
        ['name' => 'cep', 'type' => 'text'],
    ]);

    $t = fst_post($app, ['_step' => 1, 'nome' => 'Ana'], true, fsu_files($root, 'anexo'))['body']['token'];
    $primeiro = fsu_list($root);

    // Volta para a etapa 1 e reenvia com OUTRO arquivo.
    fst_post($app, ['_step' => 1, '_step_token' => $t, 'nome' => 'Ana'], true, fsu_files($root, 'anexo'));
    $depois = fsu_list($root);

    $data = json_decode(fst_row(1)['data'], true);
    $referenciado = $data['anexo']['path'] ?? null;

    $sobrou = fsu_sweep($root);

    check_same(1, count($primeiro), 'pré-condição: a etapa 1 gravou um arquivo');
    check_same(1, count($depois), 'depois do reenvio tem que continuar sendo UM arquivo, não dois');
    check_same($depois, $sobrou, 'nada além dele');
    check_same([$referenciado], $sobrou, 'e o que sobrou é exatamente o que a linha referencia');
    check(!in_array($primeiro[0], $sobrou, true), 'o arquivo da primeira tentativa não pode ter ficado');
});

test('reenvio de etapa com arquivo: depois do delete do painel não sobra NADA', function () {
    // O desfecho que o achado descreve: o órfão sobrevivia ao
    // deleteWithFiles(), porque ele percorre o `data` e o `data` já não
    // falava do arquivo antigo. Este é o teste de ponta a ponta da promessa
    // "apagar o lead apaga os arquivos dele".
    $root = fsu_root();
    $app = fsu_app($root, [['nome', 'anexo'], ['cep']], [
        ['name' => 'nome', 'type' => 'text', 'required' => true],
        ['name' => 'anexo', 'type' => 'file'],
        ['name' => 'cep', 'type' => 'text'],
    ]);

    $t = fst_post($app, ['_step' => 1, 'nome' => 'Ana'], true, fsu_files($root, 'anexo'))['body']['token'];
    fst_post($app, ['_step' => 1, '_step_token' => $t, 'nome' => 'Ana'], true, fsu_files($root, 'anexo'));

    \Velk\Models\FormSubmission::find(1)->deleteWithFiles();

    check_same([], fsu_sweep($root), 'apagar o lead tem que levar TODOS os arquivos dele');
});

test('reenvio SEM escolher arquivo de novo: o upload anterior é PRESERVADO', function () {
    // Controle negativo, e o caso mais provável de todos: a visitante volta
    // uma etapa, corrige o nome e não mexe no campo de arquivo. O validate()
    // sai por `continue` sem gravar a chave quando não há upload, então o
    // metadado antigo sobrevive ao array_merge — e a limpeza não pode
    // alcançá-lo. Sem esta asserção, uma "correção" que apagasse por nome de
    // campo em vez de por chave presente passaria despercebida e destruiria
    // o anexo de quem só corrigiu um typo.
    $root = fsu_root();
    $app = fsu_app($root, [['nome', 'anexo'], ['cep']], [
        ['name' => 'nome', 'type' => 'text', 'required' => true],
        ['name' => 'anexo', 'type' => 'file'],
        ['name' => 'cep', 'type' => 'text'],
    ]);

    $t = fst_post($app, ['_step' => 1, 'nome' => 'Ana'], true, fsu_files($root, 'anexo'))['body']['token'];
    $antes = fsu_list($root);
    fst_post($app, ['_step' => 1, '_step_token' => $t, 'nome' => 'Ana Maria']);

    $data = json_decode(fst_row(1)['data'], true);
    $sobrou = fsu_sweep($root);

    check_same($antes, $sobrou, 'o arquivo de quem não reenviou arquivo tem que ficar onde está');
    check_same('Ana Maria', $data['nome'], 'e a correção de verdade tem que ter sido gravada');
    check_same($antes[0], $data['anexo']['path'] ?? null, 'a linha continua referenciando o mesmo arquivo');
});

test('completeWith: a etapa FINAL que substitui um arquivo limpa o anterior', function () {
    // Alcance menor que o do mergeStep e por isso testado no model: para o
    // envio final trazer um arquivo para uma chave que JÁ tem um, o dono do
    // site precisa ter movido o campo para a última etapa com lead em
    // andamento — que a documentação diz ser duas linhas do site.json, sem
    // migração. A simetria entre os dois métodos é o que impede o buraco de
    // voltar por ali.
    $root = fsu_root();
    $app = fsu_app($root, [['nome'], ['anexo']], [
        ['name' => 'nome', 'type' => 'text', 'required' => true],
        ['name' => 'anexo', 'type' => 'file'],
    ]);

    // Dois arquivos de verdade no disco, pelo mesmo caminho que o engine usa.
    $velho = \Velk\Services\FormUploadService::store(fsu_files($root, 'anexo')['anexo'], ['name' => 'anexo', 'type' => 'file']);
    $novo  = \Velk\Services\FormUploadService::store(fsu_files($root, 'anexo')['anexo'], ['name' => 'anexo', 'type' => 'file']);
    check_same(2, count(fsu_list($root)), 'pré-condição: os dois estão no disco');

    $lead = \Velk\Models\FormSubmission::create([
        'form_id' => 'orcamento',
        'data' => ['nome' => 'Ana', 'anexo' => $velho],
        'pending_step' => 2,
        'step_token' => 'tok-abc',
    ]);
    check_same(true, $lead->completeWith(['anexo' => $novo]));

    $sobrou = fsu_sweep($root);

    check_same([$novo['path']], $sobrou, 'só o arquivo que a linha passou a referenciar');
});

test('completeWith que NÃO grava (corrida perdida) não apaga o arquivo do vencedor', function () {
    // A ordem importa: se a limpeza rodasse antes do UPDATE, o perdedor da
    // corrida apagaria o arquivo que a linha do vencedor referencia — e a
    // submissão ficaria apontando para o nada.
    $root = fsu_root();
    $app = fsu_app($root, [['nome'], ['anexo']], [
        ['name' => 'nome', 'type' => 'text', 'required' => true],
        ['name' => 'anexo', 'type' => 'file'],
    ]);

    $velho = \Velk\Services\FormUploadService::store(fsu_files($root, 'anexo')['anexo'], ['name' => 'anexo', 'type' => 'file']);
    $novo  = \Velk\Services\FormUploadService::store(fsu_files($root, 'anexo')['anexo'], ['name' => 'anexo', 'type' => 'file']);

    $vencedor = \Velk\Models\FormSubmission::create([
        'form_id' => 'orcamento',
        'data' => ['nome' => 'Ana', 'anexo' => $velho],
        'pending_step' => 2,
        'step_token' => 'tok-abc',
    ]);
    $perdedor = \Velk\Models\FormSubmission::find($vencedor->id);

    $vencedor->completeWith([]);                       // fecha mantendo o $velho
    check_same(false, $perdedor->completeWith(['anexo' => $novo]), 'a corrida foi perdida');

    $sobrou = fsu_sweep($root);

    check(in_array($velho['path'], $sobrou, true), 'o arquivo que a linha gravada referencia não pode ter sumido');
});
