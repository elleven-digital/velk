<?php

declare(strict_types=1);

use Velk\UploadController;

test('parseRange: sem header ou lixo → null (200 completo)', function () {
    check_same(null, UploadController::parseRange(null, 100));
    check_same(null, UploadController::parseRange('', 100));
    check_same(null, UploadController::parseRange('lixo', 100));
    check_same(null, UploadController::parseRange('chars=0-10', 100));
    check_same(null, UploadController::parseRange('bytes=-', 100));
});

test('parseRange: ranges válidos', function () {
    check_same([0, 99], UploadController::parseRange('bytes=0-', 100));
    check_same([10, 19], UploadController::parseRange('bytes=10-19', 100));
    check_same([0, 0], UploadController::parseRange('bytes=0-0', 100));
    check_same([10, 99], UploadController::parseRange('bytes=10-1000', 100));
});

test('parseRange: sufixo (últimos N bytes)', function () {
    check_same([70, 99], UploadController::parseRange('bytes=-30', 100));
    check_same([0, 99], UploadController::parseRange('bytes=-200', 100));
});

test('parseRange: insatisfazível → false (416)', function () {
    check_same(false, UploadController::parseRange('bytes=100-', 100));
    check_same(false, UploadController::parseRange('bytes=200-300', 100));
    check_same(false, UploadController::parseRange('bytes=-0', 100));
    check_same(false, UploadController::parseRange('bytes=0-', 0));
    check_same(false, UploadController::parseRange('bytes=-10', 0));
});

test('parseRange: inválidos sintáticos → null', function () {
    check_same(null, UploadController::parseRange('bytes=5-2', 100));
    check_same(null, UploadController::parseRange('bytes=0-10,20-30', 100));
});
