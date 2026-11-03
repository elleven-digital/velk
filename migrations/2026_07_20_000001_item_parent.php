<?php

declare(strict_types=1);

use Velk\Database;

/**
 * Hierarquia em itens — item types declarados "hierarchical" no site.json
 * podem ter itens com um pai do mesmo tipo. A URL passa a combinar a cadeia
 * de slugs (ex.: /docs/guia/instalacao).
 *
 * `parent_id` referencia items(id); NULL = item-raiz (comportamento atual).
 * FK self com ON DELETE SET NULL espelhando `terms` (também hierárquico):
 * deletar um pai promove os filhos a raiz, em vez de apagá-los. Índice para
 * varrer descendentes (anti-ciclo). Nullable → retrocompatível.
 */
return function (Database $db): void {
    $db->query("ALTER TABLE items ADD COLUMN parent_id INT UNSIGNED NULL AFTER slug");
    $db->query("CREATE INDEX idx_items_parent ON items (parent_id)");
    $db->query("ALTER TABLE items ADD CONSTRAINT fk_items_parent FOREIGN KEY (parent_id) REFERENCES items(id) ON DELETE SET NULL");
};
