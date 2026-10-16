<?php

declare(strict_types=1);

namespace LockSend\Support;

use PDO;
use PDOStatement;

/**
 * Thin PDO wrapper. SQLite (WAL) is the default; MariaDB works through the
 * same portable SQL (binary values are stored as base64url TEXT).
 */
final class Db
{
    public readonly PDO $pdo;
    public readonly string $driver;
    private bool $inTx = false;

    public function __construct(Config $config)
    {
        $this->pdo = new PDO($config->dbDsn, $config->dbUser, $config->dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($this->driver === 'sqlite') {
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA synchronous = NORMAL');
            $this->pdo->exec('PRAGMA busy_timeout = 5000');
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA secure_delete = ON');
        }
    }

    /** @param list<scalar|null> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st;
    }

    /**
     * @param list<scalar|null> $params
     * @return array<string,mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param list<scalar|null> $params
     * @return list<array<string,mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** @param list<scalar|null> $params */
    public function exec(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /** Row-locking suffix for SELECTs inside tx(); SQLite serialises writers with BEGIN IMMEDIATE. */
    public function forUpdate(): string
    {
        return $this->driver === 'sqlite' ? '' : ' FOR UPDATE';
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function tx(callable $fn): mixed
    {
        if ($this->inTx) {
            return $fn();
        }
        if ($this->driver === 'sqlite') {
            $this->pdo->exec('BEGIN IMMEDIATE');
        } else {
            $this->pdo->beginTransaction();
        }
        $this->inTx = true;
        try {
            $result = $fn();
            $this->inTx = false;
            $this->driver === 'sqlite' ? $this->pdo->exec('COMMIT') : $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->inTx = false;
            try {
                $this->driver === 'sqlite' ? $this->pdo->exec('ROLLBACK') : $this->pdo->rollBack();
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    public function setSetting(string $key, string $value): void
    {
        $sql = $this->driver === 'sqlite'
            ? 'INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v'
            : 'INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)';
        $this->exec($sql, [$key, $value]);
    }

    public function getSetting(string $key, ?string $default = null): ?string
    {
        $row = $this->one('SELECT v FROM settings WHERE k = ?', [$key]);
        return $row === null ? $default : (string) $row['v'];
    }
}
