<?php

declare(strict_types=1);

namespace Velk;

use Velk\Models\Member;

/**
 * Auth handler for site members — parallel to the admin Auth class but
 * operating on the `members` table and using a distinct session key.
 *
 * The two auth chains share the same PHP session (cookie name
 * `velk_session`) but namespace their state under different keys:
 *   - admin: $_SESSION['user_id']    (handled by Velk\Auth)
 *   - member: $_SESSION['member_id'] (handled here)
 *
 * That means an admin who's also testing as a member can stay logged
 * into both simultaneously — useful for previewing logged-in pages
 * from the admin without juggling browsers/profiles.
 */
final class MemberAuth
{
    /** Carimbo do último uso do site pelo membro, na sessão. */
    public const SEEN_KEY = '_member_seen_at';

    /** Padrão de MEMBER_SESSION_LIFETIME: 30 dias sem usar o site. */
    public const DEFAULT_LIFETIME = 60 * 60 * 24 * 30;

    private Database $db;
    private Session $session;
    private ?Member $cachedMember = null;
    private int $lifetime;

    /**
     * `$lifetime`: segundos sem usar o site até o login do membro cair
     * (MEMBER_SESSION_LIFETIME), contados do último uso.
     */
    public function __construct(Database $db, Session $session, int $lifetime = self::DEFAULT_LIFETIME)
    {
        $this->db = $db;
        $this->session = $session;
        // Zero ou negativo é configuração quebrada: vale o padrão, não "cair já".
        $this->lifetime = $lifetime > 0 ? max(60, $lifetime) : self::DEFAULT_LIFETIME;
    }

    /**
     * Check credentials WITHOUT creating a session. Returns the Member
     * on success, null on failure. The 2FA flow needs this split: the
     * password gets verified first, the session only starts after the
     * emailed code checks out. Rate-limiting is caller-side (the
     * MemberController consults MemberLoginAttemptService) — keeping
     * this layer focused on credential verification.
     */
    public function validate(string $email, string $password): ?Member
    {
        $row = $this->db->fetch(
            'SELECT * FROM members WHERE email = ? LIMIT 1',
            [strtolower(trim($email))]
        );

        if ($row === null) {
            // Run a dummy password_verify to keep the timing roughly
            // constant between "user exists, wrong password" and "user
            // doesn't exist" — small but worth the line.
            password_verify($password, '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinva');
            return null;
        }

        if (!password_verify($password, $row['password_hash'])) {
            return null;
        }

        return Member::fromRow($row);
    }

    /**
     * One-step credential check + session — for flows without 2FA.
     */
    public function attempt(string $email, string $password): bool
    {
        $member = $this->validate($email, $password);
        if ($member === null) {
            return false;
        }
        $this->loginAs($member);
        return true;
    }

    /**
     * Log in by Member object — used after a successful registration
     * or password-reset completion, where the credential check has
     * already been done implicitly.
     */
    public function loginAs(Member $member): void
    {
        $this->session->regenerate();
        $this->session->put('member_id', $member->id);
        $this->session->put(self::SEEN_KEY, time());
        $this->cachedMember = $member;
        $member->touchLogin();
    }

    /**
     * Há um membro logado — e dentro do limite de inatividade, que cada
     * consulta renova: para o membro, usar o site é qualquer página que leia
     * o login dele (o cabeçalho com "Minha conta", uma área restrita).
     * Passado o limite, o login do membro sai da sessão (o do admin fica).
     */
    public function check(): bool
    {
        if (!$this->session->has('member_id')) {
            return false;
        }
        if ($this->session->idleExpired(self::SEEN_KEY, $this->lifetime)) {
            $this->session->forget('member_id');
            $this->cachedMember = null;
            return false;
        }
        $this->session->markActive(self::SEEN_KEY);
        return true;
    }

    public function member(): ?Member
    {
        if ($this->cachedMember !== null) {
            return $this->cachedMember;
        }
        if (!$this->check()) {
            return null;
        }

        $memberId = $this->session->get('member_id');
        if (!is_int($memberId) && !is_numeric($memberId)) {
            return null;
        }

        $row = $this->db->fetch('SELECT * FROM members WHERE id = ? LIMIT 1', [(int) $memberId]);
        if ($row === null) {
            // Member was deleted while logged in — clear the stale session.
            $this->session->forget('member_id');
            return null;
        }

        $this->cachedMember = Member::fromRow($row);
        return $this->cachedMember;
    }

    public function logout(): void
    {
        $this->session->forget('member_id');
        $this->session->forget(self::SEEN_KEY);
        $this->session->regenerate();
        $this->cachedMember = null;
    }
}
