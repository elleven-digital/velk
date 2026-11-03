<?php

declare(strict_types=1);

/**
 * O `bin/velk schema:validate` confere a declaração dos blocos do editor de
 * texto (as regras estão no richtext_blocks_validate_test.php): aqui, só que
 * o comando chama a conferência e passa os campos do bloco pela validação de
 * campos de sempre.
 */

require_once dirname(__DIR__) . '/fixtures/site_json_cli.php';

test('schema:validate: aponta um bloco mal declarado no site.json', function () {
    $out = sjc_velk('schema:validate', ['richtext_blocks' => [
        'Big-Numbers' => ['label' => 'Chave inválida', 'file' => 'big.php', 'fields' => [['name' => 'a', 'type' => 'text']]],
        'repetido' => ['label' => 'Campo repetido', 'file' => 'repetido.php', 'fields' => [['name' => 'a', 'type' => 'text'], ['name' => 'a', 'type' => 'text']]],
    ]]);
    check(str_contains($out, 'Issues found in site.json'), $out);
    check(str_contains($out, 'site.json → richtext_blocks: "Big-Numbers" is not a valid block key'), $out);
    check(str_contains($out, 'Block "repetido": file not found — create theme/partials/richtext/repetido.php'), $out);
    // A validação de campos de sempre também vale para os campos de um bloco.
    check(str_contains($out, 'Block "repetido": duplicate field name "a"'), $out);
});

test('schema:validate: o tema sem blocos continua válido', function () {
    // O tema real do checkout, sem blocos de verdade: sem `richtext_blocks` e
    // sem a lista `blocks` de nenhum campo — o teste não pode depender de o
    // tema local não declarar blocos.
    $semBlocos = static function (mixed $node) use (&$semBlocos): mixed {
        if (!is_array($node)) return $node;
        if (($node['type'] ?? null) === 'richtext') unset($node['blocks']);
        return array_map($semBlocos, $node);
    };
    $site = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/theme/site.json'), true);
    $out = sjc_velk('schema:validate', ['richtext_blocks' => null] + $semBlocos($site));
    check(str_contains($out, 'site.json is valid'), $out);
});

test('schema:validate: aponta a referência a um grupo que não existe dentro de um bloco', function () {
    // O resolveFields descarta a referência quebrada sem avisar: o bloco
    // ficaria sem o campo, e a janela dele abriria vazia.
    $out = sjc_velk('schema:validate', ['richtext_blocks' => [
        'com_grupo' => ['label' => 'Com grupo', 'file' => 'com_grupo.php', 'fields' => [['group' => 'grupo_que_nao_existe']]],
    ]]);
    check(str_contains($out, 'Block "com_grupo": unknown field group reference "grupo_que_nao_existe" (not declared in field_groups).'), $out);
});
