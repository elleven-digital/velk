<?php
/**
 * @var string $maskedEmail
 * @var string|null $error
 * @var int $resendWait
 * @var string|null $flash_success
 * @var string|null $flash_error
 */
?>
<div class="auth__panel">
    <div class="auth__heading">
        <h1 class="auth__title"><?= __('auth.two_factor_title') ?></h1>
        <p class="auth__subtitle"><?= __('auth.two_factor_subtitle', ['email' => $maskedEmail]) ?></p>
    </div>

    <?php if (!empty($flash_success)): ?>
        <div class="auth__error" style="background:var(--success-bg, #e7f6ec);color:var(--success, #1a7f37);border-color:var(--success, #1a7f37)"><?= e($flash_success) ?></div>
    <?php endif; ?>
    <?php if (!empty($flash_error)): ?>
        <div class="auth__error"><?= e($flash_error) ?></div>
    <?php endif; ?>
    <?php if ($error !== null): ?>
        <div class="auth__error"><?= e($error) ?></div>
    <?php endif; ?>

    <form class="auth__form" method="post" action="<?= e(admin_url('login/two-factor')) ?>">
        <?= csrf_field() ?>

        <div class="field">
            <label class="field__label" for="code"><?= __('auth.verification_code') ?></label>
            <div class="field__control">
                <input
                    class="input"
                    type="text"
                    name="code"
                    id="code"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    pattern="[0-9]{6}"
                    maxlength="6"
                    placeholder="<?= __('auth.code_placeholder') ?>"
                    style="letter-spacing:6px;text-align:center;font-size:18px;font-family:var(--font-mono, monospace)"
                    autofocus
                    required
                >
            </div>
            <p class="field__help"><?= __('auth.code_help') ?></p>
        </div>

        <button class="button auth__submit" type="submit"><?= __('auth.confirm') ?></button>
    </form>

    <form method="post" action="<?= e(admin_url('login/two-factor/resend')) ?>" style="margin-top:12px;text-align:center">
        <?= csrf_field() ?>
        <button
            class="button button--ghost"
            type="submit"
            <?= $resendWait > 0 ? 'disabled title="' . __('auth.resend_wait', ['seconds' => $resendWait]) . '"' : '' ?>
            style="width:100%"
        >
            <?= $resendWait > 0 ? __('auth.resend_code_wait', ['seconds' => $resendWait]) : __('auth.resend_code') ?>
        </button>
    </form>

    <p class="auth__footer">
        <a href="<?= e(admin_url('login')) ?>" style="color:inherit;text-decoration:underline;text-underline-offset:3px;"><?= __('auth.two_factor_back') ?></a>
    </p>
</div>
