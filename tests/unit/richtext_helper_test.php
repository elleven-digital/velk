<?php

declare(strict_types=1);

use Velk\App;
use Velk\Config;

/**
 * `richtext()`: o HTML de um campo de texto com cada bloco do tema trocado
 * pela saída do partial que o site.json declara para ele (`file`, em
 * `theme/partials/richtext/`).
 *
 * O que o helper promete ao tema: o partial recebe os campos do bloco como
 * variáveis, um post antigo não quebra o partial quando a declaração do
 * bloco muda, e o que não dá para imprimir some sem derrubar a página.
 */

require_once dirname(__DIR__, 2) . '/core/helpers.php';

if (!function_exists('rth_theme')) {
    /** Um tema temporário com os arquivos dados (caminho relativo => código). */
    function rth_theme(array $files): string
    {
        $dir = sys_get_temp_dir() . '/velk_rth_' . bin2hex(random_bytes(4));
        mkdir($dir . '/partials/richtext', 0777, true);
        foreach ($files as $path => $code) {
            file_put_contents($dir . '/partials/' . $path, $code);
        }
        return $dir;
    }

    function rth_rm(string $dir): void
    {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    /** `$fn` com um App que tem os blocos e os partials dados; devolve o que `$fn` devolver. */
    function rth_with(array $blocks, array $files, callable $fn, bool $debug = false): mixed
    {
        $theme = rth_theme($files);
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, ['paths' => ['theme' => $theme], 'app' => ['debug' => $debug]]);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, ['richtext_blocks' => $blocks]);
        new App($config);
        try {
            return $fn();
        } finally {
            rth_rm($theme);
        }
    }

    /** O marcador de um bloco, escapado como o editor grava. */
    function rth_marker(string $type, array $data): string
    {
        return '<div data-velk-block="' . $type . '" data-velk-data="'
            . htmlspecialchars((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES | ENT_HTML5, 'UTF-8')
            . '"></div>';
    }

    /** `$fn` com o error_log do PHP num arquivo; devolve o que foi registrado. */
    function rth_log(callable $fn): string
    {
        $file = tempnam(sys_get_temp_dir(), 'velk_rth_log_');
        $old = ini_set('error_log', $file);
        try {
            $fn();
        } finally {
            ini_set('error_log', $old === false ? '' : $old);
        }
        $out = (string) file_get_contents($file);
        @unlink($file);
        return $out;
    }

    /** O bloco de big numbers do exemplo: um repetidor de número + rótulo, e um título. */
    function rth_bignumbers(): array
    {
        return ['bignumbers' => ['label' => 'Big numbers', 'file' => 'bignumbers.php', 'fields' => [
            ['name' => 'titulo', 'type' => 'text'],
            ['name' => 'itens', 'type' => 'repeater', 'fields' => [
                ['name' => 'valor', 'type' => 'text'],
                ['name' => 'rotulo', 'type' => 'text'],
            ]],
        ]]];
    }
}

test('richtext: o bloco sai pelo partial do tema, com os campos como variáveis', function () {
    $partial = '<h3><?= e($titulo) ?></h3><?php foreach ($itens as $n): ?><b><?= e($n[\'valor\']) ?></b> <?= e($n[\'rotulo\']) ?>;<?php endforeach; ?>';
    $html = '<p>Antes.</p>' . rth_marker('bignumbers', ['titulo' => 'Em números', 'itens' => [
        ['valor' => '120+', 'rotulo' => 'Clientes ativos'],
        ['valor' => '98%', 'rotulo' => 'Renovação & retorno'],
    ]]) . '<p>Depois.</p>';

    $out = rth_with(rth_bignumbers(), ['richtext/bignumbers.php' => $partial], fn() => richtext($html));
    check_same('<p>Antes.</p><h3>Em números</h3><b>120+</b> Clientes ativos;<b>98%</b> Renovação &amp; retorno;<p>Depois.</p>', $out);
});

