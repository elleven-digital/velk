<?php

declare(strict_types=1);

use Velk\Database;

/**
 * i18n Phase 2: config translations for item types and taxonomies.
 *
 * Item types and taxonomies are declared in site.json (e.g.,
 * `item_types.post.slug = "blog"`). The DEFAULT-language slug stays
 * in site.json. Translated slugs (e.g., "news" in en, "noticias" in
 * es) live here, in a single small table keyed by (config_type,
 * config_key).
 *
 * Why a separate table instead of extending site.json itself: per
 * user choice, ALL translation work happens in the admin panel —
 * never by editing JSON. This isolates translator/dev concerns
 * cleanly.
 *
 * The `translations` JSON is shaped:
 *   {
 *     "slug": {"en": "news", "es": "noticias"}
 *   }
 *
 * Only `slug` is translatable for items/taxonomies (the label stays
 * site.json-only since it's admin-facing). Future translatable
 * keys could be added to the same JSON without schema changes.
 */
return function (Database $db): void {
    $db->query("
        CREATE TABLE IF NOT EXISTS config_translations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            config_type VARCHAR(50) NOT NULL,
            config_key  VARCHAR(100) NOT NULL,
            translations JSON NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_config (config_type, config_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
};
