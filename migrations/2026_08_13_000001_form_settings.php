<?php

declare(strict_types=1);

use Velk\Database;

/**
 * Per-form conversion settings.
 *
 * Holds what the traffic manager configures on /admin/forms/{id}: which
 * fields map onto Google's enhanced-conversion identifiers, and an optional
 * raw snippet to run when the conversion fires.
 *
 * A table rather than a column somewhere: there IS no `forms` table — forms
 * are declared in theme/site.json, and the only form tables are
 * form_submissions and form_recipients. And not the options store either:
 * `option()` is i18n-aware (falls to Option::getPathIn on a non-default
 * language), which means nothing here and would surprise; plus that table
 * exists for groups DECLARED in site.json, so a synthetic key would hold
 * data no admin screen knows how to list.
 *
 * 1:1 with the form, so form_id is the primary key — a row appears the
 * first time someone saves settings for that form, and never otherwise.
 * Deliberately no FK: form ids live in site.json, not in a table.
 */
return function (Database $db): void {
    $db->query("
        CREATE TABLE IF NOT EXISTS form_settings (
            form_id            VARCHAR(191) NOT NULL PRIMARY KEY,
            user_data_fields   JSON NULL,
            conversion_snippet TEXT NULL,
            updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
};
