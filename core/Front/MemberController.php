<?php

declare(strict_types=1);

namespace Velk\Front;

use Velk\App;
use Velk\ClientEvents;
use Velk\Params;
use Velk\FieldRenderer;
use Velk\Mailer;
use Velk\Models\Member;
use Velk\Models\MemberSubscription;
use Velk\Models\MemberPasswordReset;
use Velk\Request;
use Velk\Response;
use Velk\Services\EmailOtpService;
use Velk\Services\MemberLoginAttemptService;
use Velk\Services\RateLimiterService;
use Velk\Services\SubscriptionService;

/**
 * Public-facing member auth endpoints. The site theme implements its
 * own login/register/reset forms — these controllers receive the POST,
 * validate, mutate state, and redirect back with a flash status the
 * theme picks up via `member_status()` (defined in helpers.php).
 *
 * Why redirect-back-with-flash instead of returning HTML/JSON:
 *   - Themes vary wildly — let them render the form however they want.
 *     We don't ship any opinionated login page.
 *   - Flash status survives one redirect, exactly enough for "Senha
 *     incorreta. Tente novamente." next to the form.
 *   - For SPA-ish themes, the controllers also return JSON when the
 *     Accept header asks for it.
 *
 * All endpoints check CSRF. The theme must include `<?= csrf_field() ?>`
 * inside the form — same convention as the public form endpoint.
 */
final class MemberController
{
    private const STATUS_KEY = '_member_status';

    /**
     * Session key holding the half-authenticated member between the
     * password step and the emailed-code step. Session-only — the
     * subject of the challenge is never client-controllable.
     */
    private const PENDING_2FA_KEY = '_2fa_pending_member';

    public function login(Request $request): Response
    {
        $app = App::instance();
        if (!$app->config->isMembersEnabled()) {
            return Response::notFound();
        }

        $back = $this->backUrl($request);

        if (!$request->isPost()) {
            return Response::redirect($back);
        }

        if (!$this->verifyCsrf($request)) {
            return $this->respond($request, $back, 'error', 'session_expired');
        }

        $email = strtolower(trim((string) ($request->post['email'] ?? '')));
        $password = (string) ($request->post['password'] ?? '');
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

        if ($email === '' || $password === '') {
            return $this->respond($request, $back, 'error', 'credentials_missing');
        }

        $throttle = new MemberLoginAttemptService();
        $lock = $throttle->checkLock($email, $ip);
        if ($lock['locked']) {
            $minutes = (int) ceil($lock['retry_after_seconds'] / 60);
            return $this->respond($request, $back, 'error', 'rate_limited', ['minutes' => $minutes]);
        }

        // validate() checks the credential WITHOUT starting the session —
        // members with 2FA only get a session after the emailed code.
        $member = $app->memberAuth->validate($email, $password);
        $throttle->record($email, $ip, $member !== null);

        if ($member === null) {
            // Intentionally generic — don't reveal whether the email
            // exists or just the password is wrong.
            return $this->respond($request, $back, 'error', 'invalid_credentials');
        }

        if ($member->twoFactorEnabled) {
            $otp = new EmailOtpService();
            $code = $otp->issue('member', $member->id, $ip);

            if ($code === null) {
                return $this->respond($request, $back, 'error', 'otp_rate_limited');
            }

            try {
                $otp->sendCode($member->email, $member->name, $code);
            } catch (\Throwable $e) {
                // If the email never left, advancing to the code step is
                // a dead end — surface the failure on the login form.
                error_log('[Velk 2FA] send failed for member ' . $member->id . ': ' . $e->getMessage());
                return $this->respond($request, $back, 'error', 'otp_send_failed');
            }

            $app->session->put(self::PENDING_2FA_KEY, [
                'id'      => $member->id,
                'expires' => time() + EmailOtpService::TTL_MINUTES * 60,
            ]);

            // Distinct status so the theme knows to swap the login form
            // for the code form (posting to the `two_factor` route).
            return $this->respond($request, $back, 'two_factor', 'otp_sent');
        }

        // Clear any stale half-login from a previous 2FA attempt.
        $app->session->forget(self::PENDING_2FA_KEY);
        $app->memberAuth->loginAs($member);

        $intended = $app->session->get('_member_intended');
        if (is_string($intended) && $intended !== '') {
            $app->session->forget('_member_intended');
            return Response::redirect($intended);
        }

        return $this->respond($request, $back, 'success', 'signed_in');
    }

