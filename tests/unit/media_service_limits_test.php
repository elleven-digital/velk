<?php

declare(strict_types=1);

use Velk\Services\MediaService;

test('maxSizeMap: limites por categoria', function () {
    $map = MediaService::maxSizeMap();
    check_same(25 * 1024 * 1024, $map['image']);
    check_same(25 * 1024 * 1024, $map['document']);
    check_same(50 * 1024 * 1024, $map['audio']);
    check_same(100 * 1024 * 1024, $map['archive']);
    check_same(200 * 1024 * 1024, $map['video']);
});

test('maxSizeFor: resolve pelo mime', function () {
    check_same(200 * 1024 * 1024, MediaService::maxSizeFor('video/mp4'));
    check_same(50 * 1024 * 1024, MediaService::maxSizeFor('application/ogg'));
    check_same(100 * 1024 * 1024, MediaService::maxSizeFor('application/zip'));
    check_same(25 * 1024 * 1024, MediaService::maxSizeFor('image/gif'));
    check_same(25 * 1024 * 1024, MediaService::maxSizeFor('application/pdf'));
});
