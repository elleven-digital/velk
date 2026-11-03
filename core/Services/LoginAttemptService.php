<?php

declare(strict_types=1);

namespace Velk\Services;

use Velk\App;

/**
 * Brute-force throttle for the login form.
 *
 * Two-dimension rate limit, both within a 15-minute rolling window:
 *   - Per email: 5 failed attempts → locked
 *   - Per IP:    20 failed attempts → locked
 *
 * Email and IP both being checked covers the two main attack shapes:
 *   - Many IPs against one account (credential stuffing) → email threshold
 *   - One IP against many accounts (spray) → IP threshold
 *
 * Why these numbers: 5 per email is tight enough to stop a serious
 * brute-force (5 tries/15min = 480/day, against a 1-in-millions search
 * space is nothing). 20 per IP is loose enough to allow shared-office
 * scenarios where multiple employees might mistype passwords on the
 * same network.
 *
 * Successful logins are recorded too (`succeeded=true`), so genuine
 * users who eventually got their password right stop counting against
 * the threshold — the lock query filters on `succeeded = FALSE`.
 *
 * Cleanup is NOT inline. Use `bin/velk cleanup:login-attempts` manually
 * (via SSH) or via cron. The throttle works correctly even if the table
 * is never cleaned because queries always filter by time window.
 */
final class LoginAttemptService
{
    /** Minutes of history considered for lockout decisions. */
    private const WINDOW_MINUTES = 15;

    /** Failed attempts in the window before email-level lock kicks in. */
    private const EMAIL_THRESHOLD = 5;

    /** Failed attempts in the window before IP-level lock kicks in. */
    private const IP_THRESHOLD = 20;

    /**
     * Record a login attempt. Both successes and failures are stored;
     * the throttle query distinguishes via the succeeded flag.
     */
    public function record(string $email, string $ip, bool $succeeded): void
    {
        App::instance()->db->insert('login_attempts', [
            'email' => mb_substr($email, 0, 255),
            'ip' => mb_substr($ip, 0, 45),
            'succeeded' => $succeeded ? 1 : 0,
        ]);
    }

    /**
     * Decide whether the given email/IP pair is currently throttled.
     *
     * Returns ['locked' => bool, 'retry_after_seconds' => int].
     * When locked, retry_after_seconds is the wall-clock seconds until
     * the oldest failure in the window expires — at that point the
     * count drops back below threshold and the lock releases naturally.
     *
     * The query reads two counts (per-email + per-IP). Both are O(log
     * n) with the indexes from the migration, so this is cheap even
     * with millions of attempt rows.
     */
    public function checkLock(string $email, string $ip): array
    {
        $db = App::instance()->db;

        $emailFails = (int) $db->fetchColumn(
            'SELECT COUNT(*) FROM login_attempts
             WHERE email = ? AND succeeded = 0
               AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)',
            [$email, self::WINDOW_MINUTES]
        );

        $ipFails = (int) $db->fetchColumn(
            'SELECT COUNT(*) FROM login_attempts
             WHERE ip = ? AND succeeded = 0
               AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)',
            [$ip, self::WINDOW_MINUTES]
        );

        $emailLocked = $emailFails >= self::EMAIL_THRESHOLD;
        $ipLocked = $ipFails >= self::IP_THRESHOLD;

        if (!$emailLocked && !$ipLocked) {
            return ['locked' => false, 'retry_after_seconds' => 0];
        }

        // Find when the relevant lock will release. We pick whichever
        // of the two dimensions is locked, take the OLDEST failure in
        // that dimension's window — the lock releases when it slides
        // out of the window.
        $row = null;
        if ($emailLocked) {
            $row = $db->fetch(
                'SELECT attempted_at FROM login_attempts
                 WHERE email = ? AND succeeded = 0
                   AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
                 ORDER BY attempted_at ASC LIMIT 1',
                [$email, self::WINDOW_MINUTES]
            );
        } elseif ($ipLocked) {
            $row = $db->fetch(
                'SELECT attempted_at FROM login_attempts
                 WHERE ip = ? AND succeeded = 0
                   AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
                 ORDER BY attempted_at ASC LIMIT 1',
                [$ip, self::WINDOW_MINUTES]
            );
        }

        $retryAfter = self::WINDOW_MINUTES * 60;
        if ($row !== null && isset($row['attempted_at'])) {
            $oldest = strtotime((string) $row['attempted_at']);
            $expiresAt = $oldest + (self::WINDOW_MINUTES * 60);
            $retryAfter = max(1, $expiresAt - time());
        }

        return ['locked' => true, 'retry_after_seconds' => $retryAfter];
    }

    /**
     * Drop attempt rows older than $days. Returns the number deleted.
     *
     * Called from `bin/velk cleanup:login-attempts` only — there's no
     * inline GC. Default 30 days is plenty: anything older than the
     * 15-minute window is dead weight for throttling purposes; the
     * extra retention exists for forensic audit of past brute-force
     * attempts if you want to look at the table after a security
     * incident.
     */
    public function cleanup(int $days = 30): int
    {
        $days = max(1, $days);
        $stmt = App::instance()->db->query(
            'DELETE FROM login_attempts
             WHERE attempted_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [$days]
        );
        return $stmt->rowCount();
    }
}
