<?php

declare(strict_types=1);

namespace Wehrkalender\Database;

use Wehrkalender\Clock;

/**
 * Führt die Migrationen aus migrations/NNN_name.php aus. Jede Datei liefert
 * eine Liste von SQL-Anweisungen mit Platzhaltern (siehe 001_schema.php).
 */
final class Migrator
{
    public function __construct(private Db $db, private string $dir, private Clock $clock)
    {
    }

    /** @return list<string> ausgeführte Versionen */
    public function migrate(): array
    {
        $this->ensureTable();
        $done = array_flip($this->db->column('SELECT version FROM schema_migrations'));
        $files = glob($this->dir . '/*.php') ?: [];
        sort($files);
        $applied = [];
        foreach ($files as $file) {
            $version = basename($file, '.php');
            if (isset($done[$version])) {
                continue;
            }
            $statements = require $file;
            // DDL ist in MariaDB nicht transaktional; daher je Anweisung ausführen
            foreach ($statements as $sql) {
                $this->db->pdo()->exec($this->translate($sql));
            }
            $this->db->insert('schema_migrations', ['version' => $version, 'applied_at' => $this->clock->nowDb()]);
            $applied[] = $version;
        }
        return $applied;
    }

    /** Alle Tabellen löschen (nur für Testdaten/Tests) */
    public function dropAll(): void
    {
        if ($this->db->driver() === 'mysql') {
            $this->db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($this->db->column('SHOW TABLES') as $t) {
                $this->db->pdo()->exec("DROP TABLE `$t`");
            }
            $this->db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
        } else {
            $this->db->pdo()->exec('PRAGMA foreign_keys = OFF');
            foreach ($this->db->column("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'") as $t) {
                $this->db->pdo()->exec("DROP TABLE \"$t\"");
            }
            $this->db->pdo()->exec('PRAGMA foreign_keys = ON');
        }
    }

    public function translate(string $sql): string
    {
        $mysql = $this->db->driver() === 'mysql';
        return strtr($sql, [
            '{PK}' => $mysql ? 'INTEGER NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT',
            '{TABLE}' => $mysql ? 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '',
            '{LONGTEXT}' => $mysql ? 'MEDIUMTEXT' : 'TEXT',
        ]);
    }

    private function ensureTable(): void
    {
        $this->db->pdo()->exec($this->translate(
            'CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(50) NOT NULL PRIMARY KEY, applied_at DATETIME NOT NULL) {TABLE}'
        ));
    }
}
