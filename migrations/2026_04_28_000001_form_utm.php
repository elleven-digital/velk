<?php

declare(strict_types=1);

use Velk\Database;

/**
 * Add UTM tracking columns to form_submissions.
 *
 * UTMs are captured from the request query string on every page visit,
 * stored in the session, and persisted with the next form submission so
 * teams can trace which campaign/source generated each lead.
 *
 * 255 chars matches Google Analytics' max length per UTM field.
 */
return function (Database $db): void {
    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $col) {
        $exists = $db->fetchColumn(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'form_submissions' AND column_name = ?",
            [$col]
        );
        if ((int) $exists === 0) {
            $db->query("ALTER TABLE form_submissions ADD COLUMN {$col} VARCHAR(255) NULL");
        }
    }

    // Index utm_source/utm_campaign — most common attribution queries
    // ("how many leads came from <source>?"). Skip if already present.
    $hasIdxSource = (int) $db->fetchColumn(
        "SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = 'form_submissions' AND index_name = 'idx_utm_source'"
    );
    if ($hasIdxSource === 0) {
        $db->query("CREATE INDEX idx_utm_source ON form_submissions (utm_source)");
    }
    $hasIdxCampaign = (int) $db->fetchColumn(
        "SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = 'form_submissions' AND index_name = 'idx_utm_campaign'"
    );
    if ($hasIdxCampaign === 0) {
        $db->query("CREATE INDEX idx_utm_campaign ON form_submissions (utm_campaign)");
    }
};