test('richtext: o partial é o arquivo que o site.json declara, não o nome da chave', function () {
    $blocks = rth_bignumbers();
    $blocks['bignumbers']['file'] = 'numeros-grandes.php';
    // Só o arquivo declarado existe: é ele que sai.
    $files = ['richtext/numeros-grandes.php' => 'DECLARADO'];
    check_same('DECLARADO', rth_with($blocks, $files, fn() => richtext(rth_marker('bignumbers', []))));
    // Um arquivo com o nome da chave ao lado não muda nada.
    $files['richtext/bignumbers.php'] = 'PELA CHAVE';
    check_same('DECLARADO', rth_with($blocks, $files, fn() => richtext(rth_marker('bignumbers', []))));

    // Sem o .php vale, como no partial().
    $blocks['bignumbers']['file'] = 'numeros-grandes';
    check_same('DECLARADO', rth_with($blocks, $files, fn() => richtext(rth_marker('bignumbers', []))));
});

test('richtext: texto sem bloco volta igual, e null vira texto vazio', function () {
    $out = rth_with(rth_bignumbers(), [], fn() => [richtext('<p>só texto</p>'), richtext(null), richtext('')]);
    check_same(['<p>só texto</p>', '', ''], $out);
});

test('richtext: campo que falta no dado gravado chega com o vazio do tipo dele', function () {
    // O bloco ganhou campos depois: o post antigo só tem o marcador. Cada campo
    // chega como se o autor o tivesse deixado em branco no formulário.
    $blocks = ['aviso' => ['label' => 'Aviso', 'file' => 'aviso.php', 'fields' => [
        ['name' => 'texto', 'type' => 'text'],
        ['name' => 'destaque', 'type' => 'boolean'],
        ['name' => 'itens', 'type' => 'repeater', 'fields' => [['name' => 'a', 'type' => 'text']]],
    ]]];
    $partial = '<?= json_encode([$texto, $destaque, $itens]) ?>';
    $out = rth_with($blocks, ['richtext/aviso.php' => $partial], fn() => richtext(rth_marker('aviso', [])));
    check_same('[null,false,[]]', $out);
});

test('richtext: uma lista nunca chega ao partial como outra coisa', function () {
    // `itens` era um campo de texto e virou repetidor: o foreach do partial
    // não pode receber o texto antigo.
    $partial = '<?php foreach ($itens as $n): ?>x<?php endforeach; ?>(<?= count($itens) ?>)';
    $out = rth_with(rth_bignumbers(), ['richtext/bignumbers.php' => $partial], fn() => richtext(rth_marker('bignumbers', ['itens' => 'texto antigo'])));
    check_same('(0)', $out);
});

test('richtext: um campo de texto nunca chega ao partial como lista', function () {
    // O contrário do teste acima: `titulo` era um repetidor e virou texto. O
    // post antigo tem uma lista, e `e($titulo)` imprimiria "Array" com aviso.
    $partial = '<?= var_export($titulo, true) ?>';
    $out = rth_with(rth_bignumbers(), ['richtext/bignumbers.php' => $partial],
        fn() => richtext(rth_marker('bignumbers', ['titulo' => [['valor' => 'x']]])));
    check_same('NULL', $out);
});

test('richtext: subcampo que falta numa linha de repetidor chega vazio, e o que saiu da declaração some', function () {
    // O repetidor ganhou `rotulo` depois e perdeu `antigo`: a linha gravada
    // tem `valor` e `antigo`. O partial recebe a linha como o formulário a
    // salvaria hoje.
    $partial = '<?= json_encode($itens) ?>';
    $out = rth_with(rth_bignumbers(), ['richtext/bignumbers.php' => $partial],
        fn() => richtext(rth_marker('bignumbers', ['itens' => [['valor' => '120+', 'antigo' => 'x']]])));
    check_same('[{"valor":"120+","rotulo":null}]', $out);
});

test('richtext: só os campos declarados viram variável', function () {
    $partial = '<?= isset($sobra) ? "tem" : "não tem" ?>';
    $out = rth_with(rth_bignumbers(), ['richtext/bignumbers.php' => $partial], fn() => richtext(rth_marker('bignumbers', ['sobra' => 'x'])));
    check_same('não tem', $out);
});

