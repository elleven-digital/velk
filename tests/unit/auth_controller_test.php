<?php
declare(strict_types=1);
use Velk\Admin\Controllers\AuthController;

/**
 * AuthController::normalizeEmail() is the fix for I-3 (admin login
 * throttle key not normalized → per-account lockout bypass via case/
 * whitespace variants). Pulled out to a pure static method so the
 * normalization itself is testable without booting the app/DB — see
 * the docblock on normalizeEmail() and the comment at its login() call
 * site for the full story.
 */

test('normalizeEmail: lowercases mixed case', function () {
    check_same('admin@x.com', AuthController::normalizeEmail('Admin@X.com'));
});

test('normalizeEmail: trims leading/trailing whitespace', function () {
    check_same('admin@x.com', AuthController::normalizeEmail('  admin@x.com  '));
});

test('normalizeEmail: trailing space + mixed case together (the I-3 bypass shape)', function () {
    check_same('admin@x.com', AuthController::normalizeEmail('admin@x.com '));
    check_same('admin@x.com', AuthController::normalizeEmail('ADMIN@x.com'));
    check_same('admin@x.com', AuthController::normalizeEmail('Admin@x.com'));
});

test('normalizeEmail: all four variants collapse to the same key', function () {
    $variants = ['Admin@x.com', 'admin@x.com', 'ADMIN@x.com', 'admin@x.com '];
    $normalized = array_map(fn($v) => AuthController::normalizeEmail($v), $variants);
    check(count(array_unique($normalized)) === 1, 'all variants must normalize to a single throttle key');
    check_same('admin@x.com', $normalized[0]);
});

test('normalizeEmail: empty string stays empty', function () {
    check_same('', AuthController::normalizeEmail(''));
    check_same('', AuthController::normalizeEmail('   '));
});

test('normalizeEmail: internal whitespace is preserved (only ends are trimmed)', function () {
    // Not a valid email either way, but proves trim() semantics — we
    // don't want to silently rewrite the middle of the string.
    check_same('a b@x.com', AuthController::normalizeEmail(' A B@X.com '));
});
