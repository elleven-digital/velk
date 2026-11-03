<?php

declare(strict_types=1);

use Velk\Config;
use Velk\RichtextBlocks;

/**
 * A declaração dos blocos do editor de texto, como o `schema:validate` a
 * confere. Cada erro aqui é um que só apareceria tarde: um bloco que o autor
 * insere e nunca aparece no site, um campo que o partial não consegue ler.
 */

if (!function_exists('rbv_problems')) {
    /**
     * Os problemas de um site.json num tema temporário com os arquivos dados.
     *
     * @param array<string, string> $files      caminho relativo ao tema => conteúdo
     * @param list<string>          $unreadable diretórios do tema que ficam sem permissão de leitura
     * @return list<string>
     */
    function rbv_problems(array $site, array $files = [], array $unreadable = []): array
    {
        $theme = sys_get_temp_dir() . '/velk_rbv_' . bin2hex(random_bytes(4));
        mkdir($theme, 0777, true);
        foreach ($files as $path => $content) {
            @mkdir(dirname($theme . '/' . $path), 0777, true);
            file_put_contents($theme . '/' . $path, $content);
        }
        foreach ($unreadable as $dir) {
            chmod($theme . '/' . $dir, 0);
        }
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, ['paths' => ['theme' => $theme]]);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, $site);
        try {
            return RichtextBlocks::validate($config);
        } finally {
            foreach ($unreadable as $dir) {
                chmod($theme . '/' . $dir, 0700);
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($theme, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($theme);
        }
    }

    /** Um site.json certo: um bloco, e um campo de texto que o aceita. */
    function rbv_site(array $blockFields = [['name' => 'itens', 'type' => 'repeater', 'fields' => [['name' => 'valor', 'type' => 'text']]]]): array
    {
        return [
            'richtext_blocks' => ['bignumbers' => ['label' => 'Big numbers', 'file' => 'bignumbers.php', 'fields' => $blockFields]],
            'item_types' => ['post' => ['fields' => [['name' => 'content', 'type' => 'richtext', 'blocks' => ['bignumbers']]]]],
        ];
    }

    /** Os arquivos do tema que o site.json certo pede. */
    function rbv_files(): array
    {
        return [
            'partials/richtext/bignumbers.php' => '<div class="bignumbers"></div>',
            'templates/post.php' => "<?= richtext(field('content')) ?>",
        ];
    }

    /** Exatamente um problema, contendo o trecho dado. */
    function rbv_one(array $problems, string $needle): void
    {
        check_same(1, count($problems), implode(' | ', $problems));
        check(str_contains($problems[0], $needle), $problems[0]);
    }
}

test('blocos: declaração certa, com partial e helper no tema, não tem problema', function () {
    check_same([], rbv_problems(rbv_site(), rbv_files()));
});

test('blocos: um site que não usa blocos não tem o que conferir', function () {
    check_same([], rbv_problems(['item_types' => ['post' => ['fields' => [['name' => 'content', 'type' => 'richtext']]]]]));
    check_same([], rbv_problems(['richtext_blocks' => []]));
    check_same([], rbv_problems([]));
});

test('blocos: "richtext_blocks" tem de ser um objeto por chave de bloco', function () {
    foreach (['bignumbers', ['bignumbers']] as $blocks) {
        rbv_one(rbv_problems(['richtext_blocks' => $blocks]), 'site.json → richtext_blocks: must be an object');
    }
});

test('blocos: chave fora do padrão é apontada pelo nome', function () {
    $site = rbv_site();
    $site['richtext_blocks']['Big-Numbers'] = $site['richtext_blocks']['bignumbers'];
    rbv_one(rbv_problems($site, rbv_files()), 'site.json → richtext_blocks: "Big-Numbers" is not a valid block key');
});

test('blocos: o bloco precisa de label e de pelo menos um campo', function () {
    $files = rbv_files();
    $com = fn(mixed $def): array => rbv_problems(['richtext_blocks' => ['bignumbers' => $def]], $files);

    rbv_one($com('texto'), 'Block "bignumbers": definition must be an object');
    rbv_one($com(['file' => 'bignumbers.php', 'fields' => [['name' => 'a', 'type' => 'text']]]), 'Block "bignumbers": "label" is required');
    rbv_one($com(['label' => '  ', 'file' => 'bignumbers.php', 'fields' => [['name' => 'a', 'type' => 'text']]]), 'Block "bignumbers": "label" is required');
    foreach ([null, [], 'texto', ['a' => ['name' => 'a', 'type' => 'text']]] as $fields) {
        rbv_one($com(['label' => 'Big numbers', 'file' => 'bignumbers.php', 'fields' => $fields]), 'Block "bignumbers": needs a non-empty "fields" list');
    }
});

