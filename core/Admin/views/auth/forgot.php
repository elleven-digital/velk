<?php /** @var string $email; @var string|null $error */ ?>
<div class="auth__panel">
    <div class="auth__heading">
        <h1 class="auth__title"><?= __('auth.forgot_title') ?></h1>
        <p class="auth__subtitle"><?= __('auth.forgot_subtitle') ?></p>
    </div>

    <?php if ($error !== null): ?>
        <div class="auth__error"><?= e($error) ?></div>
    <?php endif; ?>

    <form class="auth__form" method="post" action="<?= e(admin_url('forgot-password')) ?>">
        <?= csrf_field() ?>

        <div class="field">
            <label class="field__label" for="email"><?= __('auth.email') ?></label>
            <div class="field__control">
                <input class="input" type="email" name="email" id="email" value="<?= e($email) ?>" autofocus required autocomplete="email" placeholder="<?= __('auth.email_placeholder') ?>">
            </div>
        </div>

        <button class="button auth__submit" type="submit"><?= __('auth.send_link') ?></button>
    </form>

    <p class="auth__footer">
        <a href="<?= e(admin_url('login')) ?>" style="color:inherit;text-decoration:underline;text-underline-offset:3px;"><?= __('auth.back_to_login') ?></a>
    </p>
</div>
