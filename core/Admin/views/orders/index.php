<?php
/**
 * @var list<\Velk\Models\Order> $orders
 * @var int $total
 * @var ?string $statusFilter
 * @var ?string $typeFilter
 * @var array $sellableTypes
 */
$fmt = fn(int $c, string $cur) => \Velk\Services\Money::format($c, $cur);
$badge = fn(string $s) => match ($s) {
    'paid' => 'badge--published',
    'failed' => 'badge--danger',
    default => 'badge',
};
$statusLabels = [
    'pending' => __('orders.status_pending'),
    'paid'    => __('orders.status_paid'),
    'expired' => __('orders.status_expired'),
    'failed'  => __('orders.status_failed'),
];
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= __('orders.title') ?></h1>
        <p class="page-header__subtitle"><?= $total === 1 ? __('orders.count_one') : __('orders.count_other', ['count' => $total]) ?></p>
    </div>
</div>

<form class="tbl-toolbar" method="get" action="<?= e(admin_url('orders')) ?>">
    <select class="input input--select" name="status" data-autosubmit>
        <option value=""><?= __('orders.filter_all_statuses') ?></option>
        <?php foreach ($statusLabels as $k => $lbl): ?>
            <option value="<?= e($k) ?>" <?= $statusFilter === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
        <?php endforeach; ?>
    </select>
    <select class="input input--select" name="type" data-autosubmit>
        <option value=""><?= __('members.filter_all_types') ?></option>
        <?php foreach ($sellableTypes as $key => $def): ?>
            <option value="<?= e((string) $key) ?>" <?= $typeFilter === (string) $key ? 'selected' : '' ?>>
                <?= e((string) ($def['label'] ?? $key)) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?php if ($statusFilter !== null || $typeFilter !== null): ?>
        <a class="tbl-clear" href="<?= e(admin_url('orders')) ?>"><?= __('items.clear') ?></a>
    <?php endif; ?>
</form>

<?php if (empty($orders)): ?>
    <div class="empty"><?= ($statusFilter !== null || $typeFilter !== null) ? __('orders.empty_filtered') : __('orders.empty') ?></div>
<?php else: ?>
    <div class="table-scroll"><table class="table">
        <thead>
            <tr>
                <th>#</th>
                <th><?= __('orders.col_buyer') ?></th>
                <th><?= __('orders.col_items') ?></th>
                <th><?= __('orders.col_total') ?></th>
                <th><?= __('items.status_label') ?></th>
                <th><?= __('orders.col_date') ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($orders as $o): ?>
                <tr>
                    <td>#<?= (int) $o->id ?></td>
                    <td><?= e($o->email !== '' ? $o->email : '—') ?><?= $o->memberId !== null ? ' <span class="badge">' . __('orders.badge_member') . '</span>' : '' ?></td>
                    <td class="muted"><?= (int) count($o->items()) ?></td>
                    <td><?= e($fmt($o->totalAmount, $o->currency)) ?></td>
                    <td><span class="badge <?= e($badge($o->status)) ?>"><?= e($statusLabels[$o->status] ?? $o->status) ?></span></td>
                    <td class="muted"><?= e(date('d/m/Y H:i', strtotime($o->createdAt))) ?></td>
                    <td class="table__actions">
                        <a class="button button--small button--ghost" href="<?= e(admin_url('orders/' . $o->id)) ?>"><?= __('pages.view') ?></a>
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
