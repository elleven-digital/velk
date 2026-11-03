<?php

declare(strict_types=1);

namespace Velk;

final class Migrator
{
    public function __construct(private Database $db, private string $migrationsPath) {}

    public function ensureTable(): void
    {
        $this->db->query(
            'CREATE TABLE IF NOT EXISTS migrations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL UNIQUE,
                executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function pending(): array
    {
        $this->ensureTable();
        $files = glob($this->migrationsPath . '/*.php') ?: [];
        sort($files);

        $executed = array_column(
            $this->db->fetchAll('SELECT name FROM migrations'),
            'name'
        );

        return array_values(array_filter($files, function ($file) use ($executed) {
            return !in_array(basename($file), $executed, true);
        }));
    }

    public function migrate(): array
    {
        $applied = [];
        foreach ($this->pending() as $file) {
            $name = basename($file);
            $migration = require $file;
            if (!is_callable($migration)) {
                throw new \RuntimeException("Migration {$name} must return a callable.");
            }
            // DDL statements auto-commit in MySQL, so transactions don't help.
            // We rely on idempotent migrations (CREATE TABLE IF NOT EXISTS) and
            // record the migration only after the file runs cleanly.
            $migration($this->db);
            $this->db->insert('migrations', ['name' => $name]);
            $applied[] = $name;
        }
        return $applied;
    }
}
