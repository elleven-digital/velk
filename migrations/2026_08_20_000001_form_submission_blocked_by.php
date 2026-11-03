<?php

declare(strict_types=1);

use Velk\Database;

/**
 * `blocked_by` — a submissão reprovada pelo antispam vira linha marcada, em
 * vez de ser descartada.
 *
 * ─── Por que na mesma tabela, e não numa tabela de spam ──────────────────
 *
 * Porque o dado é o MESMO. Um lead bloqueado por engano tem `data`, `ip`,
 * `user_agent`, `referer` e `params` exatamente como um aprovado — o que
 * muda é um julgamento sobre ele, não sua natureza. Tabela separada
 * duplicaria o model, a tela, o download de anexo e o delete para guardar a
 * mesma coisa com outro nome.
 *
 * O custo é o inverso: toda consulta existente passa a precisar excluir os
 * bloqueados, ou a lista de leads se enche de spam. São seis pontos, cinco
 * dentro do FormSubmission e um no contador da sidebar, e é por isso que o
 * default do model é "só os aprovados" — quem quer spam pede.
 *
 * ─── Por que isto existe ─────────────────────────────────────────────────
 *
 * Falso positivo é real nos dois canais. Turnstile reprova humano com JS
 * bloqueado ou rede ruim (está escrito no PublicFormController). Honeypot é
 * preenchido por gerenciador de senha e autofill do navegador. Antes desta
 * coluna, os dois casos perdiam o lead em silêncio: ninguém ficava sabendo
 * que houve alguém do outro lado.
 *
 * ─── VARCHAR e não ENUM ──────────────────────────────────────────────────
 *
 * Hoje só existem 'honeypot' e 'turnstile'. ENUM travaria um terceiro canal
 * atrás de uma migration de ALTER numa tabela que pode ter milhões de
 * linhas. NULL é o valor normal: submissão aprovada.
 *
 * O índice cobre as duas leituras que a tela faz — listar por formulário
 * separando aprovado de bloqueado, ordenado por data.
 */
return function (Database $db): void {
    $columns = $db->fetchAll("SHOW COLUMNS FROM form_submissions LIKE 'blocked_by'");
    if ($columns === []) {
        $db->query("
            ALTER TABLE form_submissions
            ADD COLUMN blocked_by VARCHAR(20) NULL DEFAULT NULL AFTER form_id
        ");
    }

    $indexes = $db->fetchAll("SHOW INDEX FROM form_submissions WHERE Key_name = 'idx_form_submissions_blocked'");
    if ($indexes === []) {
        $db->query("
            CREATE INDEX idx_form_submissions_blocked
            ON form_submissions (form_id, blocked_by, created_at)
        ");
    }
};
