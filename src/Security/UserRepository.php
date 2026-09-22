<?php

declare(strict_types=1);

namespace Atelier\Security;

use Atelier\Persistence\Database;
use Atelier\Support\Clock;

/**
 * Accès aux comptes utilisateurs et à leurs groupes.
 */
final class UserRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM users WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public function findByUsername(string $username): ?array
    {
        return $this->db->selectOne('SELECT * FROM users WHERE ' . $this->db->lower('username') . ' = :u', ['u' => mb_strtolower(trim($username), 'UTF-8')]);
    }

    public function usernameExists(string $username, ?int $exceptId = null): bool
    {
        $row = $this->db->selectOne(
            'SELECT id FROM users WHERE ' . $this->db->lower('username') . ' = :u' . ($exceptId !== null ? ' AND id <> :id' : ''),
            $exceptId !== null ? ['u' => mb_strtolower(trim($username), 'UTF-8'), 'id' => $exceptId] : ['u' => mb_strtolower(trim($username), 'UTF-8')]
        );
        return $row !== null;
    }

    /**
     * @param array<string, mixed> $filters search, status, group_id
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function paginate(array $filters, int $page, int $perPage, string $sort = 'username', string $direction = 'asc'): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (!empty($filters['search'])) {
            $where[] = '(' . $this->db->lower('u.username') . ' LIKE :search OR ' . $this->db->lower('u.display_name') . ' LIKE :search OR ' . $this->db->lower('u.email') . ' LIKE :search)';
            $params['search'] = '%' . mb_strtolower((string) $filters['search'], 'UTF-8') . '%';
        }
        if (!empty($filters['status'])) {
            $where[] = 'u.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['group_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM user_groups ug WHERE ug.user_id = u.id AND ug.group_id = :group_id)';
            $params['group_id'] = (int) $filters['group_id'];
        }
        $allowedSorts = ['username' => 'u.username', 'display_name' => 'u.display_name', 'status' => 'u.status', 'last_login_at' => 'u.last_login_at', 'created_at' => 'u.created_at'];
        $orderBy = ($allowedSorts[$sort] ?? 'u.username') . ' ' . (strtolower($direction) === 'desc' ? 'DESC' : 'ASC');

        $whereSql = implode(' AND ', $where);
        $total = $this->db->count("SELECT COUNT(*) FROM users u WHERE $whereSql", $params);
        $offset = max(0, ($page - 1) * $perPage);
        $rows = $this->db->select(
            "SELECT u.* FROM users u WHERE $whereSql ORDER BY $orderBy, u.id ASC LIMIT $perPage OFFSET $offset",
            $params
        );
        foreach ($rows as &$row) {
            $row['groups'] = $this->groupsOf((int) $row['id']);
        }
        unset($row);
        return ['rows' => $rows, 'total' => $total];
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select('SELECT id, username, display_name, status FROM users ORDER BY username');
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $now = Clock::utc();
        return $this->db->insert('users', [
            'username' => trim((string) $data['username']),
            'display_name' => trim((string) ($data['display_name'] ?? $data['username'])),
            'email' => isset($data['email']) && $data['email'] !== '' ? trim((string) $data['email']) : null,
            'password_hash' => (string) $data['password_hash'],
            'status' => (string) ($data['status'] ?? 'active'),
            'must_change_password' => (int) ($data['must_change_password'] ?? 1),
            'failed_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => null,
            'password_changed_at' => $data['password_changed_at'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
            'disabled_at' => null,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $data['updated_at'] = Clock::utc();
        $this->db->update('users', $data, 'id = :id', ['id' => $id]);
    }

    public function setPassword(int $id, string $hash, bool $mustChange): void
    {
        $this->update($id, [
            'password_hash' => $hash,
            'must_change_password' => (int) $mustChange,
            'password_changed_at' => Clock::utc(),
            'failed_attempts' => 0,
            'locked_until' => null,
        ]);
    }

    public function recordFailedAttempt(int $id, int $maxAttempts, int $lockoutSeconds): int
    {
        $user = $this->find($id);
        if ($user === null) {
            return 0;
        }
        $attempts = (int) $user['failed_attempts'] + 1;
        $data = ['failed_attempts' => $attempts];
        if ($attempts >= $maxAttempts) {
            $data['locked_until'] = Clock::utcFromTimestamp(time() + $lockoutSeconds);
        }
        $this->update($id, $data);
        return $attempts;
    }

    public function recordSuccessfulLogin(int $id): void
    {
        $this->update($id, ['failed_attempts' => 0, 'locked_until' => null, 'last_login_at' => Clock::utc()]);
    }

    public function unlock(int $id): void
    {
        $this->update($id, ['failed_attempts' => 0, 'locked_until' => null]);
    }

    public function lock(int $id, int $seconds = 86400 * 365 * 10): void
    {
        $this->update($id, ['locked_until' => Clock::utcFromTimestamp(time() + $seconds)]);
    }

    public function disable(int $id): void
    {
        $this->update($id, ['status' => 'disabled', 'disabled_at' => Clock::utc()]);
    }

    public function enable(int $id): void
    {
        $this->update($id, ['status' => 'active', 'disabled_at' => null]);
    }

    public function delete(int $id): void
    {
        $this->db->delete('users', 'id = :id', ['id' => $id]);
    }

    /** @param array<string, mixed> $user */
    public static function isLocked(array $user): bool
    {
        $until = Clock::parseUtc($user['locked_until'] ?? null);
        return $until !== null && $until > Clock::now();
    }

    // ----- Groupes -----

    /** @return list<array<string, mixed>> */
    public function groupsOf(int $userId): array
    {
        return $this->db->select(
            'SELECT g.* FROM groups g INNER JOIN user_groups ug ON ug.group_id = g.id WHERE ug.user_id = :u ORDER BY g.label',
            ['u' => $userId]
        );
    }

    /** @param list<int> $groupIds */
    public function setGroups(int $userId, array $groupIds): void
    {
        $this->db->transaction(function (Database $db) use ($userId, $groupIds): void {
            $db->delete('user_groups', 'user_id = :u', ['u' => $userId]);
            $now = Clock::utc();
            foreach (array_unique(array_map('intval', $groupIds)) as $groupId) {
                $db->insert('user_groups', ['user_id' => $userId, 'group_id' => $groupId, 'added_at' => $now]);
            }
        });
    }

    public function addToGroup(int $userId, int $groupId): void
    {
        $exists = $this->db->selectOne('SELECT 1 AS x FROM user_groups WHERE user_id = :u AND group_id = :g', ['u' => $userId, 'g' => $groupId]);
        if ($exists === null) {
            $this->db->insert('user_groups', ['user_id' => $userId, 'group_id' => $groupId, 'added_at' => Clock::utc()]);
        }
    }

    /** @return list<array<string, mixed>> */
    public function allGroups(): array
    {
        return $this->db->select('SELECT g.*, (SELECT COUNT(*) FROM user_groups ug WHERE ug.group_id = g.id) AS member_count FROM groups g ORDER BY g.label');
    }

    /** @return array<string, mixed>|null */
    public function findGroup(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM groups WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public function findGroupByName(string $name): ?array
    {
        return $this->db->selectOne('SELECT * FROM groups WHERE name = :n', ['n' => $name]);
    }

    public function createGroup(string $name, string $label, ?string $description = null): int
    {
        $now = Clock::utc();
        return $this->db->insert('groups', ['name' => $name, 'label' => $label, 'description' => $description, 'is_system' => 0, 'created_at' => $now, 'updated_at' => $now]);
    }

    public function updateGroup(int $id, string $label, ?string $description): void
    {
        $this->db->update('groups', ['label' => $label, 'description' => $description, 'updated_at' => Clock::utc()], 'id = :id', ['id' => $id]);
    }

    public function deleteGroup(int $id): void
    {
        $this->db->transaction(function (Database $db) use ($id): void {
            $db->delete('acl_rules', "subject_type = 'group' AND subject_id = :id", ['id' => $id]);
            $db->delete('groups', 'id = :id', ['id' => $id]);
        });
    }

    /** @return list<array<string, mixed>> */
    public function membersOf(int $groupId): array
    {
        return $this->db->select(
            'SELECT u.id, u.username, u.display_name, u.status FROM users u INNER JOIN user_groups ug ON ug.user_id = u.id WHERE ug.group_id = :g ORDER BY u.username',
            ['g' => $groupId]
        );
    }

    public function countActive(): int
    {
        return $this->db->count("SELECT COUNT(*) FROM users WHERE status = 'active'");
    }
}