test('blocos: o bloco declara o arquivo do partial, e ele tem de existir', function () {
    $semArquivo = rbv_site();
    unset($semArquivo['richtext_blocks']['bignumbers']['file']);
    rbv_one(rbv_problems($semArquivo, rbv_files()), 'Block "bignumbers": "file" is required — the name of its partial in theme/partials/richtext/');

    $files = rbv_files();
    unset($files['partials/richtext/bignumbers.php']);
    rbv_one(rbv_problems(rbv_site(), $files), 'Block "bignumbers": file not found — create theme/partials/richtext/bignumbers.php');
});

test('blocos: o arquivo é o declarado, não o nome da chave', function () {
    // A ligação está no site.json, como a dos templates: o arquivo pode ter
    // qualquer nome, e um arquivo com o nome da chave não conta.
    $site = rbv_site();
    $site['richtext_blocks']['bignumbers']['file'] = 'numeros-grandes.php';
    $files = rbv_files();
    $files['partials/richtext/numeros-grandes.php'] = '<div></div>';
    check_same([], rbv_problems($site, $files));
    unset($files['partials/richtext/numeros-grandes.php']);
    rbv_one(rbv_problems($site, $files), 'file not found — create theme/partials/richtext/numeros-grandes.php');

    // Sem o .php vale, como no partial() e nos templates.
    $site['richtext_blocks']['bignumbers']['file'] = 'bignumbers';
    check_same([], rbv_problems($site, rbv_files()));
});

test('blocos: "file" é só o nome do arquivo — a pasta é sempre theme/partials/richtext/', function () {
    foreach (['richtext/bignumbers.php', '../bignumbers.php', 'a\\b.php', '.bignumbers.php', '', '  ', 42, ['bignumbers.php'], "big\0numbers.php"] as $file) {
        $site = rbv_site();
        $site['richtext_blocks']['bignumbers']['file'] = $file;
        rbv_one(rbv_problems($site, rbv_files()), 'Block "bignumbers": "file" must be just a file name, without folders');
    }
});

test('blocos: nome de campo que não vira variável no partial é apontado', function () {
    // O partial recebe os campos como variáveis: `$cta-label` não existe em PHP.
    foreach (['cta-label', '2col', 'a b'] as $nome) {
        rbv_one(
            rbv_problems(rbv_site([['name' => $nome, 'type' => 'text']]), rbv_files()),
            'Block "bignumbers": field "' . $nome . '" can\'t be a variable in the partial — use letters'
        );
    }
    // Estes o partial() já usa por dentro: o valor do campo não chegaria.
    foreach (['this', '__data', '__file'] as $nome) {
        rbv_one(
            rbv_problems(rbv_site([['name' => $nome, 'type' => 'text']]), rbv_files()),
            'Block "bignumbers": field "' . $nome . '" can\'t be a variable in the partial — the name is taken'
        );
    }
    // Acento vale: o PHP aceita `$ação`.
    check_same([], rbv_problems(rbv_site([['name' => 'ação', 'type' => 'text']]), rbv_files()));
    // Dentro de group e tabs o campo fica no mesmo nível, e também vira variável.
    rbv_one(
        rbv_problems(rbv_site([['type' => 'group', 'label' => 'G', 'fields' => [['name' => 'a-b', 'type' => 'text']]]]), rbv_files()),
        'field "a-b"'
    );
    // Subcampo de repetidor é chave de lista, não variável: qualquer nome serve.
    check_same([], rbv_problems(rbv_site([['name' => 'itens', 'type' => 'repeater', 'fields' => [['name' => 'meu-valor', 'type' => 'text']]]]), rbv_files()));
});

test('blocos: sem bloco dentro de bloco — nem direto, nem por grupo reutilizável', function () {
    $direto = rbv_site([['name' => 'texto', 'type' => 'richtext', 'blocks' => ['bignumbers']]]);
    rbv_one(rbv_problems($direto, rbv_files()), 'Block "bignumbers": the richtext field "texto" can\'t accept blocks');

    $porGrupo = rbv_site([['group' => 'corpo']]);
    $porGrupo['field_groups'] = ['corpo' => ['fields' => [['name' => 'texto', 'type' => 'richtext', 'blocks' => ['bignumbers']]]]];
    $problems = rbv_problems($porGrupo, rbv_files());
    check(count(array_filter($problems, fn(string $p): bool => str_contains($p, 'Block "bignumbers": the richtext field "texto" can\'t accept blocks'))) === 1, implode(' | ', $problems));
});

