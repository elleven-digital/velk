<?php
/**
 * @var \Velk\Models\Order $order
 * @var list<\Velk\Models\OrderItem> $items
 * @var ?\Velk\Models\Member $member
 */
$fmt = fn(int $c) => \Velk\Services\Money::format($c, $order->currency);
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
        <h1 class="page-header__title"><?= __('orders.show_title', ['id' => $order->id]) ?></h1>
        <p class="page-header__subtitle">
            <span class="badge <?= e($badge($order->status)) ?>"><?= e($statusLabels[$order->status] ?? $order->status) ?></span>
            · <?= e(date('d/m/Y H:i', strtotime($order->createdAt))) ?>
        </p>
    </div>
    <div class="page-header__actions">
        <a class="button button--ghost" href="<?= e(admin_url('orders')) ?>"><?= __('orders.back') ?></a>
    </div>
</div>

<div class="table-scroll"><table class="table">
    <thead><tr><th><?= __('orders.col_item') ?></th><th><?= __('orders.col_type') ?></th><th><?= __('orders.col_unit_price') ?></th><th><?= __('orders.col_qty') ?></th><th><?= __('orders.col_subtotal') ?></th></tr></thead>
    <tbody>
        <?php foreach ($items as $li): ?>
            <tr>
                <td><?= e($li->title) ?></td>
                <td class="muted"><?= e($li->itemType) ?></td>
                <td><?= e($fmt($li->unitAmount)) ?></td>
                <td><?= (int) $li->quantity ?></td>
                <td><?= e($fmt($li->unitAmount * $li->quantity)) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td colspan="4" style="text-align:right"><strong><?= __('orders.col_total') ?></strong></td><td><strong><?= e($fmt($order->totalAmount)) ?></strong></td></tr></tfoot>
</table></div>

<div class="card" style="margin-top:16px">
    <p><strong><?= __('orders.buyer_label') ?></strong> <?= e($order->email !== '' ? $order->email : '—') ?>
        <?php if ($member !== null): ?> · <a href="<?= e(admin_url('members/' . $member->id)) ?>"><?= e($member->name ?? $member->email) ?></a><?php endif; ?>
    </p>
    <p class="muted"><strong><?= __('orders.stripe_label') ?></strong> <?= e($order->stripeSessionId) ?><?= $order->stripePaymentIntent !== null ? ' · ' . e($order->stripePaymentIntent) : '' ?></p>
</div>
