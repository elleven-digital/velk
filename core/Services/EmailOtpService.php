<?php

declare(strict_types=1);

namespace Velk\Services;

use Velk\App;
use Velk\Mailer;
use Velk\View;

/**
 * One-time codes by email — the second factor for both auth chains.
 *
 * The same service handles admin users and site members; callers pass
 * a subject type ('user' | 'member') + id. Codes are 6 digits, valid
 * for 10 minutes, and stored ONLY as bcrypt hashes — the plaintext
 * exists in memory just long enough to be emailed.
 *
 * Abuse limits, and why each exists:
 *   - MAX_ATTEMPTS (5) wrong guesses kill the code. Bounds online
 *     brute-force: 5 guesses against a 900k space per code.
 *   - MAX_CODES_PER_HOUR (5) bounds total guessing throughput
 *     (5 codes × 5 attempts = 25 guesses/hour) AND prevents using the
 *     login form to bombard someone's inbox.
 *   - RESEND_COOLDOWN (60s) keeps the "resend code" button from
 *     double-sending when mail is just slow.
 *
 * Issuing a new code invalidates pending ones for the same subject —
 * only the latest code is ever valid, so a stolen older email can't
 * race a fresh one.
 */
final class EmailOtpService
{
    public const TTL_MINUTES = 10;
    private const MAX_ATTEMPTS = 5;
    private const MAX_CODES_PER_HOUR = 5;
    private const RESEND_COOLDOWN_SECONDS = 60;

    /**
     * Generate, store (hashed) and return a fresh code for the subject.
     * Returns NULL when the hourly issuing cap is hit — the caller
     * should tell the user to wait, without revealing internals.
     */
    public function issue(string $subjectType, int $subjectId, ?string $ip = null): ?string
    {
        $db = App::instance()->db;

        $issuedLastHour = (int) $db->fetchColumn(
            'SELECT COUNT(*) FROM email_otp_codes
             WHERE subject_type = ? AND subject_id = ?
               AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)',
            [$subjectType, $subjectId]
        );
        if ($issuedLastHour >= self::MAX_CODES_PER_HOUR) {
            return null;
        }

        // Supersede any pending code — exactly one valid code per subject.
        $db->query(
            'UPDATE email_otp_codes SET used_at = NOW()
             WHERE subject_type = ? AND subject_id = ? AND used_at IS NULL',
            [$subjectType, $subjectId]
        );

        $code = (string) random_int(100000, 999999);

        // expires_at is computed by MySQL (DATE_ADD ... NOW()), not PHP's
        // date()/time() — verify() checks it against MySQL's NOW() too, so
        // both sides must share one clock. A PHP-clock write compared
        // against a MySQL-clock read breaks the instant the two run in
        // different time zones (e.g. APP_TIMEZONE=America/Sao_Paulo with a
        // UTC MySQL server): the code would be born already expired.
        $db->query(
            'INSERT INTO email_otp_codes (subject_type, subject_id, code_hash, expires_at, ip)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?)',
            [
                $subjectType,
                $subjectId,
                password_hash($code, PASSWORD_DEFAULT),
                self::TTL_MINUTES * 60,
                $ip !== null ? mb_substr($ip, 0, 45) : null,
            ]
        );

