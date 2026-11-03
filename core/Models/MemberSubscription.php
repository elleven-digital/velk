<?php

declare(strict_types=1);

namespace Velk\Models;

use Velk\App;

/**
 * Estado de uma assinatura de membro, espelhado do Stripe pelos webhooks.
 *
 * "Ativo" (concede acesso) = status em ACTIVE_STATUSES. past_due está
 * incluído de propósito: durante as retentativas do Stripe (graça), o
 * membro não é cortado por uma falha pontual de cartão. Endurecer a
 * política = remover 'past_due' da constante.
 */
final class MemberSubscription
{
    public const ACTIVE_STATUSES = ['active', 'trialing', 'past_due'];

    /** Estados terminais — nunca devem ser "ressuscitados" a um estado ativo. */
    public const TERMINAL_STATUSES = ['canceled', 'incomplete_expired'];

    public int $id;
    public int $memberId;
    public string $planKey;
    public string $interval;
    public string $status;
    public string $stripeCustomerId;
    public string $stripeSubscriptionId;
    public ?string $currentPeriodEnd = null;
    public bool $cancelAtPeriodEnd = false;
    public string $createdAt;
    public string $updatedAt;
    public ?int $lastEventTs = null;

    /** PURO — decide se um status concede acesso. */
    public static function isActiveStatus(string $status): bool
    {
        return in_array($status, self::ACTIVE_STATUSES, true);
    }

    public function isActive(): bool
    {
        return self::isActiveStatus($this->status);
    }

    /** PURO — estado terminal (cancelado/expirado), não reativável. */
    public static function isTerminalStatus(string $status): bool
    {
        return in_array($status, self::TERMINAL_STATUSES, true);
    }

    /** PURO — aplica o evento só se não for mais antigo que o último aplicado. */
    public static function shouldApply(?int $storedTs, int $incomingTs): bool
    {
        return $storedTs === null || $incomingTs >= $storedTs;
    }

    /**
     * PURO — dado o conjunto de plan_keys ATIVOS de um membro, ele viola a
     * regra de cardinalidade (site.json → members.multiple_plans)?
     *   único (false):    mais de uma ativa
     *   múltiplo (true):  algum plan_key repetido
     * Uma regra, três chamadores com conjuntos diferentes: o formulário
     * passa "ativas + a pedida", o webhook "ativas depois de gravar", o
     * admin "ativas do membro".
     * plan_key vazio (assinatura sem preço no cache nem metadata) conta como
     * um plano: duas vazias violam no modo múltiplo de propósito —
     * desconhecido é suspeito, e filtrar sumiria com o sinal.
     */
    public static function violatesCardinality(array $activePlanKeys, bool $multiplePlans): bool
    {
        $keys = array_values(array_map('strval', $activePlanKeys));
        if (!$multiplePlans) {
            return count($keys) > 1;
        }
        return count(array_unique($keys)) < count($keys);
    }

