<?php

declare(strict_types=1);

namespace Atelier\Modules\Project;

use Atelier\Persistence\Database;
use Atelier\Support\Clock;

/**
 * Accès aux projets (table project_project). Tout le SQL des projets est ici :
 * jamais dans les routes ni les gabarits. La suppression est logique (deleted_at).
 */
final class ProjectRepository
{
    /** @var array<string, string> tri autorisé => expression SQL */
    public const SORTS = [
        'due' => 'CASE WHEN p.due_date IS NULL THEN 1 ELSE 0 END ASC, p.due_date ASC, p.title ASC',
        'updated' => 'p.updated_at DESC, p.title ASC',
        'title' => 'p.title ASC',
        'priority' => 'p.priority ASC, p.due_date ASC, p.title ASC',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    public static function isSortable(string $sort): bool
    {
        return isset(self::SORTS[$sort]);
    }

    // ----- Lecture -----

    /**
     * Projets actifs avec indicateurs (tâches faites / total, prochaine échéance de tâche).
     *
     * @return list<array<string, mixed>>
     */
    public function listActive(string $search, ?string $tag, string $sort): array
    {
        $where = ['p.deleted_at IS NULL'];
        $params = [];
        $search = trim($search);
        if ($search !== '') {
            $where[] = '(' . $this->db->lower('p.title') . ' LIKE :s OR ' . $this->db->lower("COALESCE(p.summary, '')") . ' LIKE :s OR ' . $this->db->lower("COALESCE(p.description, '')") . ' LIKE :s)';
            $params['s'] = '%' . mb_strtolower($search, 'UTF-8') . '%';
        }
        if ($tag !== null && $tag !== '') {
            $where[] = "EXISTS (SELECT 1 FROM info_registry r INNER JOIN info_tags it ON it.info_id = r.id INNER JOIN tags t ON t.id = it.tag_id
                        WHERE r.dataset_code = :ds AND r.local_key = CAST(p.id AS " . ($this->db->isSqlite() ? 'TEXT' : 'CHAR') . ") AND t.scope = 'shared' AND t.normalized = :tag)";
            $params['ds'] = ProjectService::DATASET;
            $params['tag'] = $tag;
        }
        $order = self::SORTS[$sort] ?? self::SORTS['updated'];
        return $this->db->select(
            'SELECT p.*, u.display_name AS owner_name, ' . $this->indicatorColumns() . '
             FROM project_project p LEFT JOIN users u ON u.id = p.owner_id
             WHERE ' . implode(' AND ', $where) . " ORDER BY $order, p.id ASC",
            $params
        );
    }

    /** @return array<string, mixed>|null projet actif */
    public function find(int $id): ?array
    {
        $row = $this->db->selectOne(
            'SELECT p.*, u.display_name AS owner_name, ' . $this->indicatorColumns() . ' FROM project_project p LEFT JOIN users u ON u.id = p.owner_id WHERE p.id = :id AND p.deleted_at IS NULL',
            ['id' => $id]
        );
        return $row;
    }

    /** @return array<string, mixed>|null projet actif par identifiant lisible */
    public function findBySlug(string $slug): ?array
    {
        $row = $this->db->selectOne('SELECT id FROM project_project WHERE slug = :s AND deleted_at IS NULL', ['s' => $slug]);
        return $row === null ? null : $this->find((int) $row['id']);
    }

    /** @return array<string, mixed>|null projet en corbeille */
    public function findTrashed(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM project_project WHERE id = :id AND deleted_at IS NOT NULL', ['id' => $id]);
    }

