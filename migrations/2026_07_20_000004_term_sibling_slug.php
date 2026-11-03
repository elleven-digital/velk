<?php

declare(strict_types=1);

use Velk\Database;

/**
 * Slugs de termo passam a ser únicos entre IRMÃOS (mesma taxonomia + mesmo
 * pai), não mais por taxonomia inteira — termos com pais diferentes podem
 * repetir o slug (ex.: /docs/guia-a/intro e /docs/guia-b/intro). Removemos o
 * UNIQUE(taxonomy, slug) e deixamos um índice comum (taxonomy, slug) para
 * casar candidatos na resolução de URL aninhada.
 *
 * A identidade do termo é o `id` numérico (igual a itens), então não há
 * desacoplamento de chave — a unicidade entre irmãos é garantida na
 * aplicação (TermController::ensureUniqueSlug com escopo de pai). A coluna
 * `parent_id` e o índice `idx_parent` já existiam.
 */
return function (Database $db): void {
    $db->query("ALTER TABLE terms DROP INDEX uniq_taxonomy_slug");
    $db->query("CREATE INDEX idx_terms_taxonomy_slug ON terms (taxonomy, slug)");
};