    /**
     * PURO — agrupa linhas {member_id, plan_key, status} por membro,
     * preservando a ordem de entrada (o chamador ordena por id DESC = mais
     * recente primeiro). É a forma que a lista de membros do admin consome.
     * @return array<int, list<array{plan_key:string,status:string}>>
     */
    public static function groupActiveByMember(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['member_id']][] = [
                'plan_key' => (string) $r['plan_key'],
                'status' => (string) $r['status'],
            ];
        }
        return $out;
    }

    public static function fromRow(array $row): self
    {
        $s = new self();
        $s->id = (int) $row['id'];
        $s->memberId = (int) $row['member_id'];
        $s->planKey = (string) $row['plan_key'];
        $s->interval = (string) $row['billing_interval'];
        $s->status = (string) $row['status'];
        $s->stripeCustomerId = (string) $row['stripe_customer_id'];
        $s->stripeSubscriptionId = (string) $row['stripe_subscription_id'];
        $s->currentPeriodEnd = $row['current_period_end'] !== null ? (string) $row['current_period_end'] : null;
        $s->cancelAtPeriodEnd = (int) ($row['cancel_at_period_end'] ?? 0) === 1;
        $s->createdAt = (string) $row['created_at'];
        $s->updatedAt = (string) $row['updated_at'];
        $s->lastEventTs = isset($row['last_event_ts']) && $row['last_event_ts'] !== null
            ? (int) $row['last_event_ts']
            : null;
        return $s;
    }

    /**
     * Assinaturas ATIVAS do membro, mais recente primeiro. Sem
     * members.multiple_plans deve haver no máximo uma — mais do que isso é
     * anomalia que o admin marca; com a flag, várias (uma por plano).
     * @return list<self>
     */
    public static function activeListForMember(int $memberId): array
    {
        $placeholders = implode(',', array_fill(0, count(self::ACTIVE_STATUSES), '?'));
        $rows = App::instance()->db->fetchAll(
            "SELECT * FROM member_subscriptions
             WHERE member_id = ? AND status IN ({$placeholders})
             ORDER BY id DESC",
            array_merge([$memberId], self::ACTIVE_STATUSES)
        );
        return array_map(fn($r) => self::fromRow($r), $rows);
    }

    /** A mais recente das ativas, ou null. */
    public static function activeForMember(int $memberId): ?self
    {
        return self::activeListForMember($memberId)[0] ?? null;
    }

    /**
     * Upsert idempotente por stripe_subscription_id, resistente a
     * reentrega fora de ordem (C1):
     *   - Guarda 1: ignora eventos mais antigos que o último aplicado.
     *   - Guarda 2: nunca reativa uma assinatura já terminal (canceled/
     *     expirada) — Stripe nunca volta um sub cancelado a ativo; um
     *     resubscribe gera um novo id (nova linha).
     *
     * M6: o SELECT-then-INSERT abaixo tem uma corrida — dois eventos
     * quase simultâneos pra uma assinatura NOVA (checkout.session.completed
     * + customer.subscription.created chegando juntos) podem ambos ver
     * $existing === null e tentar INSERT; o perdedor bate na UNIQUE key de
     * stripe_subscription_id (MySQL 1062) e, sem tratamento, isso subiria
     * como 500 — só fazendo o Stripe reentregar no mesmo race. Em vez de
     * deixar propagar, o 1062 é tratado como "alguém venceu a corrida":
     * reconsulta e converge pra UPDATE, aplicando as MESMAS guardas
     * (fora-de-ordem / terminal) que o ramo "já existe" já aplicava.
     *
     * T4 (payment-webhook): o retorno diz ao chamador (SubscriptionService)
     * SE e COMO aplicou, pra decidir se dispara o `subscription.<status>`
     * do tema (tie-replay hardening — ver design doc):
     *   - `null` quando NADA foi aplicado (guarda 1 ou 2 barrou) — o
     *     chamador nunca dispara.
     *   - `['previous_status' => ?string, 'ts_advanced' => bool]` quando
     *     aplicou. `previous_status` é o status ANTES desta escrita (null
     *     no primeiro insert — não havia "antes"); comparado ao novo status
     *     pelo chamador pra saber se houve TRANSIÇÃO. `ts_advanced` é true
     *     quando o timestamp do evento avançou de fato (`$eventTs >
     *     $storedTs`, ou não havia ts anterior) — distinto de shouldApply()
     *     usar `>=`: um evento com ts EMPATADO passa a guarda (ainda é
     *     gravado, pra manter os outros campos atualizados) mas não é uma
     *     "renovação" de verdade, só um replay byte-idêntico. O chamador
     *     dispara quando `previous_status !== novo status OU ts_advanced`
     *     — cobre renovação (mesmo status, ts novo) sem re-disparar em
     *     retentativa exata do Stripe (mesmo status, mesmo ts).
     */
    public static function upsertFromStripe(array $data, int $eventTs): ?array
    {
        $db = App::instance()->db;
        $existing = $db->fetch(
            'SELECT id, status, last_event_ts FROM member_subscriptions WHERE stripe_subscription_id = ? LIMIT 1',
            [$data['stripe_subscription_id']]
        );

        if ($existing === null) {
            try {
                $db->insert('member_subscriptions', $data);
                return ['previous_status' => null, 'ts_advanced' => true];
            } catch (\PDOException $e) {
                if (!self::isDuplicateKeyError($e)) {
                    throw $e;
                }
                $existing = $db->fetch(
                    'SELECT id, status, last_event_ts FROM member_subscriptions WHERE stripe_subscription_id = ? LIMIT 1',
                    [$data['stripe_subscription_id']]
                );
                if ($existing === null) {
                    // Não deveria acontecer (a linha sumiu entre o INSERT
                    // que falhou e este re-SELECT) — não engole em silêncio.
                    throw $e;
                }
            }
        }

        $storedTs = $existing['last_event_ts'] !== null ? (int) $existing['last_event_ts'] : null;
        if (!self::shouldApply($storedTs, $eventTs)) {
            return null; // evento obsoleto (chegou fora de ordem)
        }
        if (self::isTerminalStatus((string) $existing['status']) && !self::isTerminalStatus((string) $data['status'])) {
            return null; // não ressuscita assinatura terminal
        }

        $previousStatus = (string) $existing['status'];
        $tsAdvanced = $storedTs === null || $eventTs > $storedTs;

        // Compare-and-swap: o SELECT lá em cima e este UPDATE não são atômicos.
        // Dois webhooks do MESMO objeto chegando juntos passam ambos pelo
        // shouldApply() e o mais antigo pode gravar por último. A cláusula
        // repete a regra no banco; se nada foi atualizado, alguém mais novo
        // chegou antes e este evento é obsoleto.
        $set = [];
        $params = [];
        foreach ($data as $col => $val) {
            $set[] = "{$col} = ?";
            $params[] = $val;
        }
        $params[] = $data['stripe_subscription_id'];
        $params[] = $eventTs;
        $stmt = $db->query(
            'UPDATE member_subscriptions SET ' . implode(', ', $set)
            . ' WHERE stripe_subscription_id = ? AND (last_event_ts IS NULL OR last_event_ts <= ?)',
            $params
        );
        if ($stmt->rowCount() === 0) {
            return null; // perdeu a corrida para um evento mais novo
        }

        return ['previous_status' => $previousStatus, 'ts_advanced' => $tsAdvanced];
    }

    /**
     * PURO — true quando a PDOException é uma violação de UNIQUE/PK
     * (duplicate entry, MySQL 1062). Mesmo estilo de
     * Installer::isUnknownDatabaseError(): checa o código do driver
     * primeiro, cai pra substring da mensagem como rede de segurança.
     */
    public static function isDuplicateKeyError(\PDOException $e): bool
    {
        $code = $e->errorInfo[1] ?? null;
        if ($code === 1062) {
            return true;
        }
        return str_contains($e->getMessage(), 'Duplicate entry');
    }
}
