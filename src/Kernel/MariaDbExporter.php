<?php

declare(strict_types=1);

namespace Atelier\Kernel;

use Atelier\Persistence\Database;
use Atelier\Persistence\Migrator;
use RuntimeException;

/**
 * Migration contrôlée SQLite → MariaDB : crée le schéma cible via les mêmes migrations,
 * copie les données table par table, puis vérifie le nombre d'enregistrements.
 * Opération d'administration documentée (docs/exploitation.md), jamais automatique.
 */
final class MariaDbExporter
{
    public function __construct(private readonly Application $app, private readonly Database $target)
    {
    }

    /** @return list<string> */
    public function run(bool $dryRun = false): array
    {
        $source = $this->app->db;
        if (!$source->isSqlite()) {
            throw new RuntimeException('La source doit être SQLite.');
        }
        if ($this->target->isSqlite()) {
            throw new RuntimeException('La cible doit être MariaDB/MySQL.');
        }
        $report = [];
        $tables = $source->tables();
        $report[] = count($tables) . ' tables à exporter.';
        if ($dryRun) {
            foreach ($tables as $table) {
                $report[] = sprintf('  %-28s %d enregistrements', $table, $source->count('SELECT COUNT(*) FROM ' . $source->quoteIdentifier($table)));
            }
            return $report;
        }

        // Schéma cible : migrations du noyau puis des modules.
        $migrator = new Migrator($this->target);
        foreach ($migrator->migrate('core', dirname(__DIR__) . '/Persistence/migrations/core') as $done) {
            $report[] = 'Migration cible : ' . $done;
        }
        foreach ($this->app->modules->all() as $descriptor) {
            if ($descriptor->manifest !== null && is_dir($descriptor->manifest->migrationsDirectory())) {
                foreach ($migrator->migrate($descriptor->id, $descriptor->manifest->migrationsDirectory()) as $done) {
                    $report[] = 'Migration cible : ' . $done;
                }
            }
        }

        $this->target->execute('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($tables as $table) {
                if (!$this->target->tableExists($table)) {
                    $report[] = "ATTENTION : table $table absente de la cible, ignorée.";
                    continue;
                }
                $this->target->execute('DELETE FROM ' . $this->target->quoteIdentifier($table));
                $columns = array_intersect($source->columns($table), $this->target->columns($table));
                $count = 0;
                $offset = 0;
                do {
                    $rows = $source->select('SELECT * FROM ' . $source->quoteIdentifier($table) . ' LIMIT 500 OFFSET ' . $offset);
                    $this->target->transaction(function (Database $db) use ($rows, $table, $columns, &$count): void {
                        foreach ($rows as $row) {
                            $data = array_intersect_key($row, array_flip($columns));
                            $db->insert($table, $data);
                            $count++;
                        }
                    });
                    $offset += 500;
                } while (count($rows) === 500);
                $expected = $source->count('SELECT COUNT(*) FROM ' . $source->quoteIdentifier($table));
                $actual = $this->target->count('SELECT COUNT(*) FROM ' . $this->target->quoteIdentifier($table));
                $report[] = sprintf('  %-28s %d / %d %s', $table, $actual, $expected, $actual === $expected ? 'OK' : 'ÉCART');
            }
        } finally {
            $this->target->execute('SET FOREIGN_KEY_CHECKS = 1');
        }
        return $report;
    }
}
