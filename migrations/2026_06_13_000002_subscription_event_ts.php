<?php

declare(strict_types=1);

use Velk\Database;

/**
 * Ordenação de webhooks fora de ordem (correção C1).
 *
 * Guarda o timestamp do evento Stripe (event.created) que produziu cada
 * linha. O upsert passa a aplicar apenas eventos com timestamp >= ao
 * último aplicado — evitando que uma reentrega atrasada de
 * 'customer.subscription.updated' (status=active) ressuscite uma
 * assinatura que já recebeu 'customer.subscription.deleted' (canceled).
 */
return function (Database $db): void {
    $db->query("ALTER TABLE member_subscriptions ADD COLUMN last_event_ts INT UNSIGNED NULL AFTER cancel_at_period_end");
};
