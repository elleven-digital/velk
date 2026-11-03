<?php

declare(strict_types=1);

use Velk\Database;

/**
 * Consolida UTM e click IDs numa coluna `params` JSON.
 *
 * Antes: 5 colunas VARCHAR pras UTM (duas indexadas) + `click_ids` JSON.
 * Depois: uma coluna `params`, governada pela whitelist de `Velk\Params`.
 * Parâmetro novo — uma plataforma de anúncio nova, um `utm_id` — passa a ser
 * uma entrada na whitelist e nada mais, sem migration.
 *
 * ─── Por que as colunas podiam existir, e por que deixaram de precisar ───
 *
 * As UTM eram colunas porque o painel filtrava e agrupava por elas
 * (`distinctUtmValues()` rodava 5 `SELECT DISTINCT` a cada abertura da tela
 * do formulário). Esse filtro saiu junto: UTM é insumo de ferramenta de
 * análise — quem monta link com `utm_campaign` olha no GA4 ou no Ads, não
 * numa lista de 20 preenchimentos. No painel os parâmetros continuam
 * visíveis no detalhe da submissão; o que sai é só o filtro.
 *
 * Sem consulta, não há motivo pra coluna indexada, e a incoerência de ter
 * metade do mesmo conceito em coluna e metade em JSON some.
 *
 * ─── DESTRUTIVA, e sem backfill, deliberadamente ─────────────────────────
 *
 * Os dados de UTM/click ID existentes são DESCARTADOS. Isso foi verificado
 * site a site antes: nenhum projeto em produção dependia desse histórico.
 * Numa base com dado que importasse, o certo seria o padrão expand/contract
 * — criar `params`, preencher, rodar uma release inteira escrevendo nos
 * dois, e só então dropar — porque migration no Velk não tem `down` e DDL
 * no MySQL não é transacional (ver core/Migrator.php).
 *
 * Consequência a registrar: esta é a primeira migration não-aditiva do
 * Velk. Até aqui, `upgrade --version=<anterior>` era seguro porque o schema
 * só ficava À FRENTE do código antigo, e coluna desconhecida é ignorada.
 * Depois desta, voltar pra uma versão < v4.0.0 encontra código procurando
 * colunas que não existem mais. O downgrade cruzando este ponto é porta de
 * mão única.
 */
return function (Database $db): void {
    $hasColumn = static function (string $column) use ($db): bool {
        return (int) $db->fetchColumn(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'form_submissions'
               AND column_name = ?",
            [$column]
        ) > 0;
    };

    if (!$hasColumn('params')) {
        $db->query("ALTER TABLE form_submissions ADD COLUMN params JSON NULL");
    }

    // Nunca consultada por WHERE/GROUP BY — como o click_ids que ela
    // substitui, deliberadamente SEM ÍNDICE.
    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'click_ids'] as $column) {
        if ($hasColumn($column)) {
            $db->query("ALTER TABLE form_submissions DROP COLUMN {$column}");
        }
    }
};
