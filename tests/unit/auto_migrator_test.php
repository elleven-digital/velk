<?php

declare(strict_types=1);

use Velk\AutoMigrator;

test('decide: kill-switch desligado sempre skip', function () {
    check_same('skip', AutoMigrator::decide(false, false, false));
    check_same('skip', AutoMigrator::decide(false, true, true));
});

test('decide: flag de falha trava (failed)', function () {
    check_same('failed', AutoMigrator::decide(true, true, true));
    check_same('failed', AutoMigrator::decide(true, true, false));
});

test('decide: pendente sem flag → migrate', function () {
    check_same('migrate', AutoMigrator::decide(true, false, true));
});

test('decide: nada pendente → skip', function () {
    check_same('skip', AutoMigrator::decide(true, false, false));
});

test('flag: flagFile + clearFlag lifecycle', function () {
    $dir = sys_get_temp_dir() . '/velk-am-' . bin2hex(random_bytes(4));
    mkdir($dir . '/cache', 0777, true);

    $flag = AutoMigrator::flagFile($dir);
    check_same($dir . '/cache/migration-error.json', $flag);

    file_put_contents($flag, '{}');
    check(is_file($flag), 'flag existe');

    AutoMigrator::clearFlag($dir);
    check(!is_file($flag), 'clearFlag removeu o flag');

    @rmdir($dir . '/cache');
    @rmdir($dir);
});
