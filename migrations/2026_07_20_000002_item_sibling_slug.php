<?php

declare(strict_types=1);

use Velk\Database;

/**
 * Slugs de item passam a ser únicos entre IRMÃOS (mesmo pai), não mais por
 * tipo — dois itens do mesmo tipo sob pais diferentes podem ter o mesmo
 * slug (ex.: /docs/guia-a/intro e /docs/guia-b/intro). Removemos o
 * UNIQUE(type, slug) e deixamos um índice comum (type, slug) para casar
 * segmentos na resolução. A unicidade entre irmãos é validada na aplicação
 * (o UNIQUE do MySQL não cobre irmãos-raiz, pois trata NULLs como distintos).
 */
return function (Database $db): void {
    $db->query("ALTER TABLE items DROP INDEX uniq_type_slug");
    $db->query("CREATE INDEX idx_items_type_slug ON items (type, slug)");
};
