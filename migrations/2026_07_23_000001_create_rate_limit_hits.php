<?php

declare(strict_types=1);

use Velk\Database;

/**
 * Generic rate-limit hit log backing RateLimiterService.
 *
 * Distinct from login_attempts/member_login_attempts on purpose: those
 * track pass/fail credential guessing (dual email+IP threshold, driven
 * by a `succeeded` flag that determines when old attempts stop
 * counting). The endpoints this table serves — password-reset
 * requests, member registration — have no pass/fail concept, just
 * "did this happen"; reusing the login tables would mean faking a
 * `succeeded` value or reshaping their attempt-count semantics for
 * unrelated callers. A second tiny generic table is simpler than
 * either.
 *
 * `bucket` namespaces the caller/action (e.g. 'pwreset_admin_ip',
 * 'member_register_ip') so unrelated limiters never share counts.
 * `key_value` is normally an IP, sometimes an email — VARCHAR(255)
 * covers either without a second column.
 *
 * Cleanup is NOT automatic — same convention as login_attempts. Run
 * `bin/velk cleanup:rate-limit-hits` manually or via cron; the
 * throttle queries always filter by time window so the table can grow
 * indefinitely without affecting correctness, only disk space.
 */
return function (Database $db): void {
    $db->query("
        CREATE TABLE IF NOT EXISTS rate_limit_hits (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            bucket VARCHAR(64) NOT NULL,
            key_value VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_bucket_key_time (bucket, key_value, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
};
