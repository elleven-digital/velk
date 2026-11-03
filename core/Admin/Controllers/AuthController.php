<?php

declare(strict_types=1);

namespace Velk\Admin\Controllers;

use Velk\Models\User;
use Velk\Request;
use Velk\Response;
use Velk\Services\EmailOtpService;
use Velk\Services\LoginAttemptService;

final class AuthController extends Controller
{
    /**
     * Session key holding the half-authenticated state between the
     * password step and the code step: ['id' => userId, 'expires' => ts].
     * Lives ONLY in the session — never in a hidden form field, so the
     * subject of the challenge can't be tampered with client-side.
     */
    private const PENDING_2FA_KEY = '_2fa_pending_user';

    public function login(Request $request): Response
    {
        if ($this->app->auth->check()) {
            return Response::redirect(admin_url(''));
        }

        $error = null;
        $email = '';

        if ($request->isPost()) {
            $csrf = $this->verifyCsrfOrFail($request);
            if ($csrf !== null) {
                return $csrf;
            }
            // Normalized ONCE, up front, and reused for BOTH throttle calls
            // and validate() below (I-3). Auth::validate() already
            // normalizes internally for its own DB lookup, but the raw
            // string used to reach the throttle unnormalized — so
            // "Admin@x", "admin@x ", "ADMIN@x" each got their own 5-try
            // bucket instead of sharing the one the account should have,
            // letting an attacker bypass the per-email lock by varying
            // case/whitespace. Mirrors how MemberController::login()
            // already normalizes before touching its throttle.
            $email = self::normalizeEmail((string) ($request->post['email'] ?? ''));
            $password = (string) ($request->post['password'] ?? '');
            $ip = $request->ip();

            $throttle = new LoginAttemptService();

            // Check rate limit BEFORE attempting auth. If locked, refuse
            // without even consulting the password — the attempt would
            // be recorded and could push the lock window further out.
            $lock = $throttle->checkLock($email, $ip);
            if ($lock['locked']) {
                $minutes = (int) ceil($lock['retry_after_seconds'] / 60);
                $error = $minutes === 1
                    ? __('auth.throttle_one', ['minutes' => $minutes])
                    : __('auth.throttle', ['minutes' => $minutes]);
            } else {
                // validate() checks the credential WITHOUT starting the
                // session — whether the session starts now or after the
                // emailed code depends on the user's 2FA setting.
                $user = $this->app->auth->validate($email, $password);
                $throttle->record($email, $ip, $user !== null);

                if ($user !== null && !$user->twoFactorEnabled) {
                    // Clear any stale half-login from a previous 2FA
                    // attempt in this browser — nobody should be able
                    // to resume an old challenge after a fresh login.
                    $this->app->session->forget(self::PENDING_2FA_KEY);
                    $this->app->auth->login($user);
                    return $this->redirectToIntended();
                }

                if ($user !== null && $user->twoFactorEnabled) {
                    $otp = new EmailOtpService();
                    $code = $otp->issue('user', $user->id, $ip);

                    if ($code === null) {
                        // Hourly issuing cap hit. Deliberately vague —
                        // an attacker with the password shouldn't learn
                        // the throttle's exact shape.
                        $error = __('auth.too_many_codes');
                    } else {
                        try {
                            $otp->sendCode($user->email, $user->name, $code, $user->locale);
                        } catch (\Throwable $e) {
                            // If the email never left, the code screen is
                            // a dead end — fail HERE, on the login form.
                            error_log('[Velk 2FA] send failed for user ' . $user->id . ': ' . $e->getMessage());
                            $error = __('auth.code_send_failed');
                        }

                        if ($error === null) {
                            $this->app->session->put(self::PENDING_2FA_KEY, [
                                'id'      => $user->id,
                                'expires' => time() + EmailOtpService::TTL_MINUTES * 60,
                            ]);
                            return Response::redirect(admin_url('login/two-factor'));
                        }
                    }
                }

                if ($user === null) {
                    $error = __('auth.invalid_credentials');
                }
            }
        }

        return $this->render('auth/login', [
            'email' => $email,
            'error' => $error,
        ], 'layout/auth');
    }

