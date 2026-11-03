<?php

declare(strict_types=1);

namespace Velk\Models;

use Velk\App;

/**
 * Pedido de venda (pagamento único). Criado 'pending' no checkout; o
 * webhook marca 'paid'/'expired'. As guardas de idempotência espelham a
 * assinatura: ordenação por last_event_ts + estados terminais que não
 * regridem (uma reentrega atrasada não rebaixa um pedido pago).
 */
final class Order
{
    public const TERMINAL_STATUSES = ['paid', 'expired', 'failed'];

    public int $id;
    public ?int $memberId = null;
    public string $email = '';
    public string $status;
    public string $currency;
    public int $totalAmount;
    public string $stripeSessionId;
    public ?string $stripePaymentIntent = null;
    public ?int $lastEventTs = null;
    public string $createdAt;
    public string $updatedAt;

    public static function isTerminalStatus(string $status): bool
    {
        return in_array($status, self::TERMINAL_STATUSES, true);
    }

    public static function shouldApply(?int $storedTs, int $incomingTs): bool
    {
        return $storedTs === null || $incomingTs >= $storedTs;
    }

    public static function fromRow(array $row): self
    {
        $o = new self();
        $o->id = (int) $row['id'];
        $o->memberId = $row['member_id'] !== null ? (int) $row['member_id'] : null;
        $o->email = (string) $row['email'];
        $o->status = (string) $row['status'];
        $o->currency = (string) $row['currency'];
        $o->totalAmount = (int) $row['total_amount'];
        $o->stripeSessionId = (string) $row['stripe_session_id'];
        $o->stripePaymentIntent = $row['stripe_payment_intent'] !== null ? (string) $row['stripe_payment_intent'] : null;
        $o->lastEventTs = $row['last_event_ts'] !== null ? (int) $row['last_event_ts'] : null;
        $o->createdAt = (string) $row['created_at'];
        $o->updatedAt = (string) $row['updated_at'];
        return $o;
    }

    public static function find(int $id): ?self
    {
        $row = App::instance()->db->fetch('SELECT * FROM orders WHERE id = ? LIMIT 1', [$id]);
        return $row === null ? null : self::fromRow($row);
    }

    public static function findByStripeSession(string $sessionId): ?self
    {
        $row = App::instance()->db->fetch('SELECT * FROM orders WHERE stripe_session_id = ? LIMIT 1', [$sessionId]);
        return $row === null ? null : self::fromRow($row);
    }

    /** @return list<self> */
    public static function forMember(int $memberId, string $status = 'paid'): array
    {
        $rows = App::instance()->db->fetchAll(
            'SELECT * FROM orders WHERE member_id = ? AND status = ? ORDER BY id DESC',
            [$memberId, $status]
        );
        return array_map(fn($r) => self::fromRow($r), $rows);
    }

    /** O membro tem um pedido PAGO contendo este item? (gating digital) */
    public static function paidForMemberContainingItem(int $memberId, int $itemId): bool
    {
        return self::countPaidForMemberContainingItem($memberId, $itemId) > 0;
    }

    /**
     * Quantos pedidos PAGOS (distintos) do membro contêm este item. > 1 é
     * compra repetida — o que repeat_purchase: false tenta impedir e o
     * webhook registra quando escapa.
     */
    public static function countPaidForMemberContainingItem(int $memberId, int $itemId): int
    {
        return (int) App::instance()->db->fetchColumn(
            'SELECT COUNT(DISTINCT o.id) FROM orders o
             JOIN order_items oi ON oi.order_id = o.id
             WHERE o.member_id = ? AND o.status = ? AND oi.item_id = ?',
            [$memberId, 'paid', $itemId]
        );
    }

