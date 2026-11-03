<?php

declare(strict_types=1);

use Velk\Database;

/**
 * `sort_order` — a posição manual de um item dentro do seu tipo.
 *
 * Só é lida quando o tipo declara `manual_order: true` no site.json. Sem a
 * flag a coluna fica em 0 e nenhuma consulta a menciona, então o custo para
 * quem não usa é uma coluna INT parada.
 *
 * DEFAULT 0 para que a coluna nasça válida em base existente sem passo
 * extra. O valor real é materializado na primeira vez que a listagem do
 * painel abre com a flag ligada — ver ManualOrder::ensureSeeded().
 *
 * O índice cobre a única leitura nova: listar um tipo em ordem manual.
 */
return function (Database $db): void {
    $columns = $db->fetchAll("SHOW COLUMNS FROM items LIKE 'sort_order'");
    if ($columns === []) {
        $db->query("
            ALTER TABLE items
            ADD COLUMN sort_order INT NOT NULL DEFAULT 0
        ");
    }

    $indexes = $db->fetchAll("SHOW INDEX FROM items WHERE Key_name = 'idx_items_manual_order'");
    if ($indexes === []) {
        $db->query("
            CREATE INDEX idx_items_manual_order
            ON items (type, sort_order)
        ");
    }
};
