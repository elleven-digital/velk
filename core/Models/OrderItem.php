<?php

declare(strict_types=1);

namespace Velk\Models;

use Velk\App;

/** Linha de um pedido — snapshot de título/preço/tipo no momento da compra. */
final class OrderItem
{
    public int $id;
    public int $orderId;
    public ?int $itemId = null;
    public string $itemType;
    public string $title;
    public int $unitAmount;
    public int $quantity;
    public string $createdAt;

    public static function fromRow(array $row): self
    {
        $li = new self();
        $li->id = (int) $row['id'];
        $li->orderId = (int) $row['order_id'];
        $li->itemId = $row['item_id'] !== null ? (int) $row['item_id'] : null;
        $li->itemType = (string) $row['item_type'];
        $li->title = (string) $row['title'];
        $li->unitAmount = (int) $row['unit_amount'];
        $li->quantity = (int) $row['quantity'];
        $li->createdAt = (string) $row['created_at'];
        return $li;
    }

    /** @return list<self> */
    public static function forOrder(int $orderId): array
    {
        $rows = App::instance()->db->fetchAll(
            'SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC',
            [$orderId]
        );
        return array_map(fn($r) => self::fromRow($r), $rows);
    }

    /**
     * Insere as linhas resolvidas de um pedido. Cada $line:
     * ['item_id','item_type','title','unit_amount','quantity'].
     */
    public static function createMany(int $orderId, array $lines): void
    {
        $db = App::instance()->db;
        foreach ($lines as $line) {
            $db->insert('order_items', [
                'order_id' => $orderId,
                'item_id' => $line['item_id'] ?? null,
                'item_type' => (string) $line['item_type'],
                'title' => (string) $line['title'],
                'unit_amount' => (int) $line['unit_amount'],
                'quantity' => (int) $line['quantity'],
            ]);
        }
    }
}
