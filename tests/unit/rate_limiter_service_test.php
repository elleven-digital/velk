<?php
declare(strict_types=1);
use Velk\Services\RateLimiterService;

/**
 * RateLimiterService::decide() is the pure allow/deny math extracted
 * out of checkLock() (which needs a DB for the two COUNT/SELECT
 * queries) — same split as EmailOtpService::remainingCooldown() vs
 * secondsUntilResend(). Exercises the I13/M-5 throttle decision without
 * booting the app.
 */

test('decide: under the cap is never locked', function () {
    $r = RateLimiterService::decide(hits: 4, maxHits: 5, windowSeconds: 900, oldestHitEpoch: null, nowEpoch: 1000);
    check_same(false, $r['locked']);
    check_same(0, $r['retry_after_seconds']);
});

test('decide: zero hits is never locked', function () {
    $r = RateLimiterService::decide(hits: 0, maxHits: 5, windowSeconds: 900, oldestHitEpoch: null, nowEpoch: 1000);
    check_same(false, $r['locked']);
});

test('decide: exactly at the cap locks (>= threshold, matches LoginAttemptService)', function () {
    $r = RateLimiterService::decide(hits: 5, maxHits: 5, windowSeconds: 900, oldestHitEpoch: null, nowEpoch: 1000);
    check_same(true, $r['locked']);
});

test('decide: over the cap locks', function () {
    $r = RateLimiterService::decide(hits: 9, maxHits: 5, windowSeconds: 900, oldestHitEpoch: null, nowEpoch: 1000);
    check_same(true, $r['locked']);
});

test('decide: locked with no oldest-hit info falls back to the full window', function () {
    $r = RateLimiterService::decide(hits: 5, maxHits: 5, windowSeconds: 900, oldestHitEpoch: null, nowEpoch: 1000);
    check_same(900, $r['retry_after_seconds']);
});

test('decide: retry_after counts down to when the oldest hit ages out of the window', function () {
    // oldest hit at t=100, 900s window -> expires at t=1000; now=700 -> 300s left.
    $r = RateLimiterService::decide(hits: 5, maxHits: 5, windowSeconds: 900, oldestHitEpoch: 100, nowEpoch: 700);
    check_same(true, $r['locked']);
    check_same(300, $r['retry_after_seconds']);
});

test('decide: retry_after never goes below 1 even if the oldest hit is already past expiry', function () {
    // oldest hit expires at t=1000 (100+900), but now=2000 is already past it
    // (e.g. clock skew or a slow query) — must still report a positive wait,
    // not 0 (0 would read as "not locked" to a naive caller) or negative.
    $r = RateLimiterService::decide(hits: 5, maxHits: 5, windowSeconds: 900, oldestHitEpoch: 100, nowEpoch: 2000);
    check_same(true, $r['locked']);
    check_same(1, $r['retry_after_seconds']);
});

test('decide: retry_after at the exact expiry instant is 1, not 0', function () {
    $r = RateLimiterService::decide(hits: 5, maxHits: 5, windowSeconds: 900, oldestHitEpoch: 100, nowEpoch: 1000);
    check_same(1, $r['retry_after_seconds']);
});
