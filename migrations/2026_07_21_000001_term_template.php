<?php

declare(strict_types=1);

use Velk\Database;

/**
 * Termos passam a poder ter um template escolhido (quando a taxonomia define
 * uma lista `templates`). Espelha o tipo de `items.template` (VARCHAR(255) NULL).
 */
return function (Database $db): void {
    $db->query("ALTER TABLE terms ADD COLUMN template VARCHAR(255) NULL AFTER slug");
};
