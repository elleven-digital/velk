<?php

declare(strict_types=1);

namespace Velk\Admin\Controllers;

use Velk\Mailer;
use Velk\Models\PasswordReset;
use Velk\Models\User;
use Velk\Request;
use Velk\Response;
use Velk\Services\RateLimiterService;

final class PasswordResetController extends Controller
{
    private const MIN_PASSWORD = 8;

    public function forgot(Request $request): Response
    {
        if ($this->app->auth->check()) {
            return Response::redirect(admin_url(''));
        }

        $email = '';
        $error = null;

        if ($request->isPost()) {
            $csrf = $this->verifyCsrfOrFail($request);
            if ($csrf !== null) return $csrf;

            $email = trim((string) ($request->post['email'] ?? ''));
            $ip = $request->ip();

            // Rate-limited BEFORE any lookup/send, and checked+recorded
            // regardless of whether $email is well-formed or belongs to
            // a real account — the response below is byte-identical in
            // every one of those cases, so throttling uniformly can't
            // itself become a new enumeration signal (I13). IP is the
            // primary key (one attacker IP bombing one victim inbox);
            // email is a secondary, tighter cap so spreading requests
            // across many IPs against the same target still gets caught.
            // No hit is recorded when already locked — same
            // check-before-record ordering as AuthController::login(),
            // so continued hammering during lockout can't keep pushing
            // the window forward.
            $ipLimiter = new RateLimiterService('pwreset_admin_ip', 15, 5);
            $emailLimiter = new RateLimiterService('pwreset_admin_email', 15, 3);
            $emailKey = strtolower($email);
            $throttled = $ipLimiter->checkLock($ip)['locked']
                || ($emailKey !== '' && $emailLimiter->checkLock($emailKey)['locked']);

            if ($throttled) {
                return $this->render('auth/forgot-sent', [
                    'email' => $email,
                ], 'layout/auth');
            }
            $ipLimiter->record($ip);
            if ($emailKey !== '') {
                $emailLimiter->record($emailKey);
            }

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = __('auth.invalid_email');
            } else {
                // Always show the same success message, regardless of whether
                // the email exists — prevents user enumeration.
                PasswordReset::purge();
                $user = User::findByEmail($email);
                if ($user !== null) {
                    try {
                        $token = PasswordReset::issueFor(
                            $user,
                            $ip,
                            $request->userAgent()
                        );
                        $this->sendResetEmail($user, $token);
                    } catch (\Throwable $e) {
                        // Log internally; never expose to the user.
                        error_log('[Velk] Password reset email failed: ' . $e->getMessage());
                    }
                }

                return $this->render('auth/forgot-sent', [
                    'email' => $email,
                ], 'layout/auth');
            }
        }

        return $this->render('auth/forgot', [
            'email' => $email,
            'error' => $error,
        ], 'layout/auth');
    }

    public function reset(Request $request, array $params): Response
    {
        if ($this->app->auth->check()) {
            return Response::redirect(admin_url(''));
        }

        $token = (string) ($params['token'] ?? '');
        $reset = PasswordReset::findValidByToken($token);

        if ($reset === null) {
            return $this->render('auth/reset', [
                'invalid' => true,
                'token' => $token,
                'error' => null,
            ], 'layout/auth');
        }

        $user = $reset->user();
        if ($user === null) {
            return $this->render('auth/reset', [
                'invalid' => true,
                'token' => $token,
                'error' => null,
            ], 'layout/auth');
        }

        $error = null;

        if ($request->isPost()) {
            $csrf = $this->verifyCsrfOrFail($request);
            if ($csrf !== null) return $csrf;

            $password = (string) ($request->post['password'] ?? '');
            $confirm = (string) ($request->post['password_confirm'] ?? '');

            if (strlen($password) < self::MIN_PASSWORD) {
                $error = __('auth.password_too_short', ['min' => self::MIN_PASSWORD]);
            } elseif ($password !== $confirm) {
                $error = __('auth.password_mismatch');
            } else {
                $user->setPassword($password);
                $reset->consume();
                $this->app->session->flash('success', __('flash.password_reset'));
                return Response::redirect(admin_url('login'));
            }
        }

        return $this->render('auth/reset', [
            'invalid' => false,
            'token' => $token,
            'user' => $user,
            'error' => $error,
        ], 'layout/auth');
    }

    /**
     * Renders and sends in the TARGET user's locale — not the current
     * session's (an EN-locale admin can trigger this for a PT-locale
     * colleague; pre-login there is no session user at all). The active
     * admin locale is saved/restored around the swap so sending an email
     * never leaks a locale change into the rest of the request.
     */
    private function sendResetEmail(User $user, string $token): void
    {
        $i18n = $this->app->adminI18n;
        $prev = $i18n->current();
        $i18n->setCurrent($user->locale ?: (string) $this->app->config->get('admin.locale', 'en'));

        try {
            $resetUrl = absolute_admin_url('reset-password/' . $token);
            $siteName = (string) ($this->app->config->site('site.name') ?? 'Velk');

            $emailView = new \Velk\View($this->app->config->path('core') . '/Admin/views');
            $html = $emailView->render('emails/reset-password', [
                'user'     => $user,
                'resetUrl' => $resetUrl,
                'siteName' => $siteName,
                'ttlMinutes' => (int) (PasswordReset::TTL_SECONDS / 60),
            ]);

            $mailer = Mailer::fromEnv();
            $mailer->send(
                to:        $user->email,
                // I10: Subject header is plain text, not HTML — __() would
                // HTML-escape the site name (e.g. "Joe&#039;s &amp; Co").
                subject:   __raw('email.reset.subject', ['site' => $siteName]),
                htmlBody:  $html
            );
        } finally {
            $i18n->setCurrent($prev);
        }
    }
}
