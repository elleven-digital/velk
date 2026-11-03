<?php

declare(strict_types=1);

use Velk\Database;

/**
 * i18n Phase 2: per-language URL slug for pages.
 *
 * Both JSON-defined pages (whose default URL lives in site.json) and
 * custom pages (whose default slug is the `slug` column) gain a
 * `slug_translations` JSON column holding non-default-language slugs:
 *
 *   {"en": "about", "es": "acerca-de"}
 *
 * Used by FrontRouter to resolve incoming /en/about → the page whose
 * `slug_translations.en` is "about". When a language is absent from
 * the map, the page is not reachable in that language — graceful 404.
 *
 * NOTE: title_translations and fields_translations (also on pages)
 * come in Phase 3 along with item/term translations. Phase 2 only
 * needs slug_translations to make URL routing work.
 */
return function (Database $db): void {
    $db->query("ALTER TABLE pages ADD COLUMN slug_translations JSON NULL AFTER slug");
};
