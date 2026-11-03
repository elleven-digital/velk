<?php /** @var string $email */ ?>
<div class="auth__panel">
    <div class="auth__heading">
        <h1 class="auth__title"><?= __('auth.forgot_sent_title') ?></h1>
        <p class="auth__subtitle">
            <?= __('auth.forgot_sent_subtitle', ['email' => $email]) ?>
        </p>
    </div>

    <div style="font-size:12px;color:var(--fg-subtle);background:var(--bg-alt);border:1px solid var(--line);padding:12px 14px;border-radius:6px;line-height:1.55">
        <?= __('auth.forgot_sent_help_prefix') ?>
        <a href="<?= e(admin_url('forgot-password')) ?>" style="color:var(--fg);text-decoration:underline"><?= __('auth.forgot_sent_help_link') ?></a>.
    </div>

    <p class="auth__footer">
        <a href="<?= e(admin_url('login')) ?>" style="color:inherit;text-decoration:underline;text-underline-offset:3px;"><?= __('auth.back_to_login') ?></a>
    </p>
</div>
