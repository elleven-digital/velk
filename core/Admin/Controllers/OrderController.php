<?php

declare(strict_types=1);

namespace Velk\Admin\Controllers;

use Velk\Models\Order;
use Velk\Request;
use Velk\Response;

/**
 * Admin: global Orders page. Only mounts when the store is enabled.
 * Read-only — state comes from Stripe via webhook.
 */
final class OrderController extends Controller
{
    public function index(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;

        if (!$this->app->config->isStoreEnabled()) {
            return Response::notFound(__('orders.error_store_disabled'));
        }

        $status = (string) ($request->query['status'] ?? '');
        $status = $status !== '' ? $status : null;
        $type = (string) ($request->query['type'] ?? '');
        $type = $type !== '' ? $type : null;

        $total = Order::countAdmin($status, $type);
        $pg = \Velk\Paginator::make($request->query['page'] ?? null, $total);

        return $this->render('orders/index', [
            'orders' => Order::allAdmin($status, $type, $pg['perPage'], $pg['offset']),
            'total' => $total,
            'pg' => $pg,
            'pgQuery' => array_diff_key($request->query, ['page' => '']),
            'statusFilter' => $status,
            'typeFilter' => $type,
            'sellableTypes' => $this->app->config->sellableTypes(),
        ]);
    }

    public function show(Request $request, array $params): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;

        if (!$this->app->config->isStoreEnabled()) {
            return Response::notFound(__('orders.error_store_disabled'));
        }

        $order = Order::find((int) ($params['id'] ?? 0));
        if ($order === null) {
            return Response::notFound(__('orders.error_not_found'));
        }

        $member = $order->memberId !== null ? \Velk\Models\Member::find($order->memberId) : null;

        return $this->render('orders/show', [
            'order' => $order,
            'items' => $order->items(),
            'member' => $member,
        ]);
    }
}
