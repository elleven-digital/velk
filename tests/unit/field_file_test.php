<?php

declare(strict_types=1);

use Velk\FieldRenderer;

// e() é helper global usado pelo renderer.
require_once dirname(__DIR__, 2) . '/core/helpers.php';

// FieldRenderer's field-chrome strings (button labels, etc.) now go through
// __(), which resolves via App::instance()->adminI18n. Boot the lightest
// possible App — a real Config (no DB touch: Config only reads config/env.php
// + theme/site.json) with adminI18n pointed at the project's REAL catalogs —
// so render() below exercises the actual translations, not a stub.
$app = new \Velk\App(new \Velk\Config(dirname(__DIR__, 2)));
$app->adminI18n = new \Velk\Admin\I18n(dirname(__DIR__, 2) . '/core/Admin/lang', 'en', 'en');

test('file field: markup básico sem valor (não toca o banco)', function () {
    $html = FieldRenderer::render([
        'type' => 'file',
        'name' => 'anexo',
        'label' => 'Anexo',
        'accept' => ['video', 'document'],
    ], null, 'fields');
    check(str_contains($html, 'data-file-field'), 'container data-file-field');
    check(str_contains($html, 'data-accept="video,document"'), 'accept vira CSV no data-accept');
    check(str_contains($html, 'name="fields[anexo]"'), 'hidden com o name do campo');
    check(str_contains($html, 'data-file-field-input'), 'hidden marcado');
    check(str_contains($html, 'Choose file'), 'botão de escolher (EN, catálogo canônico)');
});

test('file field: accept inválido no site.json é ignorado no markup', function () {
    $html = FieldRenderer::render([
        'type' => 'file', 'name' => 'anexo', 'label' => 'Anexo',
        'accept' => ['banana', 'video'],
    ], null, 'fields');
    check(str_contains($html, 'data-accept="video"'), 'só categorias válidas sobram');
});

test('file field: walkValidate valida accept', function () {
    $issues = FieldRenderer::validateFields([
        ['type' => 'file', 'name' => 'a', 'accept' => ['banana']],
    ], 'page "x"');
    check(count($issues) === 1 && str_contains($issues[0], 'accept'), 'accept inválido gera issue');

    $issues2 = FieldRenderer::validateFields([
        ['type' => 'file', 'name' => 'b', 'accept' => ['video', 'image']],
        ['type' => 'file', 'name' => 'c'],
    ], 'page "x"');
    check_same([], $issues2);
});

test('css: .file-field existe no admin.css', function () {
    $css = (string) file_get_contents(
        dirname(__DIR__, 2) . '/core/Admin/assets/css/admin.css'
    );
    check(str_contains($css, '.file-field'), 'falta o CSS do campo file');
});
