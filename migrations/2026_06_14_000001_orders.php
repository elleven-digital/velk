<?php

declare(strict_types=1);

use Velk\Database;

/**
 * Venda de itens (Stripe pagamento único).
 *
 *   - orders: um por compra. Criado 'pending' no checkout; o webhook
 *     marca 'paid' (ou 'expired'). stripe_session_id é UNIQUE (chave de
 *     idempotência); a sessão é criada ANTES da order, então nunca é nula.
 *   - order_items: linhas com SNAPSHOT de título/preço/tipo, pra o pedido
 *     ser registro histórico imutável.
 */
return function (Database $db): void {
    $db->query("
        CREATE TABLE IF NOT EXISTS orders (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            member_id INT UNSIGNED NULL,
            email VARCHAR(255) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL,
            currency CHAR(3) NOT NULL,
            total_amount INT UNSIGNED NOT NULL,
            stripe_session_id VARCHAR(255) NOT NULL,
            stripe_payment_intent VARCHAR(255) NULL,
            last_event_ts INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_stripe_session (stripe_session_id),
            KEY idx_member_status (member_id, status),
            KEY idx_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->query("
        CREATE TABLE IF NOT EXISTS order_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED NOT NULL,
            item_id INT UNSIGNED NULL,
            item_type VARCHAR(100) NOT NULL,
            title VARCHAR(255) NOT NULL,
            unit_amount INT UNSIGNED NOT NULL,
            quantity INT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_order (order_id),
            KEY idx_item_type (item_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
};