test('richtext: o que não dá para imprimir some do texto, sem derrubar a página', function () {
    $files = [
        'richtext/bignumbers.php' => 'BIG',
        // Se a chave "../segredo" virasse caminho, este arquivo seria incluído.
        'segredo.php' => 'VAZOU',
    ];
    $blocks = rth_bignumbers() + [
        'sem_partial' => ['label' => 'Sem partial', 'file' => 'sem_partial.php', 'fields' => [['name' => 'a', 'type' => 'text']]],
        'sem_file' => ['label' => 'Sem file', 'fields' => [['name' => 'a', 'type' => 'text']]],
        // Se o "file" saísse da pasta dos blocos, partials/segredo.php seria incluído.
        'fora' => ['label' => 'Fora', 'file' => '../segredo.php', 'fields' => [['name' => 'a', 'type' => 'text']]],
    ];
    $html = '<p>a</p>'
        . rth_marker('nao_declarado', [])
        . rth_marker('../segredo', [])
        . '<div data-velk-block="bignumbers" data-velk-data="{quebrado"></div>'
        . rth_marker('sem_partial', [])
        . rth_marker('sem_file', [])
        . rth_marker('fora', [])
        . '<p>b</p>' . rth_marker('bignumbers', []);

    $out = rth_with($blocks, $files, fn() => richtext($html));
    check_same('<p>a</p><p>b</p>BIG', $out);
});

test('richtext: com APP_DEBUG o motivo vai para o log; sem ele, nada', function () {
    $html = rth_marker('nao_declarado', []) . rth_marker("x\n[forjado]", []) . rth_marker('sem_file', []) . rth_marker('bignumbers', []);
    $blocks = rth_bignumbers() + ['sem_file' => ['label' => 'Sem file', 'fields' => [['name' => 'a', 'type' => 'text']]]];
    $log = rth_log(fn() => rth_with($blocks, [], fn() => richtext($html), true));
    check(str_contains($log, '[Velk] richtext: block "nao_declarado" skipped — not declared in site.json.'), $log);
    check(str_contains($log, 'block "sem_file" skipped — no valid "file" in site.json.'), $log);
    check(str_contains($log, 'block "bignumbers" skipped — partial not found.'), $log);
    check(str_contains($log, 'block "x??forjado?" skipped — invalid block key.'), $log);
    check(!str_contains($log, "\n[forjado]"), 'a chave não pode abrir uma linha nova no log');

    $log = rth_log(fn() => rth_with(rth_bignumbers(), [], fn() => richtext($html), false));
    check_same('', $log);
});

test('richtext: um partial que lança erro não deixa a saída presa', function () {
    $nivel = ob_get_level();
    $erro = null;
    try {
        rth_with(rth_bignumbers(), ['richtext/bignumbers.php' => 'meio<?php throw new RuntimeException("falhou"); ?>'],
            fn() => richtext('<p>a</p>' . rth_marker('bignumbers', [])));
    } catch (RuntimeException $e) {
        $erro = $e->getMessage();
    }
    check_same('falhou', $erro, 'o erro do tema aparece, não é engolido');
    check_same($nivel, ob_get_level(), 'nenhum buffer de saída fica aberto');
});

test('richtext: um marcador dentro do dado de um bloco não é expandido', function () {
    // Sem bloco dentro de bloco: o texto de um campo do bloco sai como está.
    $dentro = rth_marker('bignumbers', []);
    $out = rth_with(rth_bignumbers(), ['richtext/bignumbers.php' => '{<?= $titulo ?>}'],
        fn() => richtext(rth_marker('bignumbers', ['titulo' => $dentro])));
    check_same('{' . $dentro . '}', $out);
});

test('richtext: dado que não é texto (um campo que mudou de tipo) sai vazio, sem derrubar a página', function () {
    $out = rth_with(rth_bignumbers(), [], fn() => [richtext(['a' => 1]), richtext(5), richtext(false)]);
    check_same(['', '', ''], $out);
});
