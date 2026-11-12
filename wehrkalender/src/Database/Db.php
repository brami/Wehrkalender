<?php

declare(strict_types=1);

namespace Wehrkalender\Database;

use Wehrkalender\Config;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * Dünne Schicht über PDO. Das SQL der Anwendung ist so gehalten, dass es auf
 * MariaDB und SQLite läuft (SQLite nur für lokale Tests): keine NOW()-Aufrufe
 * (Zeiten kommen aus PHP), kein ON DUPLICATE KEY, keine datenbankspezifischen
 * Funktionen. Unterschiede (Sperren, DDL) sind hier gekapselt.
 */
final class Db
{
    private int $transactionLevel = 0;
    /** @var array<string,resource> */
    private array $fileLocks = [];

    public function __construct(private PDO $pdo, private string $lockDir = '')
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        if ($this->driver() === 'sqlite') {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA busy_timeout = 5000');
        }
    }

    public static function fromConfig(Config $config): self
    {
        $driver = $config->get('DB_DRIVER', 'mysql');
        if ($driver === 'sqlite') {
            $path = $config->get('DB_PATH', 'storage/wehrkalender.sqlite');
            if ($path !== ':memory:' && !str_starts_with($path, '/')) {
                $path = $config->baseDir() . '/' . $path;
            }
            if ($path !== ':memory:' && !is_dir(dirname($path))) {
                mkdir(dirname($path), 0770, true);
            }
            $pdo = new PDO('sqlite:' . $path);
            if ($path !== ':memory:') {
                $pdo->exec('PRAGMA journal_mode = WAL');
            }
        } elseif ($driver === 'mysql') {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $config->get('DB_HOST', 'db'),
                $config->int('DB_PORT', 3306),
                $config->get('DB_NAME', 'wehrkalender')
            );
            $pdo = new PDO($dsn, $config->get('DB_USER', 'wehrkalender'), $config->get('DB_PASSWORD'), [
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00', NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ]);
        } else {
            throw new RuntimeException("Unbekannter DB_DRIVER: $driver");
        }
        return new self($pdo, $config->storagePath('locks'));
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /** @param array<int|string,mixed> $params */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($name, is_bool($value) ? (int) $value : $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    /** @return list<array<string,mixed>> */
    public function all(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function value(string $sql, array $params = []): mixed
    {
        $v = $this->query($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** @return list<mixed> */
    public function column(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    /** @param array<string,mixed> $data */
    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $cols),
            implode(', ', array_map(fn ($c) => ':' . $c, $cols))
        );
        $this->query($sql, $data);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $where  Spalte => Wert (UND-verknüpft)
     */
    public function update(string $table, array $data, array $where): int
    {
        $set = [];
        $params = [];
        foreach ($data as $col => $value) {
            $set[] = "$col = :s_$col";
            $params["s_$col"] = $value;
        }
        $cond = [];
        foreach ($where as $col => $value) {
            $cond[] = "$col = :w_$col";
            $params["w_$col"] = $value;
        }
        $sql = sprintf('UPDATE %s SET %s WHERE %s', $table, implode(', ', $set), implode(' AND ', $cond));
        return $this->execute($sql, $params);
    }

    /**
     * Führt $fn in einer Transaktion aus. Verschachtelte Aufrufe laufen in der
     * äußeren Transaktion mit.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        if ($this->transactionLevel > 0) {
            $this->transactionLevel++;
            try {
                return $fn();
            } finally {
                $this->transactionLevel--;
            }
        }
        $this->pdo->beginTransaction();
        $this->transactionLevel = 1;
        try {
            $result = $fn();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } finally {
            $this->transactionLevel = 0;
        }
    }

    /**
     * Benannte Sperre (MariaDB: GET_LOCK, SQLite: Dateisperre).
     * Gibt false zurück, wenn die Sperre nicht innerhalb von $timeout Sekunden frei wird.
     */
    public function lock(string $name, int $timeout = 0): bool
    {
        if ($this->driver() === 'mysql') {
            return (int) $this->value('SELECT GET_LOCK(?, ?)', [$name, $timeout]) === 1;
        }
        $dir = $this->lockDir !== '' ? $this->lockDir : sys_get_temp_dir();
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        $fh = fopen($dir . '/' . preg_replace('/[^a-z0-9_-]/i', '_', $name) . '.lock', 'c');
        if ($fh === false) {
            return false;
        }
        $deadline = microtime(true) + $timeout;
        do {
            if (flock($fh, LOCK_EX | LOCK_NB)) {
                $this->fileLocks[$name] = $fh;
                return true;
            }
            usleep(200_000);
        } while (microtime(true) < $deadline);
        fclose($fh);
        return false;
    }

    public function unlock(string $name): void
    {
        if ($this->driver() === 'mysql') {
            $this->value('SELECT RELEASE_LOCK(?)', [$name]);
            return;
        }
        if (isset($this->fileLocks[$name])) {
            flock($this->fileLocks[$name], LOCK_UN);
            fclose($this->fileLocks[$name]);
            unset($this->fileLocks[$name]);
        }
    }

    /** Platzhalter "IN (?, ?, ?)" für eine Liste */
    public static function in(array $values): string
    {
        return implode(', ', array_fill(0, max(1, count($values)), '?'));
    }
}