    /**
     * Second step of member 2FA: receives the emailed code, completes
     * the login. The theme posts `code` (+ CSRF) to the `two_factor`
     * route while `member_status()` is reporting status 'two_factor'.
     */
    public function twoFactor(Request $request): Response
    {
        $app = App::instance();
        if (!$app->config->isMembersEnabled()) {
            return Response::notFound();
        }

        $back = $this->backUrl($request);

        if (!$request->isPost()) {
            return Response::redirect($back);
        }

        if (!$this->verifyCsrf($request)) {
            return $this->respond($request, $back, 'error', 'session_expired');
        }

        $member = $this->pendingMember();
        if ($member === null) {
            return $this->respond($request, $back, 'error', 'otp_session_expired');
        }

        $code = trim((string) ($request->post['code'] ?? ''));
        $otp = new EmailOtpService();

        if ($code === '' || !$otp->verify('member', $member->id, $code)) {
            // Status stays 'two_factor' so the theme keeps showing the
            // code form instead of bouncing back to email+password.
            return $this->respond($request, $back, 'two_factor', 'otp_invalid');
        }

        $app->session->forget(self::PENDING_2FA_KEY);
        $app->memberAuth->loginAs($member);

        $intended = $app->session->get('_member_intended');
        if (is_string($intended) && $intended !== '') {
            $app->session->forget('_member_intended');
            return Response::redirect($intended);
        }

        return $this->respond($request, $back, 'success', 'signed_in');
    }

