<?php

declare(strict_types=1);

use Velk\Services\EmailOtpService;

/**
 * Pure cooldown math only — secondsUntilResend() itself needs a DB (it
 * reads the latest row's created_at via MySQL's own clock, see B2 fix in
 * EmailOtpService::issue()/secondsUntilResend()). remainingCooldown() is
 * the bounds-checked arithmetic extracted out of that method so it can be
 * exercised here without booting the app.
 */

test('remainingCooldown: no time elapsed = full cooldown', function () {
    check_same(60, EmailOtpService::remainingCooldown(0));
});

test('remainingCooldown: partial elapsed = remainder', function () {
    check_same(25, EmailOtpService::remainingCooldown(35));
});

test('remainingCooldown: exactly at cooldown = 0', function () {
    check_same(0, EmailOtpService::remainingCooldown(60));
});

test('remainingCooldown: past cooldown never goes negative', function () {
    check_same(0, EmailOtpService::remainingCooldown(61));
    check_same(0, EmailOtpService::remainingCooldown(999));
});
