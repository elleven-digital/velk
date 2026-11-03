<?php
/**
 * @var list<\Velk\Models\Member> $members
 * @var array $types
 * @var ?string $typeFilter
 * @var int $total
 * @var bool $publicRegistration
 * @var bool $showPlans
 * @var array<int,list<array{plan_key:string,status:string}>> $subscriptions
 * @var array<int,bool> $subscriptionConflicts
 * @var array<string,string> $planLabels
 */
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= __('members.title') ?></h1>
        <p class="page-header__subtitle">
            <?= $total === 1 ? __('members.count_one') : __('members.count_other', ['count' => $total]) ?>
            <?php if ($publicRegistration): ?>
                · <span class="badge badge--published"><?= __('members.badge_public_registration') ?></span>
            <?php else: ?>
                · <span class="badge"><?= __('members.badge_panel_only') ?></span>
            <?php endif; ?>
        </p>
    </div>
    <div class="page-header__actions">
        <a class="button" href="<?= e(admin_url('members/new')) ?>"><?= __('members.new') ?></a>
    </div>
</div>

<?php if (!empty($types)): ?>
    <form class="tbl-toolbar" method="get" action="<?= e(admin_url('members')) ?>">
        <select class="input input--select" name="type" data-autosubmit>
            <option value=""><?= __('members.filter_all_types') ?></option>
            <?php foreach ($types as $key => $def): ?>
                <option value="<?= e((string) $key) ?>" <?= $typeFilter === (string) $key ? 'selected' : '' ?>>
                    <?= e((string) ($def['label'] ?? $key)) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ($typeFilter !== null): ?>
            <a class="tbl-clear" href="<?= e(admin_url('members')) ?>" title="<?= __('members.clear_filter_title') ?>"><?= __('items.clear') ?></a>
        <?php endif; ?>
    </form>
<?php endif; ?>

<?php if (empty($members)): ?>
    <div class="empty">
        <?= $typeFilter !== null ? __('members.empty_prefix_filtered') : __('members.empty_prefix') ?> <a href="<?= e(admin_url('members/new')) ?>"><?= __('items.empty_create_link') ?></a>.
    </div>
<?php else: ?>
    <div class="table-scroll"><table class="table">
        <thead>
            <tr>
                <th><?= __('members.col_name_email') ?></th>
                <?php if (!empty($types)): ?><th><?= __('members.field_type') ?></th><?php endif; ?>
                <th><?= __('forms.col_registered_at') ?></th>
                <th><?= __('members.col_last_login') ?></th>
                <?php if ($showPlans): ?><th><?= __('members.col_plan') ?></th><?php endif; ?>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($members as $m): ?>
                <tr>
                    <td>
                        <div class="table__title"><?= e($m->name ?? $m->email) ?></div>
                        <?php if ($m->name !== null): ?>
                            <div class="muted"><?= e($m->email) ?></div>
                        <?php endif; ?>
                    </td>
                    <?php if (!empty($types)): ?>
                        <td class="muted">
                            <?php
                            $tplLabel = isset($types[$m->type ?? ''])
                                ? (string) ($types[$m->type]['label'] ?? $m->type)
                                : ($m->type ?? '—');
                            ?>
                            <?= e($tplLabel) ?>
                        </td>
                    <?php endif; ?>
                    <td class="muted"><?= e(date('d/m/Y', strtotime($m->createdAt))) ?></td>
                    <td class="muted">
                        <?= $m->lastLoginAt !== null ? e(date('d/m/Y H:i', strtotime($m->lastLoginAt))) : '—' ?>
                    </td>
                    <?php if ($showPlans): ?>
                        <td class="muted">
                            <?php $subs = $subscriptions[$m->id] ?? []; ?>
                            <?php if ($subs === []): ?>—<?php else: ?>
                                <?php if ($subscriptionConflicts[$m->id] ?? false): ?>
                                    <span class="badge badge--danger" title="<?= e(__('members.subscription_conflict')) ?>">!</span>
                                <?php endif; ?>
                                <?php foreach ($subs as $i => $sub): ?>
                                    <?php if ($i > 0): ?><br><?php endif; ?>
                                    <?= e($planLabels[$sub['plan_key']] ?? $sub['plan_key']) ?>
                                    <?php if ($sub['status'] !== 'active'): ?>
                                        <span class="badge"><?= e($sub['status']) ?></span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                    <td class="table__actions">
                        <a class="button button--small button--ghost" href="<?= e(admin_url('members/' . $m->id)) ?>"><?= __('common.edit') ?></a>
                        <form method="post" action="<?= e(admin_url('members/' . $m->id . '/delete')) ?>" data-confirm="<?= __('members.confirm_delete') ?>" style="display:inline">
                            <?= csrf_field() ?>
                            <button class="button button--small button--danger" type="submit"><?= __('common.delete') ?></button>
                        </form>
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
