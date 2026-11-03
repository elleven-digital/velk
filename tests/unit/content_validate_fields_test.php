<?php

declare(strict_types=1);

use Velk\Commands\Content;
use Velk\FieldRenderer;

// O collect() de um campo link passa por is_safe_url(), helper global.
require_once dirname(__DIR__, 2) . '/core/helpers.php';

/**
 * Content::validateFields() é o que `item:create`, `item:update` e
 * `page:update` aplicam ao conteúdo — nos dois updates, ao item INTEIRO (o
 * que já estava gravado + o que veio no JSON), não só ao que mudou.
 *
 * O painel não valida nada ao salvar: grava o que FieldRenderer::collect()
 * devolve. Num campo image/file isso é "0" quando a mídia nunca foi
 * escolhida (o hidden nasce com value="0") e "" logo depois do botão
 * Remover (o admin.js zera o input). O validador só aceitava id positivo ou
 * null, então um post salvo pelo painel com uma imagem vazia era recusado
 * pelo CLI até numa mudança que não tocava na imagem:
 * "Field 'og_image' has wrong type for 'image'."
 */

if (!function_exists('content_validate_fields')) {
    /** @return list<string> */
    function content_validate_fields(array $schema, array $values): array
    {
        $method = new ReflectionMethod(Content::class, 'validateFields');
        return $method->invoke(null, $schema, $values);
    }
}

// O post do tema de exemplo, só com um campo de cada tipo que ele usa.
$cvfPostSchema = [
    ['name' => 'dek', 'type' => 'textarea'],
    ['name' => 'thumbnail', 'type' => 'image'],
    ['name' => 'read_minutes', 'type' => 'number'],
    ['name' => 'content', 'type' => 'richtext'],
    ['name' => 'meta_title', 'type' => 'text'],
    ['name' => 'og_image', 'type' => 'image'],
];

test('update parcial: post salvo pelo painel com as imagens vazias ("0") passa', function () use ($cvfPostSchema) {
    // items.fields como o painel gravou um post em que ninguém escolheu imagem.
    $stored = [
        'dek' => '', 'content' => '<p></p>', 'og_image' => '0', 'thumbnail' => '0',
        'meta_title' => '', 'read_minutes' => null,
    ];
    // itemUpdate valida o gravado + o JSON, e o JSON só trouxe o texto rico.
    $merged = array_merge($stored, ['content' => '<p>Mudança só no texto rico.</p>']);

    check_same([], content_validate_fields($cvfPostSchema, $merged));
});

test('update parcial: imagem tirada pelo botão Remover (gravada como "") passa', function () use ($cvfPostSchema) {
    $stored = [
        'dek' => '', 'content' => '<p></p>', 'og_image' => '0', 'thumbnail' => '',
        'meta_title' => '', 'read_minutes' => null,
    ];
    $merged = array_merge($stored, ['meta_title' => 'Só o título mudou']);

    check_same([], content_validate_fields($cvfPostSchema, $merged));
});

// Um campo de cada tipo que tem um "vazio" próprio, mais um de texto.
$cvfEmptyDefs = [
    ['name' => 'capa', 'type' => 'image'],
    ['name' => 'anexo', 'type' => 'file'],
    ['name' => 'fotos', 'type' => 'gallery'],
    ['name' => 'cta', 'type' => 'link'],
    ['name' => 'relacionado', 'type' => 'item', 'item_type' => 'post'],
    ['name' => 'minutos', 'type' => 'number'],
    ['name' => 'destaque', 'type' => 'boolean'],
    ['name' => 'linhas', 'type' => 'repeater', 'fields' => [['name' => 'texto', 'type' => 'text']]],
    ['name' => 'blocos', 'type' => 'flexible', 'layouts' => ['hero' => ['label' => 'Hero', 'fields' => []]]],
    ['name' => 'titulo', 'type' => 'text'],
];

test('o que o painel grava de um formulário em que ninguém mexeu passa', function () use ($cvfEmptyDefs) {
    // O POST do navegador: image/file mandam o "0" do hidden; galeria,
    // repetidor, flexível e checkbox desmarcado não mandam nada.
    $stored = FieldRenderer::collect($cvfEmptyDefs, [
        'capa' => '0', 'anexo' => '0', 'cta' => ['title' => '', 'url' => ''],
        'relacionado' => '', 'minutos' => '', 'titulo' => '',
    ]);

    check_same([], content_validate_fields($cvfEmptyDefs, $stored));
});

test('o que o painel grava depois do botão Remover (image e file) passa', function () use ($cvfEmptyDefs) {
    $stored = FieldRenderer::collect($cvfEmptyDefs, [
        'capa' => '', 'anexo' => '', 'cta' => ['title' => '', 'url' => ''],
        'relacionado' => '', 'minutos' => '', 'titulo' => '',
    ]);

    check_same([], content_validate_fields($cvfEmptyDefs, $stored));
});

test('obrigatório: image/file vazio é campo faltando, não tipo errado', function () {
    $schema = [
        ['name' => 'capa', 'type' => 'image', 'required' => true],
        ['name' => 'anexo', 'type' => 'file', 'required' => true],
    ];
    $faltando = ["Field 'capa' is required.", "Field 'anexo' is required."];

    foreach (['0', '', 0, null] as $vazio) {
        check_same(
            $faltando,
            content_validate_fields($schema, ['capa' => $vazio, 'anexo' => $vazio]),
            'vazio ' . var_export($vazio, true)
        );
    }
    check_same($faltando, content_validate_fields($schema, []), 'chave ausente');
});

test('obrigatório: zero só é vazio em image/file — em texto e número é conteúdo', function () {
    $schema = [
        ['name' => 'nota', 'type' => 'text', 'required' => true],
        ['name' => 'estoque', 'type' => 'number', 'required' => true],
    ];

    check_same([], content_validate_fields($schema, ['nota' => '0', 'estoque' => 0]));
});