    /**
     * Entre $itemIds, os que o membro já tem em pedido PAGO — uma consulta,
     * só sobre os ids do carrinho. É o que o checkout passa a
     * OrderService::enforceRepeatPurchase().
     * @return list<int>
     */
    public static function paidItemIdsForMember(int $memberId, array $itemIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $itemIds)));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = App::instance()->db->fetchAll(
            "SELECT DISTINCT oi.item_id FROM orders o
             JOIN order_items oi ON oi.order_id = o.id
             WHERE o.member_id = ? AND o.status = 'paid' AND oi.item_id IN ({$placeholders})",
            array_merge([$memberId], $ids)
        );
        return array_map(fn($r) => (int) $r['item_id'], $rows);
    }

    /** Lista paginada para o admin, com filtros opcionais. @return list<self> */
    public static function allAdmin(?string $status, ?string $itemType, int $limit = 100, int $offset = 0): array
    {
        $db = App::instance()->db;
        $where = [];
        $params = [];
        if ($status !== null && $status !== '') {
            $where[] = 'o.status = ?';
            $params[] = $status;
        }
        if ($itemType !== null && $itemType !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id AND oi.item_type = ?)';
            $params[] = $itemType;
        }
        $sql = 'SELECT o.* FROM orders o';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY o.id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
        $rows = $db->fetchAll($sql, $params);
        return array_map(fn($r) => self::fromRow($r), $rows);
    }

    public static function countAdmin(?string $status, ?string $itemType): int
    {
        $db = App::instance()->db;
        $where = [];
        $params = [];
        if ($status !== null && $status !== '') {
            $where[] = 'o.status = ?';
            $params[] = $status;
        }
        if ($itemType !== null && $itemType !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id AND oi.item_type = ?)';
            $params[] = $itemType;
        }
        $sql = 'SELECT COUNT(*) FROM orders o';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        return (int) $db->fetchColumn($sql, $params);
    }

    /** @return list<OrderItem> */
    public function items(): array
    {
        return OrderItem::forOrder($this->id);
    }

    public static function createPending(array $data): int
    {
        return App::instance()->db->insert('orders', $data);
    }

    /**
     * Atualiza o status a partir de um evento do webhook, com as guardas:
     * ignora eventos obsoletos (last_event_ts) e não regride estado terminal.
     * O banco é sempre atualizado (ts/payment_intent/email) quando as guardas
     * passam, mesmo reaplicando o mesmo status — mas o retorno só carrega o
     * status quando ele DIFERE do status anteriormente gravado (transição de
     * fato). Devolve `null` em qualquer um dos caminhos de skip (pedido não
     * encontrado, `shouldApply` falso, guarda de estado terminal) OU quando a
     * escrita reaplica o status já existente — endurecimento contra
     * "tie replay": `shouldApply` usa `>=` de propósito (dois eventos
     * DIFERENTES no mesmo segundo ainda devem aplicar), então uma retentativa
     * Stripe byte-idêntica (mesmo evento, mesmo `created`) ou um segundo
     * evento apenas confirmando o mesmo resultado (ex.: `completed(paid)`
     * seguido de `async_payment_succeeded(paid)`) passa pelas guardas acima
     * e grava de novo — mas não deve re-disparar o `payment_webhook` do tema
     * (o chamador só dispara quando o retorno aqui é não-null).
     */
    public static function markStatusFromWebhook(
        string $sessionId,
        string $newStatus,
        int $eventTs,
        ?string $paymentIntent,
        ?string $email
    ): ?string {
        $db = App::instance()->db;
        $existing = $db->fetch(
            'SELECT id, status, last_event_ts, email FROM orders WHERE stripe_session_id = ? LIMIT 1',
            [$sessionId]
        );
        if ($existing === null) {
            error_log('[Velk] store webhook: pedido não encontrado p/ session ' . $sessionId);
            return null;
        }

        $storedTs = $existing['last_event_ts'] !== null ? (int) $existing['last_event_ts'] : null;
        if (!self::shouldApply($storedTs, $eventTs)) {
            return null;
        }
        if (self::isTerminalStatus((string) $existing['status']) && !self::isTerminalStatus($newStatus)) {
            return null;
        }

        $update = ['status' => $newStatus, 'last_event_ts' => $eventTs];
        if ($paymentIntent !== null && $paymentIntent !== '') {
            $update['stripe_payment_intent'] = $paymentIntent;
        }
        if ((string) ($existing['email'] ?? '') === '' && $email !== null && $email !== '') {
            $update['email'] = $email;
        }
        // Compare-and-swap — mesma razão do MemberSubscription::upsertFromStripe:
        // o SELECT acima e este UPDATE não são atômicos, e dois webhooks do
        // mesmo pedido chegando juntos podem gravar fora de ordem.
        $set = [];
        $params = [];
        foreach ($update as $col => $val) {
            $set[] = "{$col} = ?";
            $params[] = $val;
        }
        $params[] = $existing['id'];
        $params[] = $eventTs;
        $stmt = $db->query(
            'UPDATE orders SET ' . implode(', ', $set)
            . ' WHERE id = ? AND (last_event_ts IS NULL OR last_event_ts <= ?)',
            $params
        );
        if ($stmt->rowCount() === 0) {
            return null; // perdeu a corrida para um evento mais novo
        }

        return $newStatus !== (string) $existing['status'] ? $newStatus : null;
    }
}
