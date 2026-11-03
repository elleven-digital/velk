<?php

declare(strict_types=1);

namespace Velk\Models;

use Velk\App;

/**
 * One-shot password reset token for site members.
 *
 * Mirrors PasswordReset but lives in its own table (member_password_resets)
 * so admin reset tokens and member reset tokens can never be confused with
 * each other — important defense-in-depth: an attacker who somehow obtains
 * a hash from one realm can't use it against the other.
 *
 * Storage rule: we store only the SHA-256 hash of the token. The plaintext
 * is returned to the caller exactly once (to email it). Lookup then
 * re-hashes the incoming token and compares — same idea as how password
 * hashing keeps plaintext off disk.
 */
final class MemberPasswordReset
{
    public int $id;
    public int $memberId;
    public string $tokenHash;
    public string $expiresAt;
    public ?string $usedAt = null;
    public ?string $ip = null;
    public ?string $userAgent = null;
    public string $createdAt;

    public const TTL_SECONDS = 3600; // 60 minutes — same as admin reset

    public static function fromRow(array $row): self
    {
        $r = new self();
        $r->id = (int) $row['id'];
        $r->memberId = (int) $row['member_id'];
        $r->tokenHash = (string) $row['token_hash'];
        $r->expiresAt = (string) $row['expires_at'];
        $r->usedAt = $row['used_at'] !== null ? (string) $row['used_at'] : null;
        $r->ip = $row['ip'] !== null ? (string) $row['ip'] : null;
        $r->userAgent = $row['user_agent'] !== null ? (string) $row['user_agent'] : null;
        $r->createdAt = (string) $row['created_at'];
        return $r;
    }

    /**
     * Issue a fresh reset token for a member. Invalidates any pending
     * (unused, unexpired) tokens for the same member first so only the
     * latest works — same UX as the admin flow.
     *
     * Returns the plaintext token. Caller is responsible for emailing
     * it; the DB only stores the hash.
     */
    public static function issueFor(Member $member, ?string $ip = null, ?string $userAgent = null): string
    {
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);

        $db = App::instance()->db;

        $db->query(
            'UPDATE member_password_resets SET used_at = NOW()
             WHERE member_id = ? AND used_at IS NULL AND expires_at > NOW()',
            [$member->id]
        );

        // expires_at is computed by MySQL (DATE_ADD ... NOW()), not PHP's
        // date()/time() — findValidByToken() checks it against MySQL's
        // NOW() too, so both sides must share one clock. A PHP-clock write
        // compared against a MySQL-clock read breaks the instant the two
        // run in different time zones (e.g. APP_TIMEZONE=America/Sao_Paulo
        // with a UTC MySQL server): the token would be born already expired.
        $db->query(
            'INSERT INTO member_password_resets (member_id, token_hash, expires_at, ip, user_agent)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?, ?)',
            [
                $member->id,
                $hash,
                self::TTL_SECONDS,
                $ip,
                $userAgent !== null ? mb_substr($userAgent, 0, 500) : null,
            ]
        );

        return $token;
    }

    public static function findValidByToken(string $token): ?self
    {
        if ($token === '') return null;
        $hash = hash('sha256', $token);
        $row = App::instance()->db->fetch(
            'SELECT * FROM member_password_resets
             WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1',
            [$hash]
        );
        return $row === null ? null : self::fromRow($row);
    }

    public function member(): ?Member
    {
        return Member::find($this->memberId);
    }

    public function consume(): void
    {
        // NOW() (not PHP's date()) — same single-clock rule as issueFor().
        App::instance()->db->query('UPDATE member_password_resets SET used_at = NOW() WHERE id = ?', [$this->id]);
    }

    public static function purge(): int
    {
        return App::instance()->db->query(
            'DELETE FROM member_password_resets
             WHERE expires_at < NOW()
                OR (used_at IS NOT NULL AND used_at < (NOW() - INTERVAL 7 DAY))'
        )->rowCount();
    }
}
