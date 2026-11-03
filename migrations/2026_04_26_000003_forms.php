<?php

declare(strict_types=1);

use Velk\Database;

return function (Database $db): void {
    $db->query("
        CREATE TABLE IF NOT EXISTS form_submissions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            form_id VARCHAR(100) NOT NULL,
            data JSON NOT NULL,
            ip VARCHAR(64) NULL,
            user_agent VARCHAR(500) NULL,
            referer VARCHAR(500) NULL,
            email_status VARCHAR(20) NULL,
            email_error TEXT NULL,
            sent_to TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_form_id (form_id),
            KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->query("
        CREATE TABLE IF NOT EXISTS form_recipients (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            form_id VARCHAR(100) NOT NULL,
            email VARCHAR(255) NOT NULL,
            name VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_form_email (form_id, email),
            KEY idx_form_id (form_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
};