    /**
     * Second step: collect the emailed code. Only reachable while a
     * pending challenge exists in the session (i.e. the password was
     * already verified, minutes ago at most).
     */
    public function twoFactor(Request $request): Response
    {
        if ($this->app->auth->check()) {
            return Response::redirect(admin_url(''));
        }

        $pendingId = $this->pendingUserId();
        if ($pendingId === null) {
            return Response::redirect(admin_url('login'));
        }

        $user = User::find($pendingId);
        if ($user === null) {
            $this->app->session->forget(self::PENDING_2FA_KEY);
            return Response::redirect(admin_url('login'));
        }

        $error = null;

        if ($request->isPost()) {
            $csrf = $this->verifyCsrfOrFail($request);
            if ($csrf !== null) {
                return $csrf;
            }

            $code = trim((string) ($request->post['code'] ?? ''));
            $otp = new EmailOtpService();

            if ($code !== '' && $otp->verify('user', $user->id, $code)) {
                $this->app->session->forget(self::PENDING_2FA_KEY);
                $this->app->auth->login($user);
                return $this->redirectToIntended();
            }

            $error = __('auth.code_invalid');
        }

        return $this->render('auth/two-factor', [
            'maskedEmail' => self::maskEmail($user->email),
            'error'       => $error,
            'resendWait'  => (new EmailOtpService())->secondsUntilResend('user', $user->id),
        ], 'layout/auth');
    }

    /** POST-only "resend code" — new code, same pending challenge. */
    public function twoFactorResend(Request $request): Response
    {
        $csrf = $this->verifyCsrfOrFail($request);
        if ($csrf !== null) {
            return $csrf;
        }

        $pendingId = $this->pendingUserId();
        $user = $pendingId !== null ? User::find($pendingId) : null;
        if ($user === null) {
            return Response::redirect(admin_url('login'));
        }

        $otp = new EmailOtpService();

        if ($otp->secondsUntilResend('user', $user->id) > 0) {
            $this->app->session->flash('error', __('flash.resend_too_soon'));
            return Response::redirect(admin_url('login/two-factor'));
        }

        $code = $otp->issue('user', $user->id, $request->ip());
        if ($code === null) {
            $this->app->session->flash('error', __('flash.too_many_codes'));
            return Response::redirect(admin_url('login/two-factor'));
        }

        try {
            $otp->sendCode($user->email, $user->name, $code, $user->locale);
            // Issuing extends the challenge window to match the new code.
            $this->app->session->put(self::PENDING_2FA_KEY, [
                'id'      => $user->id,
                'expires' => time() + EmailOtpService::TTL_MINUTES * 60,
            ]);
            $this->app->session->flash('success', __('flash.code_resent'));
        } catch (\Throwable $e) {
            error_log('[Velk 2FA] resend failed for user ' . $user->id . ': ' . $e->getMessage());
            $this->app->session->flash('error', __('flash.code_resend_failed'));
        }

        return Response::redirect(admin_url('login/two-factor'));
    }

    public function logout(Request $request): Response
    {
        // verifyCsrfOrFail() returns a Response on failure (the 403 page)
        // and null on success. Capturing the return value is mandatory —
        // without it, an invalid token still falls through to logout(),
        // letting an attacker force-logout users via cross-site forms
        // (SameSite=Lax doesn't block top-level POSTs to same-site URLs).
        $csrf = $this->verifyCsrfOrFail($request);
        if ($csrf !== null) {
            return $csrf;
        }

        $this->app->auth->logout();
        return Response::redirect(admin_url('login'));
    }

    // ─── Internals ────────────────────────────────────────────────

    /** Valid (non-expired) pending 2FA user id, or null. */
    private function pendingUserId(): ?int
    {
        $pending = $this->app->session->get(self::PENDING_2FA_KEY);
        if (!is_array($pending) || !isset($pending['id'], $pending['expires'])) {
            return null;
        }
        if ((int) $pending['expires'] < time()) {
            $this->app->session->forget(self::PENDING_2FA_KEY);
            return null;
        }
        return (int) $pending['id'];
    }

    private function redirectToIntended(): Response
    {
        $intended = $this->app->session->get('intended');
        $this->app->session->forget('intended');
        $target = is_string($intended) && $intended !== '' ? url($intended) : admin_url('');
        return Response::redirect($target);
    }

    /**
     * Canonical form used for BOTH the throttle key and the credential
     * lookup — pulled out to a pure, public static method (no request/DB
     * needed) so the normalization itself is unit-testable in isolation
     * from the rest of login(). Same rule Auth::validate() applies to
     * its own query (strtolower(trim())); kept here too so the throttle
     * and the lookup can never drift apart again (I-3).
     */
    public static function normalizeEmail(string $raw): string
    {
        return strtolower(trim($raw));
    }

    /**
     * jo***@ex***.com — enough for "which inbox should I check" without
     * printing the full address on a pre-auth screen.
     */
    private static function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false) {
            return $email;
        }
        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        $maskedLocal = mb_substr($local, 0, 2) . '***';
        $maskedDomain = mb_substr($domain, 0, 2) . '***';
        $tld = strrchr($domain, '.');
        return $maskedLocal . '@' . $maskedDomain . ($tld !== false ? $tld : '');
    }
}
