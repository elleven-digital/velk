<?php

declare(strict_types=1);

namespace Velk;

use Velk\Models\User;

final class Auth
{
    /** Carimbo do último uso do painel, na sessão. */
    public const SEEN_KEY = '_admin_seen_at';

    /** Padrão de ADMIN_SESSION_LIFETIME: 2 dias sem usar o painel. */
    public const DEFAULT_LIFETIME = 60 * 60 * 24 * 2;

    private Database $db;
    private Session $session;
    private ?User $cachedUser = null;
    private int $lifetime;
    private bool $expired = false;

    /**
     * `$lifetime`: segundos sem usar o painel até o login cair
     * (ADMIN_SESSION_LIFETIME). Contados do último uso — ver touch().
     */
    public function __construct(Database $db, Session $session, int $lifetime = self::DEFAULT_LIFETIME)
    {
        $this->db = $db;
        $this->session = $session;
        // Zero ou negativo é configuração quebrada: vale o padrão, não "cair já".
        $this->lifetime = $lifetime > 0 ? max(60, $lifetime) : self::DEFAULT_LIFETIME;
    }

    /**
     * Check credentials WITHOUT creating a session. Returns the User on
     * success, null on failure. Split from attempt() so the 2FA flow
     * can verify the password first and only establish the session
     * after the emailed code checks out.
     */
    public function validate(string $email, string $password): ?User
    {
        $row = $this->db->fetch(
            'SELECT * FROM users WHERE email = ? LIMIT 1',
            [strtolower(trim($email))]
        );

        if ($row === null) {
            // Dummy verify keeps timing roughly constant between "email
            // not found" and "wrong password" — same as MemberAuth.
            password_verify($password, '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinva');
            return null;
        }

        if (!password_verify($password, $row['password_hash'])) {
            return null;
        }

        return User::fromRow($row);
    }

    /**
     * Establish the session for an already-verified user. Regenerates
     * the session id (fixation defense). Callers are responsible for
     * having ACTUALLY verified the user — via validate() or a completed
     * 2FA challenge.
     */
    public function login(User $user): void
    {
        $this->session->regenerate();
        $this->session->put('user_id', $user->id);
        $this->session->put(self::SEEN_KEY, time());
        $this->cachedUser = $user;
        $this->expired = false;
    }

    /**
     * One-step credential check + session. Kept for flows without 2FA
     * (and for BC) — composes the two halves above.
     */
    public function attempt(string $email, string $password): bool
    {
        $user = $this->validate($email, $password);
        if ($user === null) {
            return false;
        }
        $this->login($user);
        return true;
    }

    /**
     * Há um admin logado — e dentro do limite de inatividade. Passado o
     * limite, o login do admin sai da sessão (o de membro, se houver, fica).
     * Só confere: quem conta como uso é touch(), chamado pelas telas do
     * painel; o site público perguntar se há admin (a prévia de rascunho)
     * não renova nada.
     */
    public function check(): bool
    {
        if (!$this->session->has('user_id')) {
            return false;
        }
        if ($this->session->idleExpired(self::SEEN_KEY, $this->lifetime)) {
            $this->session->forget('user_id');
            $this->cachedUser = null;
            $this->expired = true;
            return false;
        }
        return true;
    }

    /** O login caiu por inatividade nesta requisição? (O painel avisa no login.) */
    public function expiredThisRequest(): bool
    {
        return $this->expired;
    }

    /**
     * Uso do painel: renova o prazo. Chamado pelo construtor das telas do
     * admin (Controller) — abrir, salvar, subir um arquivo, reordenar.
     */
    public function touch(): void
    {
        if ($this->check()) {
            $this->session->markActive(self::SEEN_KEY);
        }
    }

    public function user(): ?User
    {
        if ($this->cachedUser !== null) {
            return $this->cachedUser;
        }
        if (!$this->check()) {
            return null;
        }

        $userId = $this->session->get('user_id');
        if (!is_int($userId) && !is_numeric($userId)) {
            return null;
        }

        $row = $this->db->fetch('SELECT * FROM users WHERE id = ? LIMIT 1', [(int) $userId]);
        if ($row === null) {
            $this->session->forget('user_id');
            return null;
        }

        $this->cachedUser = User::fromRow($row);
        return $this->cachedUser;
    }

    public function logout(): void
    {
        $this->session->forget('user_id');
        $this->session->forget(self::SEEN_KEY);
        $this->session->regenerate();
        $this->cachedUser = null;
    }
}
