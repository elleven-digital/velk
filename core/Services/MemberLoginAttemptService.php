<?php

declare(strict_types=1);

namespace Velk\Services;

use Velk\App;

/**
 * Brute-force throttle for the member login form.
 *
 * Mirrors LoginAttemptService (admin) but operates on the separate
 * member_login_attempts table. Keeping the tables apart means a spray
 * attack against many member emails won't lock out the admin's IP or
 * vice versa — important when both auth flows are exposed.
 *
 * Same thresholds as admin (5/email, 20/IP, 15-min window). The
 * rationale is identical; rate-limit semantics shouldn't change just
 * because the realm did.
 */
final class MemberLoginAttemptService
{
    private const WINDOW_MINUTES = 15;
    private const EMAIL_THRESHOLD = 5;
    private const IP_THRESHOLD = 20;

    public function record(string $email, string $ip, bool $succeeded): void
    {
        App::instance()->db->insert('member_login_attempts', [
            'email' => mb_substr($email, 0, 255),
            'ip' => mb_substr($ip, 0, 45),
            'succeeded' => $succeeded ? 1 : 0,
        ]);
    }

    /** @return array{locked: bool, retry_after_seconds: int} */
    public function checkLock(string $email, string $ip): array
    {
        $db = App::instance()->db;

        $emailFails = (int) $db->fetchColumn(
            'SELECT COUNT(*) FROM member_login_attempts
             WHERE email = ? AND succeeded = 0
               AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)',
            [$email, self::WINDOW_MINUTES]
        );

        $ipFails = (int) $db->fetchColumn(
            'SELECT COUNT(*) FROM member_login_attempts
             WHERE ip = ? AND succeeded = 0
               AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)',
            [$ip, self::WINDOW_MINUTES]
        );

        $emailLocked = $emailFails >= self::EMAIL_THRESHOLD;
        $ipLocked = $ipFails >= self::IP_THRESHOLD;

        if (!$emailLocked && !$ipLocked) {
            return ['locked' => false, 'retry_after_seconds' => 0];
        }

        $row = null;
        if ($emailLocked) {
            $row = $db->fetch(
                'SELECT attempted_at FROM member_login_attempts
                 WHERE email = ? AND succeeded = 0
                   AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
                 ORDER BY attempted_at ASC LIMIT 1',
                [$email, self::WINDOW_MINUTES]
            );
        } elseif ($ipLocked) {
            $row = $db->fetch(
                'SELECT attempted_at FROM member_login_attempts
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

    public function cleanup(int $days = 30): int
    {
        $days = max(1, $days);
        $stmt = App::instance()->db->query(
            'DELETE FROM member_login_attempts
             WHERE attempted_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [$days]
        );
        return $stmt->rowCount();
    }
}
