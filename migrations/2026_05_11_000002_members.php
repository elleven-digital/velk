<?php

declare(strict_types=1);

use Velk\Database;

/**
 * Site members — a separate user system for the public-facing site.
 *
 * Intentionally distinct from `users` (which is for admin panel access):
 *   - Separate tables, separate auth class, separate session key
 *   - Separate password_resets and login_attempts so member flows can't
 *     interfere with admin security state (e.g., an attacker spraying
 *     member emails can't lock out the admin's IP)
 *   - No predefined roles — each site's `site.json` declares its own
 *     `types` (member.type column), and theme code decides what each
 *     type can do. Velk just stores the type marker.
 *
 * Tables created:
 *   - members                  → the member records (email, password, type, fields JSON)
 *   - member_password_resets   → one-shot tokens for self-service password reset
 *   - member_login_attempts    → brute-force throttling (mirrors login_attempts)
 *
 * The `email_verified_at` column ships now so the schema is ready for
 * a future "confirm your email" flow without another migration; the
 * feature itself is opt-in at the application layer (not in v1.3.x).
 */
return function (Database $db): void {
    $db->query("
        CREATE TABLE IF NOT EXISTS members (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            name VARCHAR(255) NULL,
            type VARCHAR(100) NULL,
            fields JSON NOT NULL,
            email_verified_at DATETIME NULL,
            last_login_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_member_email (email),
            KEY idx_member_type (type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->query("
        CREATE TABLE IF NOT EXISTS member_password_resets (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            member_id INT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            ip VARCHAR(45) NULL,
            user_agent VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_token_hash (token_hash),
            KEY idx_member_active (member_id, used_at, expires_at),
            CONSTRAINT fk_mpr_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->query("
        CREATE TABLE IF NOT EXISTS member_login_attempts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            ip VARCHAR(45) NOT NULL,
            succeeded TINYINT(1) NOT NULL DEFAULT 0,
            attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_email_time (email, attempted_at),
            KEY idx_ip_time (ip, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
};
