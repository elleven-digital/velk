<?php

declare(strict_types=1);

use Velk\Config;
use Velk\RichtextBlocks;

/**
 * O marcador de um bloco do tema dentro do HTML de um campo richtext, e a
 * declaração dos blocos no site.json.
 *
 * O marcador é uma tag vazia com o tipo e os dados:
 * `<div data-velk-block="chave" data-velk-data="{json}"></div>`. O editor do
 * painel o grava sempre do mesmo jeito; quem escreve o conteúdo por fora (o
 * CLI, um agente) tende a usar aspas simples e outra ordem, e isso também
 * tem de ser lido.
 */

if (!function_exists('rbm_render')) {
    /** Troca cada marcador por `[tipo:json]`, para o teste ler o que foi entendido. */
    function rbm_render(string $html): string
    {
        return RichtextBlocks::replace($html, static fn(string $type, ?array $data): string
            => '[' . $type . ':' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ']');
    }

    /** Um Config com o site.json dado, sem ler disco. */
    function rbm_config(array $site): Config
    {
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, []);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, $site);
        return $config;
    }
}

test('bloco: o marcador que o editor grava é lido com o tipo e os dados', function () {
    $html = '<p>Antes.</p><div data-velk-block="bignumbers" data-velk-data="{&quot;itens&quot;:[{&quot;valor&quot;:&quot;120+&quot;,&quot;rotulo&quot;:&quot;Clientes ativos&quot;}]}"></div><p>Depois.</p>';
    check_same(
        '<p>Antes.</p>[bignumbers:{"itens":[{"valor":"120+","rotulo":"Clientes ativos"}]}]<p>Depois.</p>',
        rbm_render($html)
    );
});

test('bloco: vários marcadores no mesmo texto, cada um no seu lugar', function () {
    $html = '<div data-velk-block="a" data-velk-data="{&quot;n&quot;:1}"></div><p>meio</p><div data-velk-block="b" data-velk-data="{&quot;n&quot;:2}"></div>';
    check_same('[a:{"n":1}]<p>meio</p>[b:{"n":2}]', rbm_render($html));
});

test('bloco: aspas, acento, & e sinais de maior e menor nos dados chegam inteiros', function () {
    // Como o Chrome grava: tudo escapado dentro do atributo.
    $html = '<div data-velk-block="x" data-velk-data="{&quot;t&quot;:&quot;Café &amp; \\&quot;pão\\&quot; &lt;b&gt; 1 &gt; 0&quot;}"></div>';
    $lido = null;
    RichtextBlocks::replace($html, function (string $type, ?array $data) use (&$lido): string {
        $lido = $data;
        return '';
    });
    check_same(['t' => 'Café & "pão" <b> 1 > 0'], $lido);
});

test('bloco: um < ou > cru dentro do atributo não corta o marcador', function () {
    // Navegador antigo não escapa < e > em atributo, e o dado de um campo de
    // texto formatado dentro do bloco é HTML.
    $html = '<p>a</p><div data-velk-block="x" data-velk-data="{&quot;html&quot;:&quot;<p>oi</p>&quot;}"></div><p>b</p>';
    check_same('<p>a</p>[x:{"html":"<p>oi</p>"}]<p>b</p>', rbm_render($html));
});

test('bloco: escrito à mão — aspas simples, outra ordem, quebra de linha — também é lido', function () {
    $html = "<div data-velk-data='{\"itens\":[{\"valor\":\"98%\"}]}'\n     data-velk-block='bignumbers'>\n</div>";
    check_same('[bignumbers:{"itens":[{"valor":"98%"}]}]', rbm_render($html));
    check_same('[x:[]]', rbm_render('<DIV data-velk-block="x"></DIV>'), 'sem dados é um bloco vazio');
});

test('bloco: JSON quebrado ou que não é objeto chega como null, para quem chama decidir', function () {
    check_same('[x:null]', rbm_render('<div data-velk-block="x" data-velk-data="{quebrado"></div>'));
    check_same('[x:null]', rbm_render('<div data-velk-block="x" data-velk-data="&quot;texto&quot;"></div>'));
});

test('bloco: o que não é marcador fica como está', function () {
    foreach ([
        '<p>texto sem bloco nenhum</p>',
        '<div class="data-velk-block"></div>',
        '<div data-outro="1"></div>',
        '<div data-velk-block="x">com conteúdo dentro</div>',
        '',
    ] as $html) {
        check_same($html, rbm_render($html), $html);
    }
    check_same(false, RichtextBlocks::has('<p>texto</p>'));
    check_same(true, RichtextBlocks::has('<div data-velk-block="x"></div>'));
});

