<?php

declare(strict_types=1);

namespace Atelier\Kernel;

use Atelier\Persistence\Database;
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
    public function __construct(private readonly Database $db, private readonly Config $config)
    {
    }

    public static function forApplication(Application $app): self
    {
        return new self($app->db, $app->config);
    }

    public function directory(): string
    {
        return $this->config->path('backups');
    }

    /** Crée une sauvegarde et retourne son nom. */
    public function create(string $suffix = ''): string
    {
        if (!$this->db->isSqlite()) {
            throw new RuntimeException('La sauvegarde intégrée ne prend en charge que SQLite ; utilisez mysqldump pour MariaDB.');
        }
        $name = Clock::now()->format('Ymd-His') . ($suffix !== '' ? '-' . preg_replace('/[^a-z0-9_-]/i', '', $suffix) : '');
        $target = $this->directory() . '/' . $name;
        Files::ensureDirectory($target);

        // Base : VACUUM INTO produit une copie cohérente même en mode WAL.
        $dbCopy = $target . '/atelier.sqlite';
        $this->db->execute('VACUUM INTO ' . $this->db->pdo()->quote(str_replace('\\', '/', $dbCopy)));

        // Pièces jointes
        $attachments = $this->config->path('attachments');
        if (is_dir($attachments)) {
            Files::copyDirectory($attachments, $target . '/attachments');
        }

        // Clé de chiffrement des pièces jointes : indispensable pour relire les fichiers restaurés.
        $keyFile = $this->config->path('attachments_key');
        if ($keyFile !== '' && is_file($keyFile)) {
            copy($keyFile, $target . '/attachments.key');
            @chmod($target . '/attachments.key', 0600);
        }

        // Configuration des modules
        $modulesConfig = $this->config->path('modules_config');
        if (is_file($modulesConfig)) {
            copy($modulesConfig, $target . '/modules.json');
        }

        $counts = [];
        foreach ($this->db->tables() as $table) {
            $counts[$table] = $this->db->count('SELECT COUNT(*) FROM ' . $this->db->quoteIdentifier($table));
        }
        Json::writeFile($target . '/manifest.json', [
            'name' => $name,
            'created_at' => Clock::utc(),
            'app_version' => $this->config->string('app.version'),
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

        $path = $this->db->sqlitePath();
        if ($path === null) {
            throw new RuntimeException('Restauration disponible uniquement pour SQLite.');
        }
        $this->db->close();
        foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        Files::ensureDirectory(dirname($path));
        copy($source . '/atelier.sqlite', $path);
        $report[] = 'Base restaurée.';

        $attachments = $this->config->path('attachments');
        if (is_dir($attachments)) {
            $this->removeDirectory($attachments);
        }
        if (is_dir($source . '/attachments')) {
            Files::copyDirectory($source . '/attachments', $attachments);
            $report[] = 'Pièces jointes restaurées.';
        } else {
            Files::ensureDirectory($attachments);
        }

        if (is_file($source . '/attachments.key')) {
            $keyFile = $this->config->path('attachments_key');
            Files::ensureDirectory(dirname($keyFile));
            copy($source . '/attachments.key', $keyFile);
            @chmod($keyFile, 0600);
            $report[] = 'Clé de chiffrement des pièces jointes restaurée.';
        } elseif (is_dir($source . '/attachments')) {
            $report[] = 'ATTENTION : la sauvegarde ne contient pas de clé de chiffrement ; les pièces jointes chiffrées avec une autre clé seront illisibles.';
        }

        if (is_file($source . '/modules.json')) {
            Files::ensureDirectory(dirname($this->config->path('modules_config')));
            copy($source . '/modules.json', $this->config->path('modules_config'));
            $report[] = 'Configuration des modules restaurée.';
        }

        foreach (($manifest['tables'] ?? []) as $table => $expected) {
            $actual = $this->db->count('SELECT COUNT(*) FROM ' . $this->db->quoteIdentifier((string) $table));
            if ($actual !== (int) $expected) {
                $report[] = sprintf('ATTENTION : table %s, %d enregistrements attendus, %d trouvés.', $table, $expected, $actual);
            }
        }
        $report[] = 'Vérification des tables effectuée.';
        @unlink($this->config->path('cache') . '/manifests.hash');
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
