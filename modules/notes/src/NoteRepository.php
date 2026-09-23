<?php

declare(strict_types=1);

namespace Atelier\Modules\Notes;

use Atelier\Persistence\Database;
use Atelier\Support\Clock;

/**
 * Accès aux notes. Toutes les lectures et écritures sont filtrées par propriétaire :
 * aucune méthode ne permet d'atteindre la note d'un autre utilisateur sans fournir
 * explicitement son identifiant (vue d'assistance, contrôlée en amont par le module).
 */
final class NoteRepository
{
    public const TABLE = 'notes_note';

    /** @var array<string, string> colonne de tri autorisée => expression SQL */
    private const SORTS = [
        'updated_at' => 'n.updated_at',
        'created_at' => 'n.created_at',
        'title' => 'n.title',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    public static function isSortable(string $column): bool
    {
        return isset(self::SORTS[$column]);
    }

    /**
     * Notes actives d'un propriétaire, paginées, avec recherche plein texte simple (titre et contenu).
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function paginate(int $ownerId, string $search, int $page, int $perPage, string $sort = 'updated_at', string $direction = 'desc'): array
    {
        [$where, $params] = $this->whereActive($ownerId, $search);
        $orderBy = $this->orderBy($sort, $direction);
        $total = $this->db->count("SELECT COUNT(*) FROM " . self::TABLE . " n WHERE $where", $params);
        // Garde-fou : un numéro de page démesuré déborderait l'entier et rendrait la clause OFFSET invalide.
        $offset = max(0, (min($page, 1000000) - 1) * $perPage);
        $rows = $this->db->select(
            "SELECT n.id, n.owner_id, n.title, n.content, n.created_at, n.updated_at
             FROM " . self::TABLE . " n WHERE $where ORDER BY $orderBy, n.id DESC LIMIT $perPage OFFSET $offset",
            $params
        );
        return ['rows' => $rows, 'total' => $total];
    }

    /** Nombre de notes actives d'un propriétaire. */
    public function countActive(int $ownerId): int
    {
        return $this->db->count('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE owner_id = :o AND deleted_at IS NULL', ['o' => $ownerId]);
    }

    /** Nombre total de notes (toutes lignes, tous propriétaires) : utilisé par le seed. */
    public function countAll(): int
    {
        return $this->db->count('SELECT COUNT(*) FROM ' . self::TABLE);
    }

    /** Note active appartenant au propriétaire, ou null. @return array<string, mixed>|null */
    public function find(int $id, int $ownerId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM ' . self::TABLE . ' WHERE id = :id AND owner_id = :o AND deleted_at IS NULL',
            ['id' => $id, 'o' => $ownerId]
        );
    }

    /** Note en corbeille appartenant au propriétaire, ou null. @return array<string, mixed>|null */
    public function findTrashed(int $id, int $ownerId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM ' . self::TABLE . ' WHERE id = :id AND owner_id = :o AND deleted_at IS NOT NULL',
            ['id' => $id, 'o' => $ownerId]
        );
    }

    public function create(int $ownerId, string $title, string $content): int
    {
        $now = Clock::utc();
        return $this->db->insert(self::TABLE, [
            'owner_id' => $ownerId,
            'title' => $title,
            'content' => $content,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);
    }

    /** Met à jour titre et contenu ; retourne le nouvel horodatage de modification. */
    public function update(int $id, int $ownerId, string $title, string $content): string
    {
        $now = Clock::utc();
        $this->db->update(
            self::TABLE,
            ['title' => $title, 'content' => $content, 'updated_at' => $now],
            'id = :id AND owner_id = :o AND deleted_at IS NULL',
            ['id' => $id, 'o' => $ownerId]
        );
        return $now;
    }

    /** Suppression logique (mise à la corbeille). */
    public function softDelete(int $id, int $ownerId): bool
    {
        return $this->db->update(
            self::TABLE,
            ['deleted_at' => Clock::utc()],
            'id = :id AND owner_id = :o AND deleted_at IS NULL',
            ['id' => $id, 'o' => $ownerId]
        ) > 0;
    }

    public function restore(int $id, int $ownerId): bool
    {
        return $this->db->update(
            self::TABLE,
            ['deleted_at' => null, 'updated_at' => Clock::utc()],
            'id = :id AND owner_id = :o AND deleted_at IS NOT NULL',
            ['id' => $id, 'o' => $ownerId]
        ) > 0;
    }

    /** Suppression physique d'une note en corbeille. */
    public function purge(int $id, int $ownerId): bool
    {
        return $this->db->delete(self::TABLE, 'id = :id AND owner_id = :o AND deleted_at IS NOT NULL', ['id' => $id, 'o' => $ownerId]) > 0;
    }

    /**
     * Notes en corbeille d'un propriétaire, supprimées depuis moins de $retentionDays jours.
     *
     * @return list<array<string, mixed>>
     */
    public function trashed(int $ownerId, int $retentionDays): array
    {
        $limit = Clock::utc(Clock::now()->modify('-' . $retentionDays . ' days'));
        return $this->db->select(
            'SELECT id, owner_id, title, content, created_at, updated_at, deleted_at FROM ' . self::TABLE . '
             WHERE owner_id = :o AND deleted_at IS NOT NULL AND deleted_at >= :l ORDER BY deleted_at DESC',
            ['o' => $ownerId, 'l' => $limit]
        );
    }

    /**
     * Identifiants des notes en corbeille depuis plus de $retentionDays jours (tous propriétaires).
     *
     * @return list<int>
     */
    public function expiredTrashIds(int $retentionDays): array
    {
        $limit = Clock::utc(Clock::now()->modify('-' . $retentionDays . ' days'));
        $rows = $this->db->select('SELECT id FROM ' . self::TABLE . ' WHERE deleted_at IS NOT NULL AND deleted_at < :l', ['l' => $limit]);
        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    /** Suppression physique par identifiant (purge de rétention). */
    public function deleteById(int $id): void
    {
        $this->db->delete(self::TABLE, 'id = :id', ['id' => $id]);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function whereActive(int $ownerId, string $search): array
    {
        $where = ['n.owner_id = :owner', 'n.deleted_at IS NULL'];
        $params = ['owner' => $ownerId];
        $search = trim($search);
        if ($search !== '') {
            $where[] = '(' . $this->db->lower('n.title') . ' LIKE :search OR ' . $this->db->lower("COALESCE(n.content, '')") . ' LIKE :search)';
            $params['search'] = '%' . mb_strtolower($search, 'UTF-8') . '%';
        }
        return [implode(' AND ', $where), $params];
    }

    private function orderBy(string $sort, string $direction): string
    {
        $column = self::SORTS[$sort] ?? self::SORTS['updated_at'];
        $dir = strtolower($direction) === 'asc' ? 'ASC' : 'DESC';
        return "$column $dir";
    }
}
