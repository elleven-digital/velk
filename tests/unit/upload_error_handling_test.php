<?php

declare(strict_types=1);

use Velk\Admin\Controllers\MediaController;

// ini_bytes é helper global (não autoloadável) — mesmo padrão do field_item_test.
require_once dirname(__DIR__, 2) . '/core/helpers.php';

test('postWasDiscarded: corpo grande com POST/FILES vazios = descartado', function () {
    check_same(true, MediaController::postWasDiscarded(9000000, [], []));
});

test('postWasDiscarded: casos normais não disparam', function () {
    check_same(false, MediaController::postWasDiscarded(0, [], []));
    check_same(false, MediaController::postWasDiscarded(9000000, ['_csrf' => 'x'], []));
    check_same(false, MediaController::postWasDiscarded(9000000, [], ['file' => ['name' => 'a.gif']]));
});

test('ini_bytes: converte shorthand do php.ini', function () {
    check_same(2 * 1024 * 1024, ini_bytes('2M'));
    check_same(8 * 1024 * 1024, ini_bytes('8m'));
    check_same(512 * 1024, ini_bytes('512K'));
    check_same(1024 ** 3, ini_bytes('1G'));
    check_same(100, ini_bytes('100'));
    check_same(0, ini_bytes(''));
    check_same(-1, ini_bytes('-1'));
});