    /** POST-only resend for the member code — cooldown + hourly cap. */
    public function twoFactorResend(Request $request): Response
    {
        $app = App::instance();
        if (!$app->config->isMembersEnabled()) {
            return Response::notFound();
        }

        $back = $this->backUrl($request);

        if (!$request->isPost()) {
            return Response::redirect($back);
        }

        if (!$this->verifyCsrf($request)) {
            return $this->respond($request, $back, 'error', 'session_expired');
        }

        $member = $this->pendingMember();
        if ($member === null) {
            return $this->respond($request, $back, 'error', 'otp_session_expired');
        }

        $otp = new EmailOtpService();

        if ($otp->secondsUntilResend('member', $member->id) > 0) {
            return $this->respond($request, $back, 'two_factor', 'otp_resend_wait');
        }

        $code = $otp->issue('member', $member->id, (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
        if ($code === null) {
            return $this->respond($request, $back, 'two_factor', 'otp_rate_limited');
        }

        try {
            $otp->sendCode($member->email, $member->name, $code);
        } catch (\Throwable $e) {
            error_log('[Velk 2FA] resend failed for member ' . $member->id . ': ' . $e->getMessage());
            return $this->respond($request, $back, 'two_factor', 'otp_send_failed');
        }

        // New code → extend the challenge window to match its TTL.
        $app->session->put(self::PENDING_2FA_KEY, [
            'id'      => $member->id,
            'expires' => time() + EmailOtpService::TTL_MINUTES * 60,
        ]);

        return $this->respond($request, $back, 'two_factor', 'otp_resent');
    }

    public function logout(Request $request): Response
    {
        $app = App::instance();
        if (!$app->config->isMembersEnabled()) {
            return Response::notFound();
        }

        $back = $this->backUrl($request);

        if (!$request->isPost() || !$this->verifyCsrf($request)) {
            return Response::redirect($back);
        }

        $app->memberAuth->logout();
        return $this->respond($request, $back, 'success', 'signed_out');
    }

    public function register(Request $request): Response
    {
        $app = App::instance();
        if (!$app->config->isMembersEnabled()) {
            return Response::notFound();
        }
        // Registration must be explicitly enabled — otherwise only the
        // admin can create members (via /admin/members/new).
        if (!$app->config->isMemberPublicRegistration()) {
            return Response::notFound();
        }

        $back = $this->backUrl($request);

        if (!$request->isPost()) {
            return Response::redirect($back);
        }

        if (!$this->verifyCsrf($request)) {
            return $this->respond($request, $back, 'error', 'session_expired');
        }

        // Honeypot — same hidden `_hp` field + convention as
        // PublicFormController::submit() (form_honeypot() in
        // core/helpers.php). Bots that fill in every field trip it;
        // faking a normal success response (no account created) avoids
        // tipping the bot off that it was caught. No-op today if the
        // theme's registration form doesn't render the field yet — this
        // just makes the check ready the moment it does (M-5).
        if (!empty($request->post['_hp'])) {
            return $this->respond($request, $back, 'success', 'registered');
        }

        $email = strtolower(trim((string) ($request->post['email'] ?? '')));
        $password = (string) ($request->post['password'] ?? '');
        $name = trim((string) ($request->post['name'] ?? '')) ?: null;
        $type = (string) ($request->post['type'] ?? '');
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

        // IP throttle guards against mass account creation (M-5).
        // Checked before any validation so even malformed submissions
        // count against the cap the same as legit ones — same
        // check-before-record ordering as the login/reset throttles so
        // hammering while locked can't keep pushing the window forward.
        $throttle = new RateLimiterService('member_register_ip', 15, 5);
        if ($throttle->checkLock($ip)['locked']) {
            return $this->respond($request, $back, 'error', 'rate_limited');
        }
        $throttle->record($ip);

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->respond($request, $back, 'error', 'invalid_email');
        }
        if (strlen($password) < 8) {
            return $this->respond($request, $back, 'error', 'password_too_short');
        }

        // If the site declares types, the registration form must pick a
        // valid one. If the site has no types, the field is ignored —
        // members all share a single (NULL) type.
        $typesAvailable = $app->config->memberTypes();
        if (!empty($typesAvailable)) {
            if ($type === '' || !isset($typesAvailable[$type])) {
                return $this->respond($request, $back, 'error', 'invalid_member_type');
            }
        } else {
            $type = '';
        }
        $finalType = $type === '' ? null : $type;

        if (Member::findByEmail($email) !== null) {
            return $this->respond($request, $back, 'error', 'email_taken');
        }

        // Custom-field values come in under `fields[name]`. We collect
        // against the effective schema for the chosen type so values
        // for fields not declared in site.json are silently dropped.
        $fieldDefs = $app->config->memberFieldsFor($finalType);
        $fieldValues = FieldRenderer::collect($fieldDefs, $request->post['fields'] ?? []);

        try {
            $member = Member::create($email, $password, $name, $finalType, $fieldValues);
        } catch (\Throwable $e) {
            // Unique-key collision on email is the typical case but we
            // already checked above — anything reaching here is unusual.
            return $this->respond($request, $back, 'error', 'registration_failed');
        }

        $app->memberAuth->loginAs($member);

        // Conversion event. Built HERE, right after Member::create(), for the
        // same structural reason as the form path: the honeypot branch above
        // returns long before this line, so a caught bot can never produce a
        // conversion. That's the shape of the code, not a check to remember.
        //
        // The member's email is native and always present, so the panel
        // offers a plain toggle rather than a field mapping — but it stays
        // OFF by default. What changes on upgrade must never be who receives
        // the users' data.
        $userData = [];
        if (option('tracking.signup_send_email')) {
            $userData = ClientEvents::buildUserData(['email' => 'email'], ['email' => $member->email]);
        }

        $snippet = (string) (option('tracking.signup_snippet') ?? '');

        return $this->respond($request, $back, 'success', 'registered', [], [
            'name' => 'velk_signup_success',
            'data' => [
                'member_id' => $member->id,
                'type'      => $finalType,
                // (object) porque é mapa por contrato — sem o cast um vazio
                // sairia `[]` e o tipo mudaria entre um cadastro com campanha e
                // um direto. Ver PublicFormController.
                'params'    => (object) Params::fromSession($app->session),
                'user_data' => (object) $userData,
            ],
            'snippet' => $snippet !== '' ? $snippet : null,
        ]);
    }

    /**
     * Step 1 of password reset: receive an email, send a token if a
     * member with that email exists. The response is identical whether
     * or not the email is registered — that's a deliberate choice to
     * avoid email-enumeration via the reset form.
     */
    public function passwordResetRequest(Request $request): Response
    {
        $app = App::instance();
        if (!$app->config->isMembersEnabled()) {
            return Response::notFound();
        }

        $back = $this->backUrl($request);

        if (!$request->isPost()) {
            return Response::redirect($back);
        }

        if (!$this->verifyCsrf($request)) {
            return $this->respond($request, $back, 'error', 'session_expired');
        }

        $email = strtolower(trim((string) ($request->post['email'] ?? '')));
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $genericMessage = 'password_reset_sent';

        // Rate-limited BEFORE any lookup/send, and checked+recorded
        // regardless of whether $email is well-formed or belongs to a
        // real account — every branch below returns the exact same
        // $genericMessage, so throttling uniformly can't itself become
        // a new enumeration signal (I13). IP is the primary key (one
        // attacker IP bombing one victim inbox); email is a secondary,
        // tighter cap so spreading requests across many IPs against the
        // same target still gets caught. No hit is recorded when
        // already locked — same check-before-record ordering as
        // AuthController::login().
        $ipLimiter = new RateLimiterService('pwreset_member_ip', 15, 5);
        $emailLimiter = new RateLimiterService('pwreset_member_email', 15, 3);
        $throttled = $ipLimiter->checkLock($ip)['locked']
            || ($email !== '' && $emailLimiter->checkLock($email)['locked']);

        if ($throttled) {
            return $this->respond($request, $back, 'success', $genericMessage);
        }
        $ipLimiter->record($ip);
        if ($email !== '') {
            $emailLimiter->record($email);
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Even on bad input we return the generic success message —
            // a malformed email is still "not found" from the caller's
            // perspective and we don't want to leak any signal.
            return $this->respond($request, $back, 'success', $genericMessage);
        }

        $member = Member::findByEmail($email);
        if ($member !== null) {
            $token = MemberPasswordReset::issueFor(
                $member,
                (string) ($_SERVER['REMOTE_ADDR'] ?? null),
                (string) ($_SERVER['HTTP_USER_AGENT'] ?? null)
            );

            $resetPath = $app->config->memberRoutes()['password_new'] ?? '/member/password-new';
            $resetUrl = absolute_path_url($resetPath) . '?token=' . urlencode($token);
            $siteName = (string) ($app->config->site('site.name') ?? 'Site');

            try {
                $this->sendResetEmail($member, $resetUrl, $siteName);
            } catch (\Throwable $e) {
                // Swallow on purpose: the response below is identical
                // whether the email exists or not (anti-enumeration),
                // so a delivery failure can't change the reply either.
                error_log('[Velk] member reset email failed: ' . $e->getMessage());
            }
        }

        return $this->respond($request, $back, 'success', $genericMessage);
    }

    /**
     * Step 2: receive token + new password, complete the reset.
     * Token comes via POST (the theme's form has a hidden `token` field
     * sourced from the email-link's `?token=` query param).
     */
    public function passwordResetComplete(Request $request): Response
    {
        $app = App::instance();
        if (!$app->config->isMembersEnabled()) {
            return Response::notFound();
        }

        $back = $this->backUrl($request);

        if (!$request->isPost()) {
            return Response::redirect($back);
        }

        if (!$this->verifyCsrf($request)) {
            return $this->respond($request, $back, 'error', 'session_expired');
        }

        $token = (string) ($request->post['token'] ?? '');
        $password = (string) ($request->post['password'] ?? '');

        if (strlen($password) < 8) {
            return $this->respond($request, $back, 'error', 'password_too_short');
        }

        $reset = MemberPasswordReset::findValidByToken($token);
        if ($reset === null) {
            return $this->respond($request, $back, 'error', 'reset_link_invalid');
        }

        $member = $reset->member();
        if ($member === null) {
            $reset->consume();
            return $this->respond($request, $back, 'error', 'account_not_found');
        }

        $member->setPassword($password);
        $reset->consume();
        // Best-effort GC — keeps the table from accumulating expired rows
        // without needing a cron just for this.
        MemberPasswordReset::purge();

        // Log them in automatically — same UX as admin reset.
        $app->memberAuth->loginAs($member);

        return $this->respond($request, $back, 'success', 'password_updated');
    }

    /**
     * Inicia o checkout do Stripe para um plano/intervalo. Exige login +
     * CSRF. Valida o plano contra os ativos antes de qualquer chamada ao
     * Stripe. Em sucesso, redireciona (302) para a página hospedada.
     */
    public function subscribe(Request $request): Response
    {
        $app = App::instance();
        if (!$app->config->isSubscriptionsEnabled()) {
            return Response::notFound();
        }

        $back = $this->backUrl($request);

        if (!$request->isPost()) {
            return Response::redirect($back);
        }
        if (!$this->verifyCsrf($request)) {
            return $this->respond($request, $back, 'error', 'session_expired');
        }

        $member = $app->memberAuth->member();
        if ($member === null) {
            $app->session->put('_member_intended', $_SERVER['REQUEST_URI'] ?? '/');
            return Response::redirect($app->basePath . ($app->config->memberRoutes()['login'] ?? '/member/login'));
        }

        $planKey = (string) ($request->post['plan'] ?? '');
        $interval = (string) ($request->post['interval'] ?? 'month');

        $service = SubscriptionService::make();
        $plan = $service->findPlan($planKey, true);
        if ($plan === null || !isset($plan['prices'][$interval])) {
            return $this->respond($request, $back, 'error', 'plan_unavailable');
        }

        // Cardinalidade (site.json → members.multiple_plans): uma assinatura
        // por membro por padrão; com a flag, várias mas uma por plano. O
        // conjunto testado é "as ativas + a pedida". Sem esta guarda, um
        // segundo envio do formulário — clique duplo, aba antiga, F5 —
        // criava uma SEGUNDA subscription no Stripe e o membro era cobrado
        // duas vezes. Trocar de plano é pelo portal.
        $activeKeys = array_map(
            fn(MemberSubscription $s) => $s->planKey,
            MemberSubscription::activeListForMember($member->id)
        );
        if (MemberSubscription::violatesCardinality([...$activeKeys, $planKey], $app->config->allowsMultiplePlans())) {
            return $this->respond($request, $back, 'info', 'already_subscribed');
        }

        $routes = $app->config->memberRoutes();
        $success = absolute_path_url($routes['subscription_success'] ?? '/member/subscription/success');
        $cancel = absolute_path_url($routes['subscription_cancel'] ?? '/member/subscription/cancel');

        try {
            $url = $service->createCheckoutSession($member, $planKey, $interval, $success, $cancel);
        } catch (\Throwable $e) {
            error_log('[Velk] checkout failed for member ' . $member->id . ': ' . $e->getMessage());
            return $this->respond($request, $back, 'error', 'checkout_failed');
        }

        return Response::redirect($url);
    }

    /** Abre o Customer Portal do Stripe (cancelar/trocar cartão/upgrade). */
    public function subscriptionPortal(Request $request): Response
    {
        $app = App::instance();
        if (!$app->config->isSubscriptionsEnabled()) {
            return Response::notFound();
        }

        $back = $this->backUrl($request);

        if (!$request->isPost()) {
            return Response::redirect($back);
        }
        if (!$this->verifyCsrf($request)) {
            return $this->respond($request, $back, 'error', 'session_expired');
        }

        $member = $app->memberAuth->member();
        if ($member === null || $member->stripeCustomerId === null || $member->stripeCustomerId === '') {
            return $this->respond($request, $back, 'error', 'no_subscription');
        }

        try {
            // O Stripe exige return_url ABSOLUTA. $back pode ser o fallback
            // relativo do backUrl() (sem Referer — Referrer-Policy: no-referrer
            // no site basta), e aí o portal falhava com "Invalid URL".
            $url = SubscriptionService::make()->createPortalSession($member, absolute_path_url($back));
        } catch (\Throwable $e) {
            error_log('[Velk] portal failed for member ' . $member->id . ': ' . $e->getMessage());
            return $this->respond($request, $back, 'error', 'portal_failed');
        }

        return Response::redirect($url);
    }

    /**
     * Retorno pós-pagamento. Cosmético: quem grava o estado é o webhook.
     * Mostra um flash de "confirmando" e volta pro site (o tema lê via
     * member_status()).
     */
    public function subscriptionSuccess(Request $request): Response
    {
        $app = App::instance();
        if (!$app->config->isSubscriptionsEnabled()) {
            return Response::notFound();
        }
        $app->session->put(self::STATUS_KEY, [
            'status' => 'success',
            'code' => 'subscription_paid',
        ]);
        $base = $app->basePath === '' ? '/' : $app->basePath . '/';
        return Response::redirect($base);
    }

    /** Retorno de checkout cancelado pelo usuário. */
    public function subscriptionCancel(Request $request): Response
    {
        $app = App::instance();
        if (!$app->config->isSubscriptionsEnabled()) {
            return Response::notFound();
        }
        $app->session->put(self::STATUS_KEY, [
            'status' => 'info',
            'code' => 'subscription_cancelled',
        ]);
        $base = $app->basePath === '' ? '/' : $app->basePath . '/';
        return Response::redirect($base);
    }

    // ─── Internals ────────────────────────────────────────────────

    private function verifyCsrf(Request $request): bool
    {
        $token = (string) ($request->post['_csrf'] ?? '');
        return App::instance()->session->verifyCsrf($token);
    }

    /**
     * The member with a valid (non-expired) pending 2FA challenge in
     * this session, or null. Re-fetches from the DB so a member deleted
     * mid-challenge can't complete the login.
     */
    private function pendingMember(): ?Member
    {
        $session = App::instance()->session;
        $pending = $session->get(self::PENDING_2FA_KEY);
        if (!is_array($pending) || !isset($pending['id'], $pending['expires'])) {
            return null;
        }
        if ((int) $pending['expires'] < time()) {
            $session->forget(self::PENDING_2FA_KEY);
            return null;
        }
        return Member::find((int) $pending['id']);
    }

    private function backUrl(Request $request): string
    {
        // Só volta para o Referer se ele for deste host — ver
        // Request::refererSameHost() para o porquê. Lê do Request, não de
        // $_SERVER direto: é o que deixa isto testável sem tocar o global.
        $same = $request->refererSameHost();
        if ($same !== null) {
            return $same;
        }
        $base = App::instance()->basePath;
        return $base === '' ? '/' : $base . '/';
    }

    /**
     * Single place to decide between JSON and redirect-with-flash, based
     * on the Accept header. SPAs send `Accept: application/json` and get
     * a structured payload; classic forms get redirect + flash.
     *
     * `$event` carries a conversion event when this response marks one (only
     * a completed registration today). It has exactly ONE destination:
     * inside the JSON body for an AJAX caller, or on the queue for the next
     * render otherwise. Doing both would fire it twice; queueing only would
     * fire it late or never, since a theme registering over AJAX never
     * re-renders the page that drains the queue.
     */
    private function respond(
        Request $request,
        string $back,
        string $status,
        string $code,
        array $params = [],
        ?array $event = null
    ): Response {
        $payload = ['status' => $status, 'code' => $code];
        // `params` carrega o que só o engine sabe e a frase precisa — os
        // minutos que faltam para poder tentar de novo, por exemplo. Sem
        // isso o tema diria "tente mais tarde" e nunca "tente em 3 minutos".
        if ($params !== []) {
            $payload['params'] = $params;
        }

        if ($request->wantsJson()) {
            // `_event=queue`: o tema vai navegar depois de enviar, então o
            // evento vai para a FILA e a próxima página dispara pelo
            // tracking_head(). Mesma decisão do PublicFormController, e pelo
            // mesmo motivo — consertar só um lado criaria dois
            // comportamentos para o mesmo problema.
            if ($event !== null && ClientEvents::requestedQueue($request)) {
                ClientEvents::queue(
                    App::instance()->session,
                    (string) ($event['name'] ?? ''),
                    (array) ($event['data'] ?? []),
                    $event['snippet'] ?? null
                );
            } elseif ($event !== null) {
                $payload['event'] = $event;
            }
            // 'two_factor' is a step, not a failure — only true errors
            // get 422 so SPA themes can branch on the HTTP status.
            return Response::json($payload, $status === 'error' ? 422 : 200);
        }

        $session = App::instance()->session;
        if ($event !== null) {
            ClientEvents::queue(
                $session,
                (string) ($event['name'] ?? ''),
                (array) ($event['data'] ?? []),
                $event['snippet'] ?? null
            );
        }

        $session->put(self::STATUS_KEY, $payload);
        return Response::redirect($back);
    }

    /**
     * Renders and sends in the admin-configured default locale. Members
     * have no locale field of their own — this follows the same
     * convention as the member 2FA code email (EmailOtpService::sendCode):
     * member call sites omit a target locale and fall back to
     * `admin.locale` (member-side i18n is a separate, out-of-scope
     * concern). Reuses the admin panel's `email.reset.*` catalog keys and
     * `emails/reset-password` view — the copy is generic enough that no
     * member-specific wording is needed. The active admin locale is
     * saved/restored around the swap so sending an email never leaks a
     * locale change into the rest of the request.
     */
    private function sendResetEmail(Member $member, string $resetUrl, string $siteName): void
    {
        $app = App::instance();
        $i18n = $app->adminI18n;
        $prev = $i18n->current();
        $i18n->setCurrent((string) $app->config->get('admin.locale', 'en'));

        try {
            $emailView = new \Velk\View($app->config->path('core') . '/Admin/views');
            $html = $emailView->render('emails/reset-password', [
                // The shared view only reads $user->name; a Member has no
                // other overlap with the admin User model it was written
                // for. Fall back to email so a nameless member (name is
                // optional at registration) still gets a proper greeting
                // instead of "Hello ,".
                'user'       => (object) ['name' => $member->name ?? $member->email],
                'resetUrl'   => $resetUrl,
                'siteName'   => $siteName,
                'ttlMinutes' => (int) (MemberPasswordReset::TTL_SECONDS / 60),
            ]);

            // Mailer::fromEnv() (NOT `new Mailer()`) so SMTP_* env vars
            // are honored, and send() takes positional args — the old
            // array call here was a TypeError waiting for the first
            // member to ask for a reset.
            Mailer::fromEnv()->send(
                $member->email,
                // I10: Subject header is plain text, not HTML — __() would
                // HTML-escape the site name (e.g. "Joe&#039;s &amp; Co").
                __raw('email.reset.subject', ['site' => $siteName]),
                $html
            );
        } finally {
            $i18n->setCurrent($prev);
        }
    }
}
