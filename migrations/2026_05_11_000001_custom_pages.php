<?php

declare(strict_types=1);

use Velk\Database;

/**
 * Custom pages — pages created from the admin panel using one of the
 * `page_templates` declared in site.json, vs the JSON-defined pages
 * which are fixed by code.
 *
 * Two new columns on the existing `pages` table:
 *   - `template` (NULL for JSON pages, the page_template key for custom)
 *   - `slug` (NULL for JSON pages — their URL is JSON-defined; required
 *     for custom pages, drives `/{slug}` URL resolution)
 *
 * For custom pages, `page_key = slug` (they're kept in sync on rename).
 * This means the existing `findByKey()` lookup works for both kinds,
 * and the unique index on `page_key` enforces slug uniqueness too —
 * we only need a separate UNIQUE on `slug` so a future schema could
 * decouple key from slug without losing the constraint.
 *
 * The new columns are nullable to keep the migration backward-
 * compatible: existing JSON page rows keep working with template=NULL.
 */
return function (Database $db): void {
    $db->query("ALTER TABLE pages ADD COLUMN template VARCHAR(100) NULL AFTER fields");
    $db->query("ALTER TABLE pages ADD COLUMN slug VARCHAR(200) NULL AFTER template");
    // Unique on slug, but NULLs allowed (MySQL treats NULLs as distinct
    // for unique indexes, so JSON pages with slug=NULL never collide).
    $db->query("CREATE UNIQUE INDEX uniq_pages_slug ON pages (slug)");
};
