<?php

declare(strict_types=1);

use Velk\Database;

/**
 * Assinaturas de membros (Stripe).
 *
 *   - member_subscriptions: uma linha por assinatura do Stripe. O estado
 *     é sincronizado pelos webhooks (fonte da verdade). Linhas canceladas
 *     coexistem com a corrente — um membro pode reassinar. UNIQUE em
 *     stripe_subscription_id garante upsert idempotente.
 *
 *   - members.stripe_customer_id: vínculo durável membro↔Customer do
 *     Stripe. Pré-criado antes do primeiro checkout, reaproveitado em
 *     resubscribes e no Customer Portal.
 */
return function (Database $db): void {
    $db->query("
        CREATE TABLE IF NOT EXISTS member_subscriptions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            member_id INT UNSIGNED NOT NULL,
            plan_key VARCHAR(100) NOT NULL,
            billing_interval VARCHAR(10) NOT NULL,
            status VARCHAR(30) NOT NULL,
            stripe_customer_id VARCHAR(255) NOT NULL,
            stripe_subscription_id VARCHAR(255) NOT NULL,
            current_period_end DATETIME NULL,
            cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_stripe_subscription (stripe_subscription_id),
            KEY idx_member_status (member_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->query("ALTER TABLE members ADD COLUMN stripe_customer_id VARCHAR(255) NULL AFTER two_factor_enabled");
};
