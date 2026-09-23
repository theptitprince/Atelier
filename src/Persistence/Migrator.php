<?php

declare(strict_types=1);

namespace Atelier\Persistence;

use RuntimeException;

/**
 * Migrations de structure versionnées, par périmètre ("core" ou identifiant de module).
 *
 * Un répertoire de migrations contient des fichiers "NNN_description.php" qui retournent
 * une fonction : function (Database $db): void { ... }. Les versions appliquées sont
 * enregistrées dans la table "migrations" (scope, version, name, applied_at).
 */
final class Migrator
{
    public function __construct(private readonly Database $db)
    {
    }

    public function ensureTable(): void
    {
        if ($this->db->tableExists('migrations')) {
            return;
        }
        $this->db->execute(sprintf(
            'CREATE TABLE migrations (
                id %s,
                scope %s NOT NULL,
                version INTEGER NOT NULL,
                name %s NOT NULL,
                applied_at %s NOT NULL,
                UNIQUE (scope, version)
            )%s',
            $this->db->primaryKey(),
            $this->db->varchar(64),
            $this->db->varchar(190),
            $this->db->datetime(),
            $this->db->tableOptions()
        ));
    }

    /**
     * @return list<array{version: int, name: string, file: string}>
     */
    public function available(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $migrations = [];
        foreach (scandir($directory) ?: [] as $file) {
            if (preg_match('/^(\d{3,})_([a-z0-9_]+)\.php$/i', $file, $m) === 1) {
                $migrations[] = ['version' => (int) $m[1], 'name' => $m[2], 'file' => $directory . '/' . $file];
            }
        }
        usort($migrations, static fn (array $a, array $b): int => $a['version'] <=> $b['version']);
        // Deux fichiers de même numéro seraient appliqués une fois et ignorés ensuite : erreur explicite.
        $seen = [];
        foreach ($migrations as $migration) {
            if (isset($seen[$migration['version']])) {
                throw new RuntimeException(sprintf('Migrations en doublon pour la version %03d dans %s : %s et %s.', $migration['version'], $directory, basename($seen[$migration['version']]), basename($migration['file'])));
            }
            $seen[$migration['version']] = $migration['file'];
        }
        return $migrations;
    }

    /** @return list<int> */
    public function applied(string $scope): array
    {
        $this->ensureTable();
        $rows = $this->db->select('SELECT version FROM migrations WHERE scope = :scope ORDER BY version', ['scope' => $scope]);
        return array_map(static fn (array $r): int => (int) $r['version'], $rows);
    }

    public function currentVersion(string $scope): int
    {
        $applied = $this->applied($scope);
        return $applied === [] ? 0 : max($applied);
    }

    /**
     * @return list<array{version: int, name: string, file: string}>
     */
    public function pending(string $scope, string $directory): array
    {
        $applied = $this->applied($scope);
        return array_values(array_filter(
            $this->available($directory),
            static fn (array $m): bool => !in_array($m['version'], $applied, true)
        ));
    }

    /**
     * Applique les migrations en attente, chacune dans sa propre transaction.
     *
     * @return list<string> descriptions des migrations appliquées
     */
    public function migrate(string $scope, string $directory): array
    {
        $done = [];
        foreach ($this->pending($scope, $directory) as $migration) {
            $callable = require $migration['file'];
            if (!is_callable($callable)) {
                throw new RuntimeException(sprintf('La migration %s doit retourner une fonction.', $migration['file']));
            }
            $this->db->transaction(function (Database $db) use ($callable, $migration, $scope): void {
                $callable($db);
                $db->insert('migrations', [
                    'scope' => $scope,
                    'version' => $migration['version'],
                    'name' => $migration['name'],
                    'applied_at' => \Atelier\Support\Clock::utc(),
                ]);
            });
            $done[] = sprintf('%s %03d_%s', $scope, $migration['version'], $migration['name']);
        }
        return $done;
    }
}