test('bloco: um texto enorme continua saindo inteiro, com o bloco no lugar', function () {
    $longo = str_repeat('<p>' . str_repeat('palavra ', 200) . '</p>', 2000);
    $out = rbm_render($longo . '<div data-velk-block="x" data-velk-data="{&quot;n&quot;:1}"></div>' . $longo);
    // check, e não check_same: numa falha o check_same imprimiria as duas
    // strings inteiras, uns 13 MB.
    check($out === $longo . '[x:{"n":1}]' . $longo, 'o texto enorme saiu diferente do esperado');
});

test('bloco: a chave só tem minúsculas, dígitos e _, começando por letra', function () {
    foreach (['bignumbers', 'video', 'cta_2', 'a'] as $ok) {
        check(RichtextBlocks::validKey($ok), $ok);
    }
    foreach (['', 'Big', 'big-numbers', '2cols', '../segredo', 'a/b', "a\n", 'a b', 'ção'] as $bad) {
        check(!RichtextBlocks::validKey($bad), var_export($bad, true));
    }
});

test('bloco: o Config só entrega blocos bem formados, e os campos já resolvidos', function () {
    $config = rbm_config([
        'field_groups' => ['contato' => ['fields' => [['name' => 'telefone', 'type' => 'text']]]],
        'richtext_blocks' => [
            'bignumbers' => ['label' => 'Big numbers', 'fields' => [['name' => 'itens', 'type' => 'repeater', 'fields' => [['name' => 'valor', 'type' => 'text']]]]],
            'cta' => ['label' => 'Chamada', 'fields' => [['group' => 'contato'], ['name' => 'texto', 'type' => 'richtext', 'blocks' => ['bignumbers']]]],
            'Big-Numbers' => ['label' => 'Chave inválida', 'fields' => []],
            'solto' => 'não é objeto',
        ],
    ]);
    check_same(['bignumbers', 'cta'], array_keys($config->richtextBlocks()));
    check_same('Big numbers', $config->richtextBlock('bignumbers')['label']);
    check_same(null, $config->richtextBlock('nao_existe'));
    check_same(null, $config->richtextBlock('Big-Numbers'));

    // A referência a grupo é expandida, e um campo de texto de um bloco não
    // aceita blocos: não existe bloco dentro de bloco.
    check_same(
        [['name' => 'telefone', 'type' => 'text'], ['name' => 'texto', 'type' => 'richtext']],
        $config->richtextBlockFields('cta')
    );
    check_same([], $config->richtextBlockFields('nao_existe'));
    check_same([], rbm_config([])->richtextBlocks());
    check_same([], rbm_config(['richtext_blocks' => 'texto'])->richtextBlocks());
    // O nome antigo, de antes da v3.9.0 sair, não é lido.
    check_same([], rbm_config(['blocks' => ['bignumbers' => ['label' => 'B', 'file' => 'b.php', 'fields' => []]]])->richtextBlocks());
});

test('bloco: sem bloco dentro de bloco, em qualquer profundidade dos campos', function () {
    $fields = [
        ['name' => 'a', 'type' => 'richtext', 'blocks' => ['x']],
        ['type' => 'group', 'label' => 'G', 'fields' => [['name' => 'b', 'type' => 'richtext', 'blocks' => ['x']]]],
        ['type' => 'tabs', 'tabs' => [['label' => 'T', 'fields' => [['name' => 'c', 'type' => 'richtext', 'blocks' => ['x']]]]]],
        ['name' => 'r', 'type' => 'repeater', 'fields' => [['name' => 'd', 'type' => 'richtext', 'blocks' => ['x']]]],
        ['name' => 'f', 'type' => 'flexible', 'layouts' => ['l' => ['label' => 'L', 'fields' => [['name' => 'e', 'type' => 'richtext', 'blocks' => ['x']]]]]],
        ['name' => 'g', 'type' => 'text', 'blocks' => 'não é richtext, fica'],
    ];
    $out = RichtextBlocks::withoutNestedBlocks($fields);
    check_same(['name' => 'a', 'type' => 'richtext'], $out[0]);
    check_same(['name' => 'b', 'type' => 'richtext'], $out[1]['fields'][0]);
    check_same(['name' => 'c', 'type' => 'richtext'], $out[2]['tabs'][0]['fields'][0]);
    check_same(['name' => 'd', 'type' => 'richtext'], $out[3]['fields'][0]);
    check_same(['name' => 'e', 'type' => 'richtext'], $out[4]['layouts']['l']['fields'][0]);
    check_same('não é richtext, fica', $out[5]['blocks']);
});

test('bloco: atributo repetido vale o primeiro, como no navegador e no editor', function () {
    check_same(
        '[seguro:{"a":1}]',
        rbm_render('<div data-velk-block="seguro" data-velk-data="{&quot;a&quot;:1}" data-velk-block="outro" data-velk-data="{&quot;a&quot;:2}"></div>')
    );
});
