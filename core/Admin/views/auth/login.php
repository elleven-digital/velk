<?php /** @var string $email; @var string|null $error; @var string|null $flash_error */ ?>
<?php
// O erro desta tentativa de login, ou o flash da anterior — a falha de CSRF
// de um formulário aberto numa sessão que morreu redireciona para cá.
$error = $error ?? ($flash_error ?? null);
?>
<div class="auth__panel">
    <div class="auth__heading">
        <h1 class="auth__title"><?= __('auth.login_title') ?></h1>
        <p class="auth__subtitle"><?= __('auth.login_subtitle') ?></p>
    </div>

    <?php if ($error !== null): ?>
        <div class="auth__error"><?= e($error) ?></div>
    <?php endif; ?>

    <form class="auth__form" method="post" action="<?= e(admin_url('login')) ?>">
        <?= csrf_field() ?>

        <div class="field">
            <label class="field__label" for="email"><?= __('auth.email') ?></label>
            <div class="field__control">
                <input class="input" type="email" name="email" id="email" value="<?= e($email) ?>" autofocus required autocomplete="email" placeholder="<?= __('auth.email_placeholder') ?>">
            </div>
        </div>

        <div class="field">
            <label class="field__label" for="password"><?= __('auth.password') ?></label>
            <div class="field__control">
                <input class="input" type="password" name="password" id="password" required autocomplete="current-password" placeholder="<?= __('auth.password_placeholder') ?>">
            </div>
        </div>

        <button class="button auth__submit" type="submit"><?= __('auth.sign_in') ?></button>
    </form>

    <p class="auth__footer">
        <a href="<?= e(admin_url('forgot-password')) ?>" style="color:inherit;text-decoration:underline;text-underline-offset:3px;"><?= __('auth.forgot_password') ?></a>
    </p>
</div>
