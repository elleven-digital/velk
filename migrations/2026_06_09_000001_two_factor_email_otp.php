<?php

declare(strict_types=1);

use Velk\Database;

/**
 * Two-factor authentication via email code, for admin users AND members.
 *
 * Two flag columns + one shared codes table:
 *
 *   - users.two_factor_enabled / members.two_factor_enabled — opt-in
 *     flags. Default 0 so existing accounts keep logging in exactly as
 *     before; nothing changes until someone enables it.
 *
 *   - email_otp_codes — one row per issued code. The code itself is
 *     NEVER stored, only its bcrypt hash (a leaked DB must not allow
 *     bypassing the second factor). `subject_type` discriminates
 *     'user' (admin panel) from 'member' (public site) so one table
 *     serves both auth chains without duplicating the machinery.
 *
 * Columns worth explaining:
 *   - attempts: wrong guesses against THIS code. The service kills the
 *     code after 5, so a code can't be brute-forced within its TTL
 *     (6 digits = 900k combinations vs 5 guesses).
 *   - used_at: set on successful verification OR when invalidated
 *     (superseded by a newer code / too many attempts). NULL = pending.
 *   - expires_at: 10-minute TTL, enforced in queries (no cron needed).
 */
return function (Database $db): void {
    $db->query("ALTER TABLE users ADD COLUMN two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER role");
    $db->query("ALTER TABLE members ADD COLUMN two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER fields");

    $db->query("
        CREATE TABLE IF NOT EXISTS email_otp_codes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            subject_type VARCHAR(10) NOT NULL,
            subject_id INT UNSIGNED NOT NULL,
            code_hash VARCHAR(255) NOT NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            ip VARCHAR(45) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_subject_created (subject_type, subject_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
};
