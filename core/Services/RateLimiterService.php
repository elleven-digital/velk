<?php

declare(strict_types=1);

namespace Velk\Services;

use Velk\App;

/**
 * Generic request-count throttle for endpoints that have no pass/fail
 * concept — just "how many times did this happen in the window" —
 * unlike LoginAttemptService/MemberLoginAttemptService, which track
 * credential guessing (succeeded vs failed, dual email+IP threshold).
 *
 * One instance = one (bucket, window, cap) triple. Callers construct a
 * fresh instance per call site with their own bucket name so unrelated
 * limiters never share counts, e.g.:
 *
 *   $limiter = new RateLimiterService('member_register_ip', 15, 5);
 *   if ($limiter->checkLock($ip)['locked']) { ...silently drop... }
 *   $limiter->record($ip);
 *
 * Used by:
 *   - PasswordResetController::forgot() / MemberController::
 *     passwordResetRequest() — bucket keyed by IP (primary) and email
 *     (secondary), guards against mail-bombing one inbox (I13).
 *   - MemberController::register() — bucket keyed by IP, guards
 *     against mass account creation (M-5).
 *
 * Same check-before-record ordering as LoginAttemptService: checkLock()
 * never itself counts as a hit, and callers skip record() entirely
 * when already locked — a throttled attacker's own repeated hits don't
 * keep pushing the lock window forward (see AuthController::login()'s
 * identical ordering + comment).
 *
 * Same SQL-clock discipline as every other throttle in this codebase:
 * all time math is NOW()/DATE_SUB on the DB side, never PHP's date()
 * compared against a DB timestamp.
 */
final class RateLimiterService
{
    public function __construct(
        private readonly string $bucket,
        private readonly int $windowMinutes,
        private readonly int $maxHits,
    ) {
    }

    /**
     * Decide whether $key is currently throttled.
     *
     * @return array{locked: bool, retry_after_seconds: int}
     */
    public function checkLock(string $key): array
    {
        $db = App::instance()->db;
        $key = mb_substr($key, 0, 255);

        $hits = (int) $db->fetchColumn(
            'SELECT COUNT(*) FROM rate_limit_hits
             WHERE bucket = ? AND key_value = ?
               AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)',
            [$this->bucket, $key, $this->windowMinutes]
        );

        if ($hits < $this->maxHits) {
            return ['locked' => false, 'retry_after_seconds' => 0];
        }

        $row = $db->fetch(
            'SELECT created_at FROM rate_limit_hits
             WHERE bucket = ? AND key_value = ?
               AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
             ORDER BY created_at ASC LIMIT 1',
            [$this->bucket, $key, $this->windowMinutes]
        );

        $oldestEpoch = null;
        if ($row !== null && isset($row['created_at'])) {
            $parsed = strtotime((string) $row['created_at']);
            $oldestEpoch = $parsed !== false ? $parsed : null;
        }

        return self::decide($hits, $this->maxHits, $this->windowMinutes * 60, $oldestEpoch, time());
    }

    /**
     * Pure threshold decision, extracted out of checkLock() so it's
     * unit-testable without a DB connection — mirrors
     * EmailOtpService::remainingCooldown() and the inline math in
     * LoginAttemptService::checkLock(), just given as explicit inputs
     * instead of read live from the two queries above.
     *
     * @return array{locked: bool, retry_after_seconds: int}
     */
    public static function decide(int $hits, int $maxHits, int $windowSeconds, ?int $oldestHitEpoch, int $nowEpoch): array
    {
        if ($hits < $maxHits) {
            return ['locked' => false, 'retry_after_seconds' => 0];
        }

        $retryAfter = $windowSeconds;
        if ($oldestHitEpoch !== null) {
            $retryAfter = max(1, ($oldestHitEpoch + $windowSeconds) - $nowEpoch);
        }

        return ['locked' => true, 'retry_after_seconds' => $retryAfter];
    }

    /** Record one hit for $key. */
    public function record(string $key): void
    {
        App::instance()->db->insert('rate_limit_hits', [
            'bucket' => $this->bucket,
            'key_value' => mb_substr($key, 0, 255),
        ]);
    }

    /**
     * Drop hits older than $days for ALL buckets. Returns rows deleted.
     * Manual/cron use only — see bin/velk cleanup:rate-limit-hits.
     */
    public function cleanup(int $days = 30): int
    {
        $days = max(1, $days);
        $stmt = App::instance()->db->query(
            'DELETE FROM rate_limit_hits WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [$days]
        );
        return $stmt->rowCount();
    }
}
