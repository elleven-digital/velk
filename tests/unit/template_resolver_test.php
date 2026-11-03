<?php

declare(strict_types=1);

use Velk\TemplateResolver;

$single = ['template' => 'single-post.php', 'fields' => [['name' => 'a']]];
$listGlobal = [
    'templates' => [
        'p' => ['label' => 'Padrão', 'file' => 'a.php'],
        'v' => ['label' => 'Vídeo', 'file' => 'b.php'],
    ],
    'fields' => [['name' => 'g']],
];
$listPer = [
    'templates' => [
        'p' => ['label' => 'Padrão', 'file' => 'a.php', 'fields' => [['name' => 'pa']]],
        'v' => ['label' => 'Vídeo', 'file' => 'b.php', 'fields' => []],
    ],
];
// I8.2: um item_type PODE declarar `template` (singular) JUNTO de
// `templates` (lista) — mode() continua 'list' (templates não-vazio vence),
// mas o `template` singular agora serve de fallback do TIPO quando a chave
// custom do item não resolve (entrada removida/renomeada em site.json).
$listWithTypeFallback = [
    'template' => 'type-default.php',
    'templates' => [
        'p' => ['label' => 'Padrão', 'file' => 'a.php'],
    ],
];

test('mode: single vs list', function () use ($single, $listGlobal) {
    check_same('single', TemplateResolver::mode($single));
    check_same('list', TemplateResolver::mode($listGlobal));
    check_same('single', TemplateResolver::mode([]), 'nenhum → single');
    check_same('single', TemplateResolver::mode(['templates' => []]), 'mapa vazio → single');
});

test('options: chave=>label só no modo list', function () use ($single, $listGlobal) {
    check_same([], TemplateResolver::options($single));
    check_same(['p' => 'Padrão', 'v' => 'Vídeo'], TemplateResolver::options($listGlobal));
});

test('fieldsMode: global vs per_template', function () use ($single, $listGlobal, $listPer) {
    check_same('global', TemplateResolver::fieldsMode($single));
    check_same('global', TemplateResolver::fieldsMode($listGlobal), 'nenhuma entrada com fields');
    check_same('per_template', TemplateResolver::fieldsMode($listPer), 'todas com fields');
    $partial = ['templates' => ['p' => ['label' => 'P', 'file' => 'a.php', 'fields' => []], 'v' => ['label' => 'V', 'file' => 'b.php']]];
    check_same('global', TemplateResolver::fieldsMode($partial), 'parcial → global (schema:validate pega o erro)');
});

test('fileFor', function () use ($single, $listGlobal) {
    check_same('single-post.php', TemplateResolver::fileFor($single, null));
    check_same('single-post.php', TemplateResolver::fileFor($single, 'ignorado'), 'single ignora a chave');
    check_same('a.php', TemplateResolver::fileFor($listGlobal, 'p'));
    check_same('b.php', TemplateResolver::fileFor($listGlobal, 'v'));
    check_same(null, TemplateResolver::fileFor($listGlobal, 'inexistente'), 'chave inválida → null (sem template do tipo pra cair)');
    check_same(null, TemplateResolver::fileFor($listGlobal, null), 'sem chave → null (sem template do tipo pra cair)');
});

// I8.2: chave custom do item removida/renomeada não pode 404 o item —
// cai pro `template` do TIPO antes de desistir (fallback final pro
// single.php fica a cargo do FrontRouter, fora do escopo puro daqui).
test('fileFor: I8.2 — chave válida continua igual (fallback só entra quando a chave NÃO resolve)', function () use ($listWithTypeFallback) {
    check_same('a.php', TemplateResolver::fileFor($listWithTypeFallback, 'p'), 'chave válida não deve ser afetada pelo fallback novo');
});
test('fileFor: I8.2 — chave removida/renomeada cai pro template do tipo', function () use ($listWithTypeFallback) {
    check_same('type-default.php', TemplateResolver::fileFor($listWithTypeFallback, 'removida-do-site-json'));
});
test('fileFor: I8.2 — item sem chave nenhuma também cai pro template do tipo', function () use ($listWithTypeFallback) {
    check_same('type-default.php', TemplateResolver::fileFor($listWithTypeFallback, null));
});
test('fileFor: I8.2 — sem template do tipo declarado, continua null (não inventa fallback)', function () use ($listGlobal, $listPer) {
    check_same(null, TemplateResolver::fileFor($listGlobal, 'inexistente'));
    check_same(null, TemplateResolver::fileFor($listPer, 'inexistente'));
});

test('fieldsFor: global vs per_template', function () use ($listGlobal, $listPer) {
    check_same([['name' => 'g']], TemplateResolver::fieldsFor($listGlobal, 'p'), 'global: fields do tipo');
    check_same([['name' => 'pa']], TemplateResolver::fieldsFor($listPer, 'p'), 'per_template: fields da chave');
    check_same([], TemplateResolver::fieldsFor($listPer, 'v'), 'per_template chave com fields vazio');
    check_same([], TemplateResolver::fieldsFor($listPer, null), 'per_template sem chave → []');
});
