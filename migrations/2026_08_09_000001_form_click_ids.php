<?php

declare(strict_types=1);

use Velk\Database;

/**
 * Add click_ids JSON column to form_submissions.
 *
 * Click IDs (gclid & cia — see core/ClickIds.php) are captured from the
 * request query string, held in the session, and persisted with the next
 * form submission so a CRM/ad-platform integration can run offline
 * conversion import later.
 *
 * Unlike the UTM columns above (dedicated + indexed, because teams filter
 * and group by them in the admin panel), click IDs go into a single JSON
 * blob: each value is unique per click, so grouping by it means nothing —
 * a dedicated column per ad platform would buy nothing, and JSON means a
 * new platform is one more entry in ClickIds::WHITELIST, zero migrations.
 * Never queried by WHERE, so deliberately NO INDEX.
 */
return function (Database $db): void {
    $exists = $db->fetchColumn(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'form_submissions' AND column_name = 'click_ids'"
    );
    if ((int) $exists === 0) {
        $db->query("ALTER TABLE form_submissions ADD COLUMN click_ids JSON NULL");
    }
};
