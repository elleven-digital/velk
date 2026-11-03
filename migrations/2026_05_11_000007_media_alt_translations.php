<?php

declare(strict_types=1);

use Velk\Database;

/**
 * Per-language alt text for media library entries.
 *
 * Same pattern as the other content translations: default-language
 * value stays in the `alt` column (existing reads keep working
 * verbatim), per-language overrides live in this JSON column shaped
 * as `{"en": "...", "es": "..."}`.
 *
 * Use case: images with embedded text (infographics, screenshots,
 * book/poster covers) where the alt should describe what's visible
 * in the visitor's language. For generic photos where alt is just
 * "Aerial shot of the office building", a single alt is fine and
 * this column stays NULL.
 */
return function (Database $db): void {
    $db->query("ALTER TABLE media ADD COLUMN alt_translations JSON NULL AFTER alt");
};
