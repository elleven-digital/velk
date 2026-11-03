<?php

declare(strict_types=1);

use Velk\Models\Media;

test('categoryOf: mapeia mime → categoria', function () {
    check_same('image', Media::categoryOf('image/jpeg'));
    check_same('image', Media::categoryOf('image/svg+xml'));
    check_same('video', Media::categoryOf('video/mp4'));
    check_same('video', Media::categoryOf('video/quicktime'));
    check_same('audio', Media::categoryOf('audio/mpeg'));
    check_same('audio', Media::categoryOf('application/ogg'));
    check_same('archive', Media::categoryOf('application/zip'));
    check_same('document', Media::categoryOf('application/pdf'));
    check_same('document', Media::categoryOf(
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ));
});

test('category(): instância delega pro mime da row', function () {
    $m = Media::fromRow([
        'id' => 1, 'filename' => 'clip.mp4', 'original_name' => 'clip.mp4',
        'mime' => 'video/mp4', 'size' => 1000, 'created_at' => '2026-07-02 00:00:00',
    ]);
    check_same('video', $m->category());
});

test('categoriesWhereSql: vazio → sem WHERE', function () {
    check_same(['', []], Media::categoriesWhereSql([]));
});

test('categoriesWhereSql: image/video/archive viram condições sem params', function () {
    [$sql, $params] = Media::categoriesWhereSql(['image', 'video', 'archive']);
    check(str_contains($sql, "mime LIKE 'image/%'"), 'condição de image');
    check(str_contains($sql, "mime LIKE 'video/%'"), 'condição de video');
    check(str_contains($sql, "mime = 'application/zip'"), 'condição de archive');
    check(str_contains($sql, ' OR '), 'condições unidas por OR');
    check_same([], $params);
});

test('categoriesWhereSql: audio inclui application/ogg', function () {
    [$sql, ] = Media::categoriesWhereSql(['audio']);
    check(str_contains($sql, "mime LIKE 'audio/%'"), 'prefixo audio/');
    check(str_contains($sql, "mime = 'application/ogg'"), 'ogg é áudio');
});

test('categoriesWhereSql: document parametriza pdf + ooxml', function () {
    [$sql, $params] = Media::categoriesWhereSql(['document']);
    check(str_contains($sql, 'mime IN (?, ?, ?, ?)'), 'IN com 4 placeholders');
    check_same([
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ], $params);
});
