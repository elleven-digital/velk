<?php
/**
 * @var \Velk\Models\User|null $user
 * @var array{name:string,email:string,role:string,locale:?string} $values
 * @var array<string,string> $errors
 * @var \Velk\Models\User|null $currentUser
 */
$isNew = $user === null;
$isSelf = $user !== null && $currentUser !== null && $user->id === $currentUser->id;
$currentLocale = $values['locale'] ?? '';
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= $isNew ? __('users.new') : e($user->name) ?></h1>
        <p class="page-header__subtitle">
            <?php if ($isNew): ?>
                <?= __('users.new_subtitle') ?>
            <?php else: ?>
                <?= $isSelf ? __('users.self_label') : __('users.edit_label') ?><?= __('users.added_on_suffix', ['date' => date('d/m/Y', strtotime($user->createdAt))]) ?>
            <?php endif; ?>
        </p>
    </div>
</div>

<form method="post" class="form" id="user-form">
    <?= csrf_field() ?>

    <div class="form-row">
        <div class="field">
            <label class="field__label" for="name"><?= __('forms.col_name') ?> <span class="field__required">*</span></label>
            <div class="field__control">
                <input class="input" type="text" name="name" id="name" value="<?= e($values['name']) ?>" required autofocus>
            </div>
            <?php if (!empty($errors['name'])): ?>
                <p class="field__help" style="color:var(--danger)"><?= e($errors['name']) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label class="field__label" for="email"><?= __('forms.field_email') ?> <span class="field__required">*</span></label>
            <div class="field__control">
                <input class="input" type="email" name="email" id="email" value="<?= e($values['email']) ?>" required autocomplete="email">
            </div>
            <?php if (!empty($errors['email'])): ?>
                <p class="field__help" style="color:var(--danger)"><?= e($errors['email']) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="form-row">
        <div class="field">
            <label class="field__label" for="password">
                <?= __('auth.password') ?>
                <?php if ($isNew): ?>
                    <span class="field__required">*</span>
                <?php else: ?>
                    <span class="muted" style="font-weight:400;font-size:11px"><?= __('members.password_optional_hint') ?></span>
                <?php endif; ?>
            </label>
            <div class="field__control">
                <input class="input" type="password" name="password" id="password" <?= $isNew ? 'required' : '' ?> minlength="8" autocomplete="new-password" placeholder="<?= $isNew ? __('auth.password_min_placeholder') : __('auth.password_placeholder') ?>">
            </div>
            <?php if (!empty($errors['password'])): ?>
                <p class="field__help" style="color:var(--danger)"><?= e($errors['password']) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label class="field__label" for="role"><?= __('users.col_role') ?></label>
            <?php if ($isSelf): ?>
                <?php
                $roleLabel = $values['role'] === 'admin' ? __('users.role_admin') : __('users.role_editor');
                ?>
                <div class="field__control">
                    <input class="input" type="text" value="<?= e($roleLabel) ?>" disabled>
                </div>
                <p class="field__help"><?= __('users.role_self_help') ?></p>
            <?php else: ?>
                <div class="field__control">
                    <select class="input input--select" name="role" id="role">
                        <option value="admin" <?= $values['role'] === 'admin' ? 'selected' : '' ?>><?= __('users.role_admin') ?></option>
                        <option value="editor" <?= $values['role'] === 'editor' ? 'selected' : '' ?>><?= __('users.role_editor') ?></option>
                    </select>
                </div>
                <p class="field__help"><?= __('users.role_help', ['admin' => __('users.role_admin'), 'editor' => __('users.role_editor')]) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="form-row">
        <div class="field">
            <label class="field__label"><?= __('members.security_label') ?></label>
            <div class="field__control">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
                    <input type="checkbox" name="two_factor" value="1" <?= !empty($values['two_factor']) ? 'checked' : '' ?>>
                    <?= __('members.two_factor_label') ?>
                </label>
            </div>
            <p class="field__help"><?= __('users.two_factor_help') ?></p>
        </div>
    </div>

    <div class="form-row">
        <div class="field">
            <label class="field__label" for="locale"><?= __('users.language') ?></label>
            <div class="field__control">
                <select class="input input--select" name="locale" id="locale">
                    <option value="" <?= $currentLocale === '' ? 'selected' : '' ?>><?= __('users.language_default', ['locale' => \Velk\App::instance()->adminI18n->labelFor((string) \Velk\App::instance()->config->get('admin.locale', 'en'))]) ?></option>
                    <?php foreach (\Velk\App::instance()->adminI18n->available() as $code): ?>
                        <option value="<?= e($code) ?>" <?= $currentLocale === $code ? 'selected' : '' ?>><?= e(\Velk\App::instance()->adminI18n->labelFor($code)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <p class="field__help"><?= __('users.language_help') ?></p>
        </div>
    </div>

</form>

    <div class="form-actions">
        <button class="button" type="submit" form="user-form"><?= $isNew ? __('users.create_button') : __('members.save_changes') ?></button>
        <a class="button button--ghost" href="<?= e(admin_url('users')) ?>"><?= __('common.cancel') ?></a>
        <span class="spacer"></span>
        <?php if (!$isNew && !$isSelf): ?>
            <form method="post" action="<?= e(admin_url('users/' . $user->id . '/delete')) ?>" data-confirm="<?= __('users.confirm_delete') ?>" style="margin:0">
                <?= csrf_field() ?>
                <button class="button button--small button--danger" type="submit"><?= __('common.delete') ?></button>
            </form>
        <?php endif; ?>
    </div>
