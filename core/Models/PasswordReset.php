<?php

declare(strict_types=1);

namespace Velk\Models;

use Velk\App;

final class PasswordReset
{
    public int $id;
    public int $userId;
    public string $tokenHash;
    public string $expiresAt;
    public ?string $usedAt = null;
    public ?string $ip = null;
    public ?string $userAgent = null;
    public string $createdAt;

    public const TTL_SECONDS = 3600; // 60 minutes

    public static function fromRow(array $row): self
    {
        $r = new self();
        $r->id = (int) $row['id'];
        $r->userId = (int) $row['user_id'];
        $r->tokenHash = (string) $row['token_hash'];
        $r->expiresAt = (string) $row['expires_at'];
        $r->usedAt = $row['used_at'] !== null ? (string) $row['used_at'] : null;
        $r->ip = $row['ip'] !== null ? (string) $row['ip'] : null;
        $r->userAgent = $row['user_agent'] !== null ? (string) $row['user_agent'] : null;
        $r->createdAt = (string) $row['created_at'];
        return $r;
    }

    /**
     * Issue a fresh reset token for a user. Invalidates any pending
     * (unused, unexpired) tokens for the same user first.
     *
     * Returns the plaintext token — caller emails it to the user. The DB
     * stores only the hash.
     */
    public static function issueFor(User $user, ?string $ip = null, ?string $userAgent = null): string
    {
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);

        $db = App::instance()->db;

        // Mark any active tokens for this user as used so only the latest works.
        $db->query(
            'UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL AND expires_at > NOW()',
            [$user->id]
        );

        // expires_at is computed by MySQL (DATE_ADD ... NOW()), not PHP's
        // date()/time() — findValidByToken() checks it against MySQL's
        // NOW() too, so both sides must share one clock. A PHP-clock write
        // compared against a MySQL-clock read breaks the instant the two
        // run in different time zones (e.g. APP_TIMEZONE=America/Sao_Paulo
        // with a UTC MySQL server): the token would be born already expired.
        $db->query(
            'INSERT INTO password_resets (user_id, token_hash, expires_at, ip, user_agent)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?, ?)',
            [
                $user->id,
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
            'SELECT * FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1',
            [$hash]
        );
        return $row === null ? null : self::fromRow($row);
    }

    public function user(): ?User
    {
        return User::find($this->userId);
    }

    public function consume(): void
    {
        // NOW() (not PHP's date()) — same single-clock rule as issueFor().
        App::instance()->db->query('UPDATE password_resets SET used_at = NOW() WHERE id = ?', [$this->id]);
    }

    /** Garbage-collect expired/used tokens. Cheap; called opportunistically. */
    public static function purge(): int
    {
        return App::instance()->db->query(
            'DELETE FROM password_resets WHERE expires_at < NOW() OR (used_at IS NOT NULL AND used_at < (NOW() - INTERVAL 7 DAY))'
        )->rowCount();
    }
}