    /** @return array<string, mixed>|null projet actif de même titre (insensible à la casse), hors identifiant donné */
    public function findByTitle(string $title, ?int $exceptId = null): ?array
    {
        // LOWER des deux côtés : même fonction SQL pour la valeur stockée et la valeur saisie
        // (LOWER de SQLite ne traite que l'ASCII, celui de MariaDB gère l'Unicode).
        $sql = 'SELECT id, title FROM project_project WHERE ' . $this->db->lower('title') . ' = ' . $this->db->lower(':t') . ' AND deleted_at IS NULL';
        $params = ['t' => trim($title)];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :x';
            $params['x'] = $exceptId;
        }
        return $this->db->selectOne($sql, $params);
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM project_project WHERE slug = :s';
        $params = ['s' => $slug];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :x';
            $params['x'] = $exceptId;
        }
        return $this->db->count($sql, $params) > 0;
    }

    /** Identifiant lisible unique dérivé du titre (suffixe numérique si besoin). */
    public function uniqueSlug(string $title, ?int $exceptId = null): string
    {
        $base = self::slugify($title);
        $slug = $base;
        $n = 2;
        while ($this->slugExists($slug, $exceptId)) {
            $slug = $base . '-' . $n++;
        }
        return $slug;
    }

    /** @return array<string, int> statut => nombre de projets actifs */
    public function countByStatus(): array
    {
        $result = array_fill_keys(array_keys(ProjectModule::STATUSES), 0);
        foreach ($this->db->select('SELECT status, COUNT(*) AS n FROM project_project WHERE deleted_at IS NULL GROUP BY status') as $row) {
            $result[(string) $row['status']] = (int) $row['n'];
        }
        return $result;
    }

    public function countAll(): int
    {
        return $this->db->count('SELECT COUNT(*) FROM project_project');
    }

    public function countActive(): int
    {
        return $this->db->count('SELECT COUNT(*) FROM project_project WHERE deleted_at IS NULL');
    }

    /** Projets « en cours » ayant au moins une tâche non faite en retard (échéance strictement avant $today). */
    public function countActiveWithLateTask(string $today): int
    {
        return $this->db->count(
            "SELECT COUNT(*) FROM project_project p WHERE p.deleted_at IS NULL AND p.status = 'active'
             AND EXISTS (SELECT 1 FROM project_task t WHERE t.project_id = p.id AND t.deleted_at IS NULL AND t.done = 0 AND t.due_date IS NOT NULL AND t.due_date < :d)",
            ['d' => $today]
        );
    }

    // ----- Écriture -----

    /** @param array<string, mixed> $data */
    public function create(array $data, ?int $userId): int
    {
        $now = Clock::utc();
        return $this->db->insert('project_project', $this->columns($data) + [
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->db->update('project_project', $this->columns($data) + ['updated_at' => Clock::utc()], 'id = :id', ['id' => $id]);
    }

    public function setStatus(int $id, string $status): void
    {
        $this->db->update('project_project', ['status' => $status, 'updated_at' => Clock::utc()], 'id = :id AND deleted_at IS NULL', ['id' => $id]);
    }

    public function touch(int $id): void
    {
        $this->db->update('project_project', ['updated_at' => Clock::utc()], 'id = :id', ['id' => $id]);
    }

    public function softDelete(int $id): bool
    {
        return $this->db->update('project_project', ['deleted_at' => Clock::utc()], 'id = :id AND deleted_at IS NULL', ['id' => $id]) > 0;
    }

    public function restore(int $id): bool
    {
        return $this->db->update('project_project', ['deleted_at' => null, 'updated_at' => Clock::utc()], 'id = :id AND deleted_at IS NOT NULL', ['id' => $id]) > 0;
    }

    /** Suppression physique d'un projet en corbeille (tâches et journal par cascade ou explicitement). */
    public function purge(int $id): bool
    {
        $this->db->delete('project_task', 'project_id = :p', ['p' => $id]);
        $this->db->delete('project_note', 'project_id = :p', ['p' => $id]);
        return $this->db->delete('project_project', 'id = :id AND deleted_at IS NOT NULL', ['id' => $id]) > 0;
    }

    // ----- Corbeille -----

    /** @return list<array<string, mixed>> projets en corbeille depuis moins de N jours */
    public function trashed(int $retentionDays): array
    {
        $limit = Clock::utc(Clock::now()->modify('-' . $retentionDays . ' days'));
        return $this->db->select(
            'SELECT p.*, u.display_name AS owner_name FROM project_project p LEFT JOIN users u ON u.id = p.owner_id WHERE p.deleted_at IS NOT NULL AND p.deleted_at >= :l ORDER BY p.deleted_at DESC',
            ['l' => $limit]
        );
    }

    /** @return list<int> */
    public function expiredTrashIds(int $retentionDays): array
    {
        $limit = Clock::utc(Clock::now()->modify('-' . $retentionDays . ' days'));
        return array_map(static fn (array $r): int => (int) $r['id'], $this->db->select('SELECT id FROM project_project WHERE deleted_at IS NOT NULL AND deleted_at < :l', ['l' => $limit]));
    }

    // ----- Interne -----

    /** Sous-requêtes d'indicateurs d'une ligne p : tâches faites / total, tâches en retard, prochaine échéance de tâche. */
    private function indicatorColumns(): string
    {
        return '(SELECT COUNT(*) FROM project_task t WHERE t.project_id = p.id AND t.deleted_at IS NULL) AS task_total, '
            . '(SELECT COUNT(*) FROM project_task t WHERE t.project_id = p.id AND t.deleted_at IS NULL AND t.done = 1) AS task_done, '
            . '(SELECT MIN(t.due_date) FROM project_task t WHERE t.project_id = p.id AND t.deleted_at IS NULL AND t.done = 0 AND t.due_date IS NOT NULL) AS next_task_due';
    }

    /**
     * Colonnes modifiables par l'utilisateur.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function columns(array $data): array
    {
        return [
            'title' => (string) $data['title'],
            'slug' => (string) $data['slug'],
            'status' => (string) $data['status'],
            'summary' => $data['summary'] !== null && $data['summary'] !== '' ? (string) $data['summary'] : null,
            'description' => $data['description'] !== null && $data['description'] !== '' ? (string) $data['description'] : null,
            'start_date' => $data['start_date'] ?: null,
            'due_date' => $data['due_date'] ?: null,
            'budget_estimate' => $data['budget_estimate'] === null ? null : (int) $data['budget_estimate'],
            'priority' => (int) $data['priority'],
            'owner_id' => $data['owner_id'] === null ? null : (int) $data['owner_id'],
        ];
    }

    /**
     * Identifiant lisible : minuscules, sans accents, tirets. Table de translittération explicite
     * (iconv //TRANSLIT produit « 'e » pour « é » sous Windows) — même approche que le module Pages.
     */
    public static function slugify(string $title): string
    {
        $slug = mb_strtolower(trim($title), 'UTF-8');
        $slug = strtr($slug, [
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'î' => 'i', 'ï' => 'i', 'í' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u', 'ç' => 'c', 'œ' => 'oe', 'æ' => 'ae', 'ñ' => 'n', 'ÿ' => 'y', 'ß' => 'ss',
            '’' => '-', "'" => '-',
        ]);
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug, '-');
        return $slug !== '' ? mb_substr($slug, 0, 100, 'UTF-8') : 'projet';
    }
}