        return $code;
    }

    /**
     * Check a submitted code. Constant-time comparison via
     * password_verify; each wrong guess increments `attempts` and the
     * code dies after MAX_ATTEMPTS. Success consumes the code (single
     * use) — replaying the same code fails.
     */
    public function verify(string $subjectType, int $subjectId, string $code): bool
    {
        $db = App::instance()->db;

        $row = $db->fetch(
            'SELECT * FROM email_otp_codes
             WHERE subject_type = ? AND subject_id = ?
               AND used_at IS NULL AND expires_at > NOW()
             ORDER BY id DESC LIMIT 1',
            [$subjectType, $subjectId]
        );

        if ($row === null) {
            // No pending code — burn comparable time anyway so "code
            // expired" and "wrong code" aren't distinguishable by timing.
            password_verify($code, '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinva');
            return false;
        }

        if ((int) $row['attempts'] >= self::MAX_ATTEMPTS) {
            // Defensive: should already be marked used by the increment
            // path below, but never trust a counter you didn't just read.
            // NOW() (not PHP's date()) — same single-clock rule as issue().
            $db->query('UPDATE email_otp_codes SET used_at = NOW() WHERE id = ?', [(int) $row['id']]);
            return false;
        }

        if (!password_verify(trim($code), (string) $row['code_hash'])) {
            $attempts = (int) $row['attempts'] + 1;
            if ($attempts >= self::MAX_ATTEMPTS) {
                $db->query(
                    'UPDATE email_otp_codes SET attempts = ?, used_at = NOW() WHERE id = ?',
                    [$attempts, (int) $row['id']]
                );
            } else {
                $db->update('email_otp_codes', ['attempts' => $attempts], ['id' => (int) $row['id']]);
            }
            return false;
        }

        $db->query('UPDATE email_otp_codes SET used_at = NOW() WHERE id = ?', [(int) $row['id']]);

        // Opportunistic GC — same pattern as MemberPasswordReset::purge().
        // Rows older than a day are useless (TTL is 10 min); deleting on
        // the success path keeps the table tiny without a cron.
        $db->query("DELETE FROM email_otp_codes WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");

        return true;
    }

    /**
     * Seconds the subject must still wait before another code may be
     * sent. 0 = allowed now. Drives the resend button cooldown.
     */
    public function secondsUntilResend(string $subjectType, int $subjectId): int
    {
        // Elapsed time is computed by MySQL (TIMESTAMPDIFF against its own
        // NOW()), not `time() - strtotime(created_at)` — that PHP-clock
        // computation against a MySQL-clock timestamp has the same
        // tz-split problem as the expiry writes above.
        $elapsed = App::instance()->db->fetchColumn(
            'SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) FROM email_otp_codes
             WHERE subject_type = ? AND subject_id = ?
             ORDER BY id DESC LIMIT 1',
            [$subjectType, $subjectId]
        );
        if ($elapsed === null) {
            return 0;
        }
        return self::remainingCooldown((int) $elapsed);
    }

    /**
     * Pure cooldown math, split out from secondsUntilResend() so it's
     * unit-testable without a DB connection.
     */
    public static function remainingCooldown(int $elapsedSeconds): int
    {
        return max(0, self::RESEND_COOLDOWN_SECONDS - $elapsedSeconds);
    }

    /**
     * Email the code. Throws (propagating Mailer's RuntimeException) on
     * delivery failure — callers must NOT advance to the code screen if
     * the email never left, or the user lands on a dead end.
     *
     * Renders in $locale (the RECIPIENT's locale) when given — e.g. an
     * EN-locale admin resending a code to a PT-locale colleague still
     * gets a PT email. Admin call sites pass the target user's locale;
     * member call sites omit it and fall back to the configured admin
     * default (member-side i18n is a separate, out-of-scope concern).
     * The active admin locale is saved/restored around the swap so a
     * send never leaks a locale change into the rest of the request.
     */
    public function sendCode(string $toEmail, ?string $name, string $code, ?string $locale = null): void
    {
        $app = App::instance();
        $i18n = $app->adminI18n;
        $prev = $i18n->current();
        $i18n->setCurrent($locale ?: (string) $app->config->get('admin.locale', 'en'));

        try {
            $siteName = (string) ($app->config->site('site.name') ?? 'Velk');

            $view = new View($app->config->path('core') . '/Admin/views');
            $html = $view->render('emails/otp-code', [
                'name'     => $name ?? $toEmail,
                'code'     => $code,
                'minutes'  => self::TTL_MINUTES,
                'siteName' => $siteName,
            ]);

            Mailer::fromEnv()->send(
                $toEmail,
                // I10: Subject header is plain text, not HTML — __() would
                // HTML-escape the site name (e.g. "Joe&#039;s &amp; Co").
                __raw('email.otp.subject', ['site' => $siteName]),
                $html
            );
        } finally {
            $i18n->setCurrent($prev);
        }
    }
}
