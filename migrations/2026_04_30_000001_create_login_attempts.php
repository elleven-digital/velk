<?php

declare(strict_types=1);

use Velk\Database;

/**
 * Login attempt log used to throttle brute-force tries.
 *
 * Schema rationale:
 *   - `email` and `ip` are tracked separately so we can lock by either
 *     dimension. Brute-force from many IPs against one account → email
 *     threshold catches it. Spray attack from one IP across many accounts
 *     → IP threshold catches it.
 *   - `succeeded` is recorded so successful logins clear the picture
 *     when ratelimit queries filter on `succeeded = FALSE`.
 *   - VARCHAR(45) for IP is long enough for IPv6 with optional zone.
 *   - Indexes on (email, attempted_at) and (ip, attempted_at) make the
 *     "count failures in last 15 min" query O(log n) regardless of how
 *     big the table grows. No FK to users — we want to track failures
 *     against non-existent emails too (those are usually attacks).
 *
 * Cleanup is NOT automatic. Run `bin/velk cleanup:login-attempts`
 * manually or schedule via cron when convenient. The rate-limit logic
 * filters by time window so the table can grow indefinitely without
 * impacting correctness — only disk space.
 */
return function (Database $db): void {
    $db->query("
        CREATE TABLE IF NOT EXISTS login_attempts (
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
