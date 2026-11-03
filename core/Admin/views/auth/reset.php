<?php
/**
 * @var bool $invalid
 * @var string $token
 * @var \Velk\Models\User|null $user
 * @var string|null $error
 */
?>
<div class="auth__panel">
    <?php if ($invalid): ?>
        <div class="auth__heading">
            <h1 class="auth__title"><?= __('auth.reset_invalid_title') ?></h1>
            <p class="auth__subtitle"><?= __('auth.reset_invalid_subtitle') ?></p>
        </div>

        <a class="button auth__submit" href="<?= e(admin_url('forgot-password')) ?>">
            <?= __('auth.reset_request_new_link') ?>
        </a>

        <p class="auth__footer">
            <a href="<?= e(admin_url('login')) ?>" style="color:inherit;text-decoration:underline;text-underline-offset:3px;"><?= __('auth.back_to_login') ?></a>
        </p>
    <?php else: ?>
        <div class="auth__heading">
            <h1 class="auth__title"><?= __('auth.reset_title') ?></h1>
            <p class="auth__subtitle"><?= __('auth.reset_subtitle', ['email' => $user->email]) ?></p>
        </div>

        <?php if ($error !== null): ?>
            <div class="auth__error"><?= e($error) ?></div>
        <?php endif; ?>

        <form class="auth__form" method="post" action="<?= e(admin_url('reset-password/' . $token)) ?>">
            <?= csrf_field() ?>

            <div class="field">
                <label class="field__label" for="password"><?= __('auth.new_password') ?></label>
                <div class="field__control">
                    <input class="input" type="password" name="password" id="password" required autofocus minlength="8" autocomplete="new-password" placeholder="<?= __('auth.password_min_placeholder') ?>">
                </div>
            </div>

            <div class="field">
                <label class="field__label" for="password_confirm"><?= __('auth.confirm_password') ?></label>
                <div class="field__control">
                    <input class="input" type="password" name="password_confirm" id="password_confirm" required minlength="8" autocomplete="new-password" placeholder="<?= __('auth.repeat_password_placeholder') ?>">
                </div>
            </div>

            <button class="button auth__submit" type="submit"><?= __('auth.reset_submit') ?></button>
        </form>

        <p class="auth__footer">
            <a href="<?= e(admin_url('login')) ?>" style="color:inherit;text-decoration:underline;text-underline-offset:3px;"><?= __('auth.back_to_login') ?></a>
        </p>
    <?php endif; ?>
</div>
