<?php
declare(strict_types=1);

use Velk\Database;

return function (Database $db): void {
    $db->query("ALTER TABLE users ADD COLUMN locale VARCHAR(10) NULL AFTER role");
};
