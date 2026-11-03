<?php
declare(strict_types=1);
use Velk\Models\User;

test('User::fromRow hydrates locale', function () {
    $u = User::fromRow([
        'id' => 1, 'email' => 'a@b.com', 'name' => 'A',
        'role' => 'admin', 'created_at' => '2026-01-01 00:00:00',
        'locale' => 'en',
    ]);
    check_same('en', $u->locale, 'locale should hydrate from row');
});

test('User::fromRow locale defaults to null when absent', function () {
    $u = User::fromRow([
        'id' => 1, 'email' => 'a@b.com', 'name' => 'A',
        'role' => 'admin', 'created_at' => '2026-01-01 00:00:00',
    ]);
    check_same(null, $u->locale, 'locale should be null when column absent');
});
