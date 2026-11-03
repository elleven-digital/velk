<?php

declare(strict_types=1);

use Velk\Database;

/**
 * i18n Phase 3 — per-language content storage for items, pages,
 * terms, and options.
 *
 * Pattern: for every translatable column, add a parallel
 * `*_translations` JSON column. The original column always holds
 * the DEFAULT-language value (so existing code that does `ORDER BY
 * title` or `WHERE slug = ?` keeps working — performance is
 * identical for default-language operations). Non-default languages
 * live in the JSON column as `{"en": "...", "es": "..."}`.
 *
 * For `fields` (which is already a JSON column containing custom
 * field values), the parallel `fields_translations` column has the
 * shape `{<lang>: {<field>: <value>}}` — one level deeper.
 *
 * Backward compat: every new column is nullable. Sites without i18n
 * have NULL across the board; code paths that read these columns
 * fall back to the original columns silently.
 *
 * Note: `pages.slug_translations` was already created in migration
 * 2026_05_11_000005 (Phase 2 routing). This migration only adds the
 * remaining page columns and the equivalents on items/terms/options.
 */
return function (Database $db): void {
    // Items: posts and similar content
    $db->query("ALTER TABLE items ADD COLUMN title_translations  JSON NULL AFTER title");
    $db->query("ALTER TABLE items ADD COLUMN slug_translations   JSON NULL AFTER slug");
    $db->query("ALTER TABLE items ADD COLUMN fields_translations JSON NULL AFTER fields");

    // Pages: slug_translations already exists; add the rest.
    $db->query("ALTER TABLE pages ADD COLUMN title_translations  JSON NULL AFTER title");
    $db->query("ALTER TABLE pages ADD COLUMN fields_translations JSON NULL AFTER fields");

    // Terms: taxonomy term has `name` instead of `title`.
    $db->query("ALTER TABLE terms ADD COLUMN name_translations   JSON NULL AFTER name");
    $db->query("ALTER TABLE terms ADD COLUMN slug_translations   JSON NULL AFTER slug");
    $db->query("ALTER TABLE terms ADD COLUMN fields_translations JSON NULL AFTER fields");

    // Options are stored in the `settings` table — one row per option
    // group keyed by `options.<group>`. value_translations mirrors the
    // structure by language: `{"en": {...field values...}, "es": {...}}`.
    // Non-option settings entries get NULL here, no impact.
    $db->query("ALTER TABLE settings ADD COLUMN value_translations JSON NULL AFTER value");
};