test('blocos: a lista de um campo de texto só cita blocos declarados', function () {
    $site = rbv_site();
    $site['item_types']['post']['fields'][0]['blocks'] = ['bignumbers', 'bignumber'];
    rbv_one(rbv_problems($site, rbv_files()), 'richtext field "content" lists the block "bignumber", which is not declared in "richtext_blocks"');

    foreach (['bignumbers', ['a' => 'bignumbers']] as $lista) {
        $site['item_types']['post']['fields'][0]['blocks'] = $lista;
        rbv_one(rbv_problems($site, rbv_files()), 'richtext field "content": "blocks" must be a list of block keys');
    }
});

test('blocos: o campo de texto é achado em qualquer lugar do site.json', function () {
    $campo = ['name' => 'texto', 'type' => 'richtext', 'blocks' => ['nao_existe']];
    $lugares = [
        ['pages' => ['home' => ['fields' => [$campo]]]],
        ['item_types' => ['post' => ['templates' => ['longo' => ['label' => 'L', 'file' => 'l.php', 'fields' => [$campo]]]]]],
        ['options' => ['geral' => ['fields' => [['name' => 'r', 'type' => 'repeater', 'fields' => [$campo]]]]]],
        ['members' => ['fields' => [['type' => 'tabs', 'tabs' => [['label' => 'T', 'fields' => [$campo]]]]]]],
        ['field_groups' => ['corpo' => ['fields' => [$campo]]]],
    ];
    foreach ($lugares as $site) {
        rbv_one(rbv_problems($site), 'richtext field "texto" lists the block "nao_existe"');
    }
});

test('blocos: em uso e sem o helper em nenhum arquivo do tema, nunca apareceriam', function () {
    $files = rbv_files();
    $files['templates/post.php'] = "<?= field('content') ?>";
    rbv_one(rbv_problems(rbv_site(), $files), 'richtext field "content" accepts blocks, but no theme file calls richtext()');

    // Em qualquer arquivo PHP do tema serve — e node_modules não conta.
    $files['partials/corpo.php'] = '<?= richtext($texto) ?>';
    check_same([], rbv_problems(rbv_site(), $files));
    unset($files['partials/corpo.php']);
    $files['node_modules/x/index.php'] = '<?= richtext($texto) ?>';
    rbv_one(rbv_problems(rbv_site(), $files), 'no theme file calls richtext()');

    // Bloco declarado que nenhum campo usa não pede o helper.
    $semUso = rbv_site();
    unset($semUso['item_types']);
    unset($files['node_modules/x/index.php']);
    check_same([], rbv_problems($semUso, $files));
});

test('blocos: o helper é reconhecido com espaço antes do parêntese e em qualquer caixa', function () {
    // O PHP aceita `richtext (…)` e não diferencia maiúsculas em nome de função.
    foreach (["<?= richtext (field('content')) ?>", "<?= RichText(field('content')) ?>", "<?php echo richtext\n    (field('content')); ?>"] as $chamada) {
        $files = rbv_files();
        $files['templates/post.php'] = $chamada;
        check_same([], rbv_problems(rbv_site(), $files), $chamada);
    }
    // Outra função cujo nome só termina igual não é o helper.
    $files = rbv_files();
    $files['templates/post.php'] = '<?= my_richtext($texto) ?>';
    rbv_one(rbv_problems(rbv_site(), $files), 'no theme file calls richtext()');
});

test('blocos: um subdiretório ilegível no tema não derruba a conferência', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        return; // o root lê qualquer diretório: não há como montar o caso
    }
    // Nenhum arquivo legível chama o helper, então a varredura passa pelo tema
    // inteiro — inclusive pelo diretório que não dá para abrir.
    $files = rbv_files();
    $files['templates/post.php'] = "<?= field('content') ?>";
    $files['privado/x.php'] = "<?= richtext(field('content')) ?>";
    rbv_one(rbv_problems(rbv_site(), $files, ['privado']), 'no theme file calls richtext()');
});

test('blocos: um campo com "name" que não é texto não gera aviso do PHP', function () {
    $site = rbv_site();
    $site['item_types']['post']['fields'][] = ['name' => ['x'], 'type' => 'richtext', 'blocks' => 'bignumbers'];
    $site['richtext_blocks']['bignumbers']['fields'][] = ['name' => ['y'], 'type' => 'text'];
    $avisos = [];
    set_error_handler(function (int $no, string $msg) use (&$avisos): bool { $avisos[] = $msg; return true; });
    try {
        $problems = rbv_problems($site, rbv_files());
    } finally {
        restore_error_handler();
    }
    check_same([], $avisos, implode(' | ', $avisos));
    check($problems !== [], 'o campo quebrado ainda é apontado');
});
