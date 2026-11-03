<?php

declare(strict_types=1);

namespace Velk;

use PDO;
use PDOStatement;

final class Database
{
    private PDO $pdo;

    public function __construct(array $config)
    {
        $dsn = sprintf(
            '%s:host=%s;port=%d;dbname=%s;charset=%s',
            $config['driver'] ?? 'mysql',
            $config['host'] ?? 'localhost',
            $config['port'] ?? 3306,
            $config['database'],
            $config['charset'] ?? 'utf8mb4',
        );

        $this->pdo = new PDO(
            $dsn,
            $config['username'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchColumn(string $sql, array $params = []): mixed
    {
        $value = $this->query($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(fn($c) => ':' . $c, $columns);

        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $table,
            implode('`, `', $columns),
            implode(', ', $placeholders)
        );

        $this->query($sql, $data);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, array $where): int
    {
        $set = [];
        $params = [];
        foreach ($data as $col => $val) {
            $set[] = "`{$col}` = :set_{$col}";
            $params["set_{$col}"] = $val;
        }
        $whereSql = [];
        foreach ($where as $col => $val) {
            $whereSql[] = "`{$col}` = :where_{$col}";
            $params["where_{$col}"] = $val;
        }

        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE %s',
            $table,
            implode(', ', $set),
            implode(' AND ', $whereSql)
        );

        return $this->query($sql, $params)->rowCount();
    }

    public function delete(string $table, array $where): int
    {
        $whereSql = [];
        $params = [];
        foreach ($where as $col => $val) {
            $whereSql[] = "`{$col}` = :{$col}";
            $params[$col] = $val;
        }

        $sql = sprintf(
            'DELETE FROM `%s` WHERE %s',
            $table,
            implode(' AND ', $whereSql)
        );

        return $this->query($sql, $params)->rowCount();
    }

    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback($this);
            if ($this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function tableExists(string $table): bool
    {
        $row = $this->fetch(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );
        return $row !== null;
    }
}
