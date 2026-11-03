<?php
/**
 * @var list<\Velk\Models\User> $users
 * @var int|null $currentUserId
 */
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= __('users.title') ?></h1>
        <p class="page-header__subtitle"><?= $total === 1 ? __('users.count_one') : __('users.count_other', ['count' => $total]) ?></p>
    </div>
    <div class="page-header__actions">
        <a class="button" href="<?= e(admin_url('users/new')) ?>"><?= __('users.new') ?></a>
    </div>
</div>

<?php if (empty($users)): ?>
    <div class="empty">
        <?= __('users.empty_prefix') ?> <a href="<?= e(admin_url('users/new')) ?>"><?= __('items.empty_create_link') ?></a>.
    </div>
<?php else: ?>
    <div class="table-scroll"><table class="table">
        <thead>
            <tr>
                <th><?= __('forms.col_name') ?></th>
                <th><?= __('forms.field_email') ?></th>
                <th><?= __('users.col_role') ?></th>
                <th><?= __('users.col_added_at') ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $u): ?>
                <?php $isCurrent = $currentUserId !== null && $u->id === $currentUserId; ?>
                <tr>
                    <td>
                        <div class="table__title">
                            <?= e($u->name) ?>
                            <?php if ($isCurrent): ?>
                                <span class="badge badge--published" style="margin-left:6px"><?= __('users.badge_you') ?></span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="muted"><?= e($u->email) ?></td>
                    <td class="muted"><?= e($u->role) ?></td>
                    <td class="muted"><?= e(date('d/m/Y', strtotime($u->createdAt))) ?></td>
                    <td class="table__actions">
                        <a class="button button--small button--ghost" href="<?= e(admin_url('users/' . $u->id)) ?>"><?= __('common.edit') ?></a>
                        <?php if (!$isCurrent): ?>
                            <form method="post" action="<?= e(admin_url('users/' . $u->id . '/delete')) ?>" data-confirm="<?= __('users.confirm_delete') ?>" style="display:inline">
                                <?= csrf_field() ?>
                                <button class="button button--small button--danger" type="submit"><?= __('common.delete') ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>

<?php
$page = $pg['page'];
$totalPages = $pg['totalPages'];
$query = $pgQuery;
include __DIR__ . '/../layout/pagination.php';
?>
