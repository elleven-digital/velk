<?php
/**
 * @var \Velk\Models\Member|null $member
 * @var array{name:string,email:string,type:string} $values
 * @var array<string,string> $errors
 * @var array $fieldDefs
 * @var array $types
 * @var bool $hasTypes
 */
$isNew = $member === null;
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= $isNew ? __('members.new_title') : e($member->name ?? $member->email) ?></h1>
        <p class="page-header__subtitle">
            <?php if ($isNew): ?>
                <?= __('members.new_subtitle') ?>
            <?php else: ?>
                <?= __('members.edit_subtitle', ['date' => date('d/m/Y', strtotime($member->createdAt))]) ?>
                <?php if ($member->lastLoginAt !== null): ?>
                    <?= __('members.edit_subtitle_last_login', ['date' => date('d/m/Y H:i', strtotime($member->lastLoginAt))]) ?>
                <?php endif; ?>
            <?php endif; ?>
        </p>
    </div>
</div>

<form method="post" class="form" id="member-form">
    <?= csrf_field() ?>

    <div class="form-row">
        <div class="field">
            <label class="field__label" for="name"><?= __('forms.col_name') ?></label>
            <div class="field__control">
                <input class="input" type="text" name="name" id="name" value="<?= e($values['name']) ?>" autofocus>
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

        <?php if ($hasTypes): ?>
            <div class="field">
                <label class="field__label" for="type"><?= __('members.field_type') ?></label>
                <div class="field__control">
                    <select class="input input--select" name="type" id="type">
                        <?php foreach ($types as $key => $def): ?>
                            <option value="<?= e((string) $key) ?>" <?= $values['type'] === (string) $key ? 'selected' : '' ?>>
                                <?= e((string) ($def['label'] ?? $key)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <p class="field__help"><?= __('members.type_help') ?></p>
                <?php if (!empty($errors['type'])): ?>
                    <p class="field__help" style="color:var(--danger)"><?= e($errors['type']) ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($fieldDefs)): ?>
        <?= \Velk\FieldRenderer::renderFields($fieldDefs, $member?->fields ?? [], 'fields') ?>
    <?php endif; ?>

    <div class="form-row">
        <div class="field">
            <label class="field__label"><?= __('members.security_label') ?></label>
            <div class="field__control">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
                    <input type="checkbox" name="two_factor" value="1" <?= !empty($values['two_factor']) ? 'checked' : '' ?>>
                    <?= __('members.two_factor_label') ?>
                </label>
            </div>
            <p class="field__help"><?= __('members.two_factor_help') ?></p>
        </div>
    </div>

</form>

    <div class="form-actions">
        <button class="button" type="submit" form="member-form"><?= $isNew ? __('members.create_button') : __('members.save_changes') ?></button>
        <a class="button button--ghost" href="<?= e(admin_url('members')) ?>"><?= __('common.cancel') ?></a>
        <?php if (!$isNew): ?>
            <span class="spacer"></span>
            <form method="post" action="<?= e(admin_url('members/' . $member->id . '/delete')) ?>" data-confirm="<?= __('members.confirm_delete') ?>" style="margin:0">
                <?= csrf_field() ?>
                <button class="button button--small button--danger" type="submit"><?= __('common.delete') ?></button>
            </form>
        <?php endif; ?>
    </div>
