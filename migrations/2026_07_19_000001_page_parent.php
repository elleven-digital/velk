<?php

declare(strict_types=1);

use Velk\Database;

/**
 * Subpáginas — hierarquia de páginas. Uma página pode ter um pai; a URL
 * de rota passa a ser a combinação dos slugs da cadeia (raiz → folha).
 *
 * `parent_key` referencia o `page_key` de outra página (JSON ou custom).
 * NULL = página-raiz (comportamento atual). Sem FK formal — a validação
 * (pai existe, sem ciclo) vive na aplicação, como o resto do engine.
 * Índice para varrer descendentes (anti-ciclo + montagem de menu).
 *
 * Nullable → retrocompatível: linhas existentes ficam com parent_key=NULL.
 */
return function (Database $db): void {
    $db->query("ALTER TABLE pages ADD COLUMN parent_key VARCHAR(200) NULL AFTER slug");
    $db->query("CREATE INDEX idx_pages_parent ON pages (parent_key)");
};
