<?php

declare(strict_types=1);

use Velk\Database;

/**
 * Tabela para os scripts de rastreamento, substituindo os três campos de
 * texto do grupo de opções `tracking` (head / body_start / body_end).
 *
 * ─── Por que sai de options ──────────────────────────────────────────────
 *
 * Os três campos funcionavam enquanto script era "um bloco de HTML por
 * posição". Com consentimento, cada script passa a carregar decisão própria
 * — categoria, e portanto se carrega ou não — e três textareas só conseguem
 * carregar uma decisão cada. O caso real que quebra o modelo antigo é banal:
 * GTM e um monitor de erro, os dois no `<head>`, com consentimentos
 * diferentes.
 *
 * Vira tabela, e não repetidor dentro de options, pelo mesmo critério que
 * levou `form_settings` a ser tabela: cardinalidade N com identidade própria.
 * Some junto o risco do blob — `Option::setGroup()` grava o grupo inteiro, e
 * um save que perdesse esse blob apagaria o rastreamento do site de uma vez.
 *
 * ─── Sem backfill, deliberadamente ───────────────────────────────────────
 *
 * Os valores de `tracking.head` / `body_start` / `body_end` NÃO são migrados
 * automaticamente. Não é economia de trabalho: um bloco de HTML colado pode
 * conter três scripts de serviços diferentes, e só quem administra o site
 * sabe qual categoria cada um recebe. Um backfill teria que jogar tudo numa
 * linha só, marcada como necessária, e o resultado seria pior que o manual —
 * dá a impressão de que migrou, e deixa o site rastreando sem consentimento.
 *
 * Os valores antigos seguem legíveis no banco até alguém salvar a tela de
 * opções (o `setGroup` grava o grupo inteiro, então o primeiro save descarta
 * o que saiu do schema). O procedimento está em upgrade-notes.md na skill:
 * copiar ANTES, recriar depois.
 *
 * ─── Colunas ─────────────────────────────────────────────────────────────
 *
 * `category` é VARCHAR e não FK: as categorias são declaradas em
 * theme/site.json, não em tabela — mesma razão de `form_settings.form_id`
 * não ter FK. String vazia significa "necessário, sempre carrega", que é o
 * padrão e o único valor possível num site sem categorias declaradas.
 *
 * `sort_order` porque ordem importa de verdade dentro de uma posição: o GTM
 * precisa vir antes de qualquer coisa que empurre pro dataLayer dele.
 */
return function (Database $db): void {
    $db->query("
        CREATE TABLE IF NOT EXISTS tracking_scripts (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            label       VARCHAR(191) NOT NULL,
            code        TEXT NOT NULL,
            location    VARCHAR(32) NOT NULL DEFAULT 'head',
            category    VARCHAR(191) NOT NULL DEFAULT '',
            enabled     TINYINT(1) NOT NULL DEFAULT 1,
            sort_order  INT NOT NULL DEFAULT 0,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_tracking_scripts_output (location, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
};
