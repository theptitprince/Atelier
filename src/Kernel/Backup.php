<?php

declare(strict_types=1);

namespace Atelier\Kernel;

use Atelier\Support\Clock;
use Atelier\Support\Files;
use Atelier\Support\Json;
use RuntimeException;

/**
 * Sauvegarde et restauration cohérentes : base SQLite (copie via l'API de sauvegarde en ligne
 * VACUUM INTO), pièces jointes et fichier de configuration des modules, dans un répertoire daté
 * de var/backups accompagné d'un manifeste (nombre d'enregistrements par table, empreintes).
 */
final class Backup
{
    public function __construct(private readonly Application $app)
    {
    }

    public function directory(): string
    {
        return $this->app->config->path('backups');
    }

    /** Crée une sauvegarde et retourne son nom. */
    public function create(string $suffix = ''): string
    {
        if (!$this->app->db->isSqlite()) {
            throw new RuntimeException('La sauvegarde intégrée ne prend en charge que SQLite ; utilisez mysqldump pour MariaDB.');
        }
        $name = Clock::now()->format('Ymd-His') . ($suffix !== '' ? '-' . preg_replace('/[^a-z0-9_-]/i', '', $suffix) : '');
        $target = $this->directory() . '/' . $name;
        Files::ensureDirectory($target);

        // Base : VACUUM INTO produit une copie cohérente même en mode WAL.
        $dbCopy = $target . '/atelier.sqlite';
        $this->app->db->execute('VACUUM INTO ' . $this->app->db->pdo()->quote(str_replace('\\', '/', $dbCopy)));

        // Pièces jointes
        $attachments = $this->app->config->path('attachments');
        if (is_dir($attachments)) {
            Files::copyDirectory($attachments, $target . '/attachments');
        }

        // Configuration des modules
        $modulesConfig = $this->app->config->path('modules_config');
        if (is_file($modulesConfig)) {
            copy($modulesConfig, $target . '/modules.json');
        }

        $counts = [];
        foreach ($this->app->db->tables() as $table) {
            $counts[$table] = $this->app->db->count('SELECT COUNT(*) FROM ' . $this->app->db->quoteIdentifier($table));
        }
        Json::writeFile($target . '/manifest.json', [
            'name' => $name,
            'created_at' => Clock::utc(),
            'app_version' => $this->app->config->string('app.version'),
            'database_sha256' => hash_file('sha256', $dbCopy),
            'tables' => $counts,
            'attachments_size' => Files::directorySize($target . '/attachments'),
        ]);
        return $name;
    }

    /** @return list<array{name: string, size: int, created_at: string}> */
    public function list(): array
    {
        $entries = [];
        foreach (glob($this->directory() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $manifest = is_file($dir . '/manifest.json') ? Json::readFile($dir . '/manifest.json') : [];
            $entries[] = [
                'name' => basename($dir),
                'size' => Files::directorySize($dir),
                'created_at' => (string) ($manifest['created_at'] ?? Clock::utcFromTimestamp(filemtime($dir) ?: 0)),
            ];
        }
        usort($entries, static fn (array $a, array $b): int => strcmp($b['name'], $a['name']));
        return $entries;
    }

    /**
     * Restaure une sauvegarde : vérification de l'empreinte, remplacement de la base, des pièces
     * jointes et de la configuration, puis contrôle du nombre d'enregistrements.
     *
     * @return list<string>
     */
    public function restore(string $name): array
    {
        $source = $this->directory() . '/' . basename($name);
        if (!is_dir($source) || !is_file($source . '/atelier.sqlite')) {
            throw new RuntimeException('Sauvegarde introuvable : ' . $name);
        }
        $manifest = is_file($source . '/manifest.json') ? Json::readFile($source . '/manifest.json') : [];
        $report = [];

        if (isset($manifest['database_sha256']) && hash_file('sha256', $source . '/atelier.sqlite') !== $manifest['database_sha256']) {
            throw new RuntimeException('Empreinte de la base incohérente : sauvegarde corrompue.');
        }

        $path = $this->app->db->sqlitePath();
        if ($path === null) {
            throw new RuntimeException('Restauration disponible uniquement pour SQLite.');
        }
        $this->app->db->close();
        foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        Files::ensureDirectory(dirname($path));
        copy($source . '/atelier.sqlite', $path);
        $report[] = 'Base restaurée.';

        $attachments = $this->app->config->path('attachments');
        if (is_dir($attachments)) {
            $this->removeDirectory($attachments);
        }
        if (is_dir($source . '/attachments')) {
            Files::copyDirectory($source . '/attachments', $attachments);
            $report[] = 'Pièces jointes restaurées.';
        } else {
            Files::ensureDirectory($attachments);
        }

        if (is_file($source . '/modules.json')) {
            Files::ensureDirectory(dirname($this->app->config->path('modules_config')));
            copy($source . '/modules.json', $this->app->config->path('modules_config'));
            $report[] = 'Configuration des modules restaurée.';
        }

        foreach (($manifest['tables'] ?? []) as $table => $expected) {
            $actual = $this->app->db->count('SELECT COUNT(*) FROM ' . $this->app->db->quoteIdentifier((string) $table));
            if ($actual !== (int) $expected) {
                $report[] = sprintf('ATTENTION : table %s, %d enregistrements attendus, %d trouvés.', $table, $expected, $actual);
            }
        }
        $report[] = 'Vérification des tables effectuée.';
        $this->app->synchronizer()->invalidate();
        return $report;
    }

    private function removeDirectory(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}
