<?php

declare(strict_types=1);

namespace Atelier\Modules\Users;

use Atelier\Persistence\Database;
use Atelier\Support\Clock;

/**
 * Requêtes de consultation propres au module : liste paginée avec filtres étendus
 * (blocage temporaire, mot de passe temporaire), export et listes de sélection.
 * Les écritures passent par le dépôt du noyau (UserRepository).
 */
final class AccountRepository
{
    public const STATUS_FILTERS = ['active', 'disabled', 'locked', 'temporary'];

    /** @var array<string, string> colonne de tri autorisée => expression SQL */
    private const SORTS = [
        'username' => 'u.username',
        'display_name' => 'u.display_name',
        'status' => 'u.status',
        'last_login_at' => 'u.last_login_at',
        'created_at' => 'u.created_at',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    public static function isSortable(string $column): bool
    {
        return isset(self::SORTS[$column]);
    }

    /**
     * @param array<string, mixed> $filters search, status (active|disabled|locked|temporary), group_id
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function paginate(array $filters, int $page, int $perPage, string $sort = 'username', string $direction = 'asc'): array
    {
        [$whereSql, $params] = $this->where($filters);
        $orderBy = $this->orderBy($sort, $direction);
        $total = $this->db->count("SELECT COUNT(*) FROM users u WHERE $whereSql", $params);
        // Garde-fou : un numéro de page démesuré déborderait l’entier et rendrait la clause OFFSET invalide.
        $offset = max(0, (min($page, 1000000) - 1) * $perPage);
        $rows = $this->db->select(
            "SELECT u.id, u.username, u.display_name, u.email, u.status, u.must_change_password, u.failed_attempts,
                    u.locked_until, u.last_login_at, u.created_at, u.updated_at, u.disabled_at
             FROM users u WHERE $whereSql ORDER BY $orderBy, u.id ASC LIMIT $perPage OFFSET $offset",
            $params
        );
        return ['rows' => $this->attachGroups($rows), 'total' => $total];
    }

    /**
     * Toutes les lignes correspondant aux filtres (export), sans mot de passe.
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function export(array $filters, string $sort = 'username', string $direction = 'asc'): array
    {
        [$whereSql, $params] = $this->where($filters);
        $orderBy = $this->orderBy($sort, $direction);
        $rows = $this->db->select(
            "SELECT u.id, u.username, u.display_name, u.email, u.status, u.must_change_password, u.failed_attempts,
                    u.locked_until, u.last_login_at, u.created_at
             FROM users u WHERE $whereSql ORDER BY $orderBy, u.id ASC",
            $params
        );
        return $this->attachGroups($rows);
    }

    /** @return array<string, int> nombre de comptes par état (active, disabled, locked, temporary) */
    public function countByStatus(): array
    {
        $now = Clock::utc();
        return [
            'active' => $this->db->count("SELECT COUNT(*) FROM users WHERE status = 'active'"),
            'disabled' => $this->db->count("SELECT COUNT(*) FROM users WHERE status = 'disabled'"),
            'locked' => $this->db->count('SELECT COUNT(*) FROM users WHERE locked_until IS NOT NULL AND locked_until > :now', ['now' => $now]),
            'temporary' => $this->db->count('SELECT COUNT(*) FROM users WHERE must_change_password = 1'),
        ];
    }

    /**
     * Comptes pour les listes de sélection (sujets de règles, membres à ajouter).
     *
     * @return list<array<string, mixed>>
     */
    public function forSelect(): array
    {
        return $this->db->select('SELECT id, username, display_name, status FROM users ORDER BY username');
    }

    /**
     * Comptes qui ne sont pas membres d'un groupe donné.
     *
     * @return list<array<string, mixed>>
     */
    public function notInGroup(int $groupId): array
    {
        return $this->db->select(
            'SELECT u.id, u.username, u.display_name, u.status FROM users u
             WHERE NOT EXISTS (SELECT 1 FROM user_groups ug WHERE ug.user_id = u.id AND ug.group_id = :g)
             ORDER BY u.username',
            ['g' => $groupId]
        );
    }

    /** Un compte est-il temporairement bloqué ? */
    public static function isLocked(array $user): bool
    {
        $until = Clock::parseUtc($user['locked_until'] ?? null);
        return $until !== null && $until > Clock::now();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function where(array $filters): array
    {
        $where = ['1 = 1'];
        $params = [];
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(' . $this->db->lower('u.username') . ' LIKE :search OR ' . $this->db->lower('u.display_name') . ' LIKE :search OR ' . $this->db->lower('COALESCE(u.email, \'\')') . ' LIKE :search)';
            $params['search'] = '%' . mb_strtolower($search, 'UTF-8') . '%';
        }
        $status = (string) ($filters['status'] ?? '');
        switch ($status) {
            case 'active':
            case 'disabled':
                $where[] = 'u.status = :status';
                $params['status'] = $status;
                break;
            case 'locked':
                $where[] = 'u.locked_until IS NOT NULL AND u.locked_until > :now';
                $params['now'] = Clock::utc();
                break;
            case 'temporary':
                $where[] = 'u.must_change_password = 1';
                break;
            default:
                break;
        }
        if (!empty($filters['group_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM user_groups ug WHERE ug.user_id = u.id AND ug.group_id = :group_id)';
            $params['group_id'] = (int) $filters['group_id'];
        }
        return [implode(' AND ', $where), $params];
    }

    private function orderBy(string $sort, string $direction): string
    {
        $column = self::SORTS[$sort] ?? 'u.username';
        $dir = strtolower($direction) === 'desc' ? 'DESC' : 'ASC';
        // Les dates absentes (jamais connecté) sont placées en fin de liste quel que soit le sens.
        if ($sort === 'last_login_at') {
            return "CASE WHEN u.last_login_at IS NULL THEN 1 ELSE 0 END ASC, $column $dir";
        }
        return "$column $dir";
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function attachGroups(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $placeholders = [];
        $params = [];
        foreach ($ids as $i => $id) {
            $placeholders[] = ':u' . $i;
            $params['u' . $i] = $id;
        }
        $links = $this->db->select(
            'SELECT ug.user_id, g.id, g.name, g.label, g.is_system FROM user_groups ug INNER JOIN groups g ON g.id = ug.group_id
             WHERE ug.user_id IN (' . implode(', ', $placeholders) . ') ORDER BY g.label',
            $params
        );
        $byUser = [];
        foreach ($links as $link) {
            $byUser[(int) $link['user_id']][] = $link;
        }
        foreach ($rows as &$row) {
            $row['groups'] = $byUser[(int) $row['id']] ?? [];
        }
        unset($row);
        return $rows;
    }
}
