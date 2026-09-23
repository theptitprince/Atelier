<?php

declare(strict_types=1);

namespace Atelier\Modules\Attachments;

use Atelier\Persistence\Database;

/**
 * Lectures complémentaires du module : tags portés par les fichiers (inscrits au registre commun
 * sous le jeu attachments.file) et répartition des fichiers par dossier virtuel, dans la portée
 * de l'utilisateur (ses fichiers) ou globale (assistance). Aucune écriture : les tags et les
 * dossiers sont modifiés via les services du noyau.
 */
final class FileIndexRepository
{
    public const DATASET = 'attachments.file';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Tags partagés de chaque fichier de la liste : identifiant de fichier => noms de tags triés.
     *
     * @param list<string> $attachmentIds
     * @return array<string, list<string>>
     */
    public function tagsOfFiles(array $attachmentIds): array
    {
        if ($attachmentIds === []) {
            return [];
        }
        $placeholders = [];
        $params = ['d' => self::DATASET, 's' => 'shared'];
        foreach (array_values($attachmentIds) as $i => $id) {
            $placeholders[] = ':a' . $i;
            $params['a' . $i] = (string) $id;
        }
        $rows = $this->db->select(
            'SELECT r.local_key AS attachment_id, t.name
             FROM info_registry r
             INNER JOIN info_tags it ON it.info_id = r.id
             INNER JOIN tags t ON t.id = it.tag_id
             WHERE r.dataset_code = :d AND t.scope = :s AND r.local_key IN (' . implode(', ', $placeholders) . ')
             ORDER BY t.normalized',
            $params
        );
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['attachment_id']][] = (string) $row['name'];
        }
        return $map;
    }

    /**
     * Tags utilisés par au moins un fichier actif (de l'utilisateur donné, ou de tous), avec le nombre de fichiers.
     *
     * @return list<array{name: string, normalized: string, count: int}>
     */
    public function fileTags(?int $uploadedBy = null): array
    {
        $params = ['d' => self::DATASET, 's' => 'shared'];
        $owner = '';
        if ($uploadedBy !== null) {
            $owner = ' AND a.uploaded_by = :u';
            $params['u'] = $uploadedBy;
        }
        $rows = $this->db->select(
            'SELECT t.name, t.normalized, COUNT(*) AS c
             FROM tags t
             INNER JOIN info_tags it ON it.tag_id = t.id
             INNER JOIN info_registry r ON r.id = it.info_id AND r.dataset_code = :d
             INNER JOIN attachments a ON a.id = r.local_key AND a.deleted_at IS NULL' . $owner . '
             WHERE t.scope = :s
             GROUP BY t.id, t.name, t.normalized
             ORDER BY t.normalized',
            $params
        );
        return array_map(static fn (array $r): array => ['name' => (string) $r['name'], 'normalized' => (string) $r['normalized'], 'count' => (int) $r['c']], $rows);
    }

    /**
     * Nombre de fichiers actifs par dossier ; la clé 0 correspond aux fichiers non rangés.
     *
     * @return array<int, int>
     */
    public function folderCounts(?int $uploadedBy = null): array
    {
        $params = [];
        $owner = '';
        if ($uploadedBy !== null) {
            $owner = ' AND uploaded_by = :u';
            $params['u'] = $uploadedBy;
        }
        $rows = $this->db->select('SELECT folder_id, COUNT(*) AS c FROM attachments WHERE deleted_at IS NULL' . $owner . ' GROUP BY folder_id', $params);
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) ($row['folder_id'] ?? 0)] = (int) $row['c'];
        }
        return $counts;
    }
}
