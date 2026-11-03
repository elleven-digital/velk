<?php

declare(strict_types=1);

use Velk\Database;

/**
 * `sort_order` — a posição manual de um termo dentro da sua taxonomia.
 *
 * Espelha a coluna de `items`. Só é lida quando a taxonomia declara
 * `manual_order: true` no site.json; sem a flag, `Term::all()` continua
 * ordenando por `name ASC` como sempre.
 */
return function (Database $db): void {
    $columns = $db->fetchAll("SHOW COLUMNS FROM terms LIKE 'sort_order'");
    if ($columns === []) {
        $db->query("
            ALTER TABLE terms
            ADD COLUMN sort_order INT NOT NULL DEFAULT 0
        ");
    }

    $indexes = $db->fetchAll("SHOW INDEX FROM terms WHERE Key_name = 'idx_terms_manual_order'");
    if ($indexes === []) {
        $db->query("
            CREATE INDEX idx_terms_manual_order
            ON terms (taxonomy, sort_order)
        ");
    }
};
