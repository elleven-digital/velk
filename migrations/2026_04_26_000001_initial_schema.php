<?php

declare(strict_types=1);

use Velk\Database;

return function (Database $db): void {
    $db->query("
        CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            name VARCHAR(255) NOT NULL,
            role VARCHAR(50) NOT NULL DEFAULT 'admin',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->query("
        CREATE TABLE IF NOT EXISTS pages (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            page_key VARCHAR(100) NOT NULL,
            title VARCHAR(255) NOT NULL,
            fields JSON NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_page_key (page_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->query("
        CREATE TABLE IF NOT EXISTS items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            type VARCHAR(100) NOT NULL,
            slug VARCHAR(255) NOT NULL,
            title VARCHAR(255) NOT NULL,
            template VARCHAR(255) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            fields JSON NOT NULL,
            published_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_type_slug (type, slug),
            KEY idx_type_status (type, status),
            KEY idx_published (published_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->query("
        CREATE TABLE IF NOT EXISTS terms (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            taxonomy VARCHAR(100) NOT NULL,
            slug VARCHAR(255) NOT NULL,
            name VARCHAR(255) NOT NULL,
            parent_id INT UNSIGNED NULL,
            fields JSON NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_taxonomy_slug (taxonomy, slug),
            KEY idx_taxonomy (taxonomy),
            KEY idx_parent (parent_id),
            CONSTRAINT fk_terms_parent FOREIGN KEY (parent_id) REFERENCES terms(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->query("
        CREATE TABLE IF NOT EXISTS item_term (
            item_id INT UNSIGNED NOT NULL,
            term_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (item_id, term_id),
            KEY idx_term_id (term_id),
            CONSTRAINT fk_it_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
            CONSTRAINT fk_it_term FOREIGN KEY (term_id) REFERENCES terms(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->query("
        CREATE TABLE IF NOT EXISTS media (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(500) NOT NULL,
            original_name VARCHAR(500) NOT NULL,
            mime VARCHAR(100) NOT NULL,
            size INT UNSIGNED NOT NULL,
            width INT UNSIGNED NULL,
            height INT UNSIGNED NULL,
            alt VARCHAR(500) NULL,
            meta JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->query("
        CREATE TABLE IF NOT EXISTS settings (
            setting_key VARCHAR(100) NOT NULL,
            value JSON NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
};
