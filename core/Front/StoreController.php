<?php

declare(strict_types=1);

namespace Velk\Front;

use Velk\App;
use Velk\Models\Order;
use Velk\Request;
use Velk\Response;
use Velk\Services\OrderService;

/**
 * Endpoints públicos da loja. checkout aceita convidado E membro (o tema
 * decide se exige login); exige CSRF. success/cancel são cosméticos — o
 * estado 'paid' é gravado pelo webhook.
 */
final class StoreController
{
    private const STATUS_KEY = '_store_status';

    public function checkout(Request $request): Response
    {
        $app = App::instance();
        if (!$app->config->isStoreEnabled()) {
            return Response::notFound();
        }

        $back = $this->backUrl($request);

        if (!$request->isPost()) {
            return Response::redirect($back);
        }
        if (!$app->session->verifyCsrf((string) ($request->post['_csrf'] ?? ''))) {
            return $this->flash($back, 'error', 'session_expired');
        }

        $rawItems = $request->post['items'] ?? [];
        if (!is_array($rawItems) || $rawItems === []) {
            return $this->flash($back, 'error', 'cart_empty');
        }

        $service = OrderService::make();
        $lines = $service->resolveCart($rawItems);
        if ($lines === []) {
            return $this->flash($back, 'error', 'cart_invalid');
        }

        $member = $app->memberAuth->member(); // null = convidado

        // repeat_purchase (spec 2026-09-07): tipo de compra única é item de
        // uma unidade, e membro logado que já tem o item não paga de novo —
        // a linha cai; se não sobrar nada, already_purchased. Convidado passa:
        // a regra é por membro, e o tema decide se exige login. Sem tipo de
        // compra única não há consulta nenhuma.
        $singleTypes = $app->config->singlePurchaseTypes();
        if ($singleTypes !== []) {
            $owned = $member !== null
                ? Order::paidItemIdsForMember($member->id, array_column($lines, 'item_id'))
                : [];
            $lines = OrderService::enforceRepeatPurchase($lines, $singleTypes, $owned);
            if ($lines === []) {
                return $this->flash($back, 'info', 'already_purchased');
            }
        }
        $routes = $app->config->storeRoutes();
        $success = absolute_path_url($routes['success']);
        $cancel = absolute_path_url($routes['cancel']);

        try {
            $url = $service->createCheckout($lines, $member, $success, $cancel);
        } catch (\Throwable $e) {
            error_log('[Velk] store checkout failed: ' . $e->getMessage());
            return $this->flash($back, 'error', 'checkout_failed');
        }

        return Response::redirect($url);
    }

    public function success(Request $request): Response
    {
        $app = App::instance();
        if (!$app->config->isStoreEnabled()) {
            return Response::notFound();
        }
        $app->session->put(self::STATUS_KEY, [
            'status' => 'success',
            'code' => 'order_paid',
        ]);
        $base = $app->basePath === '' ? '/' : $app->basePath . '/';
        return Response::redirect($base);
    }

    public function cancel(Request $request): Response
    {
        $app = App::instance();
        if (!$app->config->isStoreEnabled()) {
            return Response::notFound();
        }
        $app->session->put(self::STATUS_KEY, [
            'status' => 'info',
            'code' => 'purchase_cancelled',
        ]);
        $base = $app->basePath === '' ? '/' : $app->basePath . '/';
        return Response::redirect($base);
    }

    // ─── Internals ────────────────────────────────────────────────

    /**
     * Guarda o resultado para o próximo render — como CÓDIGO, não frase.
     *
     * O engine sabe que o carrinho está vazio; só o tema sabe se isso se diz
     * "Seu carrinho está vazio", "Nothing here yet" ou um desenho de carrinho
     * riscado. Mandar texto pronto daqui obrigava todo tema a exibir a cópia
     * do core, em inglês, sem caminho de tradução em site de idioma único.
     */
    private function flash(string $back, string $status, string $code): Response
    {
        App::instance()->session->put(self::STATUS_KEY, ['status' => $status, 'code' => $code]);
        return Response::redirect($back);
    }

    private function backUrl(Request $request): string
    {
        // Só volta para o Referer se ele for deste host — ver
        // Request::refererSameHost() para o porquê. Terceira cópia deste
        // helper no engine; as outras duas já passaram pela mesma regra.
        $same = $request->refererSameHost();
        if ($same !== null) {
            return $same;
        }
        $base = App::instance()->basePath;
        return $base === '' ? '/' : $base . '/';
    }
}
