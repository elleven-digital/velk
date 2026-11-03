<?php

declare(strict_types=1);

use Velk\Database;

/**
 * Preenchimento por etapas: `pending_step` e `step_token`.
 *
 * ─── Por que NULL significa "completo" ──────────────────────────────────
 *
 * É o que torna esta migration puramente aditiva. Toda linha que já existe
 * em `form_submissions` é um lead completo, e com `NULL` = completo ela
 * nasce correta — sem backfill numa tabela que pode ter milhões de
 * registros. Mesmo truque do `blocked_by`, onde `NULL` = aprovada.
 *
 * O inverso (`0` = completo, ou uma coluna `is_complete`) exigiria varrer e
 * atualizar a tabela inteira, e migration no Velk não tem `down`.
 *
 * ─── Duas colunas, e não uma ────────────────────────────────────────────
 *
 * `pending_step` é a AUTORIDADE sobre o lead estar aberto, e é o que o
 * painel lê para dizer "etapa 2 de 4". `step_token` é a CREDENCIAL que o
 * cliente devolve. Vidas diferentes e propósitos diferentes: uma é
 * informação, a outra é segredo. As duas vão a `NULL` no mesmo `UPDATE` do
 * envio final — o ponteiro porque o lead fechou, o token porque ele morre
 * junto, e assim um token vazado de lead antigo não tem nem como ser
 * procurado.
 *
 * ─── UNIQUE aceitando NULL repetido ─────────────────────────────────────
 *
 * No MySQL um índice UNIQUE permite quantos NULL quiser, então milhões de
 * linhas completas convivem sem colidir. O índice existe para a única
 * leitura nova: resolver um token no POST de uma etapa.
 *
 * TINYINT UNSIGNED: 255 etapas é um teto que nenhum formulário usável
 * alcança, e a coluna fica em 1 byte.
 */
return function (Database $db): void {
    $hasColumn = static function (string $column) use ($db): bool {
        return $db->fetchAll("SHOW COLUMNS FROM form_submissions LIKE '{$column}'") !== [];
    };

    if (!$hasColumn('pending_step')) {
        $db->query("
            ALTER TABLE form_submissions
            ADD COLUMN pending_step TINYINT UNSIGNED NULL DEFAULT NULL AFTER blocked_by
        ");
    }

    if (!$hasColumn('step_token')) {
        $db->query("
            ALTER TABLE form_submissions
            ADD COLUMN step_token VARCHAR(64) NULL DEFAULT NULL AFTER pending_step
        ");
    }

    $indexes = $db->fetchAll("SHOW INDEX FROM form_submissions WHERE Key_name = 'uniq_form_submissions_step_token'");
    if ($indexes === []) {
        $db->query("
            CREATE UNIQUE INDEX uniq_form_submissions_step_token
            ON form_submissions (step_token)
        ");
    }
};
