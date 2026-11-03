<?php

declare(strict_types=1);

use Velk\Database;

/**
 * Internationalization — Phase 1: language registry and string catalog.
 *
 * Two tables:
 *   - languages       Additional languages the site supports. The DEFAULT
 *                     language is NOT here — it lives in site.json under
 *                     i18n.default. This table only holds the extras
 *                     (en, es, etc.) so the admin can add/remove/order
 *                     them without touching code.
 *   - theme_strings   Translation catalog for hardcoded strings used by
 *                     `t()` in templates and engine controllers. One
 *                     row per source string; all language variants live
 *                     in the `translations` JSON column.
 *
 * Phase 1 does NOT include URL prefix routing — that's Phase 2. The
 * `url_prefix` column is included now so Phase 2 can fill it without
 * another migration. For Phase 1, language switching happens via
 * `?lang=en` query parameter (preview mode).
 *
 * Phase 1 does NOT include per-language content columns — that's
 * Phase 3 (item/page/term fields_translations etc.).
 */
return function (Database $db): void {
    $db->query("
        CREATE TABLE IF NOT EXISTS languages (
            code VARCHAR(10) NOT NULL PRIMARY KEY,
            label VARCHAR(100) NOT NULL,
            url_prefix VARCHAR(20) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_url_prefix (url_prefix)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->query("
        CREATE TABLE IF NOT EXISTS theme_strings (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            key_hash CHAR(64) NOT NULL,
            original TEXT NOT NULL,
            context VARCHAR(255) NULL,
            translations JSON NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_key_hash (key_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
};
