<?php

declare(strict_types=1);

namespace Atelier\Modules\Budget;

use Atelier\Persistence\Database;
use Atelier\Support\Clock;

/**
 * Accès à la table budget_recurring : opérations récurrentes du prévisionnel.
 * Une récurrence en corbeille (deleted_at), ou dont le compte est en corbeille, est ignorée
 * (échéances, badge, projection).
 */
final class RecurringRepository
{
    public const TABLE = 'budget_recurring';
    public const UNITS = ['day' => 'jour(s)', 'week' => 'semaine(s)', 'month' => 'mois', 'year' => 'an(s)'];

    private const COLUMNS = 'r.id, r.account_id, r.category_id, r.label, r.payee, r.amount, r.interval_unit, r.interval_count, r.next_at, r.ends_at, r.active, r.notes, r.created_by, r.created_at, r.updated_at, r.deleted_at, r.deleted_by, a.name AS account_name, c.name AS category_name, p.name AS category_parent_name';
    private const FROM = ' FROM budget_recurring r INNER JOIN budget_account a ON a.id = r.account_id LEFT JOIN budget_category c ON c.id = r.category_id LEFT JOIN budget_category p ON p.id = c.parent_id';
    private const ALIVE = 'r.deleted_at IS NULL AND a.deleted_at IS NULL';

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed>|null récurrence vivante */
    public function find(int $id): ?array
    {
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . self::FROM . ' WHERE r.id = :id AND ' . self::ALIVE, ['id' => $id]);
        return $row === null ? null : $this->hydrate($row);
    }

    /** @return list<array<string, mixed>> */
    public function all(bool $activeOnly = false): array
    {
        return array_map([$this, 'hydrate'], $this->db->select('SELECT ' . self::COLUMNS . self::FROM . ' WHERE ' . self::ALIVE . ($activeOnly ? ' AND r.active = 1' : '') . ' ORDER BY r.active DESC, r.next_at ASC, r.id ASC'));
    }

    /** @return list<array<string, mixed>> récurrences actives dont l'échéance est atteinte */
    public function due(string $today): array
    {
        return array_map([$this, 'hydrate'], $this->db->select('SELECT ' . self::COLUMNS . self::FROM . ' WHERE ' . self::ALIVE . ' AND r.active = 1 AND r.next_at <= :d ORDER BY r.next_at ASC, r.id ASC', ['d' => $today]));
    }

    public function countDue(string $today): int
    {
        return $this->db->count('SELECT COUNT(*) FROM ' . self::TABLE . ' r INNER JOIN budget_account a ON a.id = r.account_id WHERE ' . self::ALIVE . ' AND r.active = 1 AND r.next_at <= :d', ['d' => $today]);
    }

    /** Récurrences rattachées à une catégorie, corbeille comprise (garde-fou de suppression). */
    public function countForCategory(int $categoryId): int
    {
        return $this->db->count('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE category_id = :c', ['c' => $categoryId]);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, ?int $userId): int
    {
        $now = Clock::utc();
        return $this->db->insert(self::TABLE, $this->columns($data) + ['created_by' => $userId, 'created_at' => $now, 'updated_at' => $now]);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->db->update(self::TABLE, $this->columns($data) + ['updated_at' => Clock::utc()], 'id = :id', ['id' => $id]);
    }

    /** Avance l'échéance après un postage ; désactive si la fin est dépassée. */
    public function advance(int $id, string $nextAt, bool $active): void
    {
        $this->db->update(self::TABLE, ['next_at' => $nextAt, 'active' => $active, 'updated_at' => Clock::utc()], 'id = :id', ['id' => $id]);
    }

    // ----- Corbeille -----

    public function softDelete(int $id, ?int $userId): bool
    {
        return $this->db->update(self::TABLE, ['deleted_at' => Clock::utc(), 'deleted_by' => $userId], 'id = :id AND deleted_at IS NULL', ['id' => $id]) > 0;
    }

    public function restore(int $id): bool
    {
        return $this->db->update(self::TABLE, ['deleted_at' => null, 'deleted_by' => null, 'updated_at' => Clock::utc()], 'id = :id AND deleted_at IS NOT NULL', ['id' => $id]) > 0;
    }

    /** Suppression physique d'une récurrence en corbeille. */
    public function purge(int $id): bool
    {
        return $this->db->delete(self::TABLE, 'id = :id AND deleted_at IS NOT NULL', ['id' => $id]) > 0;
    }

    /** Suppression physique des récurrences d'un compte (purge du compte). */
    public function deleteForAccount(int $accountId): int
    {
        return $this->db->delete(self::TABLE, 'account_id = :a', ['a' => $accountId]);
    }

    /** @return array<string, mixed>|null */
    public function findTrashed(int $id): ?array
    {
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . self::FROM . ' WHERE r.id = :id AND r.deleted_at IS NOT NULL', ['id' => $id]);
        return $row === null ? null : $this->hydrate($row);
    }

    /** @return list<array<string, mixed>> */
    public function trashed(int $retentionDays): array
    {
        $limit = Clock::utc(Clock::now()->modify('-' . $retentionDays . ' days'));
        return array_map([$this, 'hydrate'], $this->db->select('SELECT ' . self::COLUMNS . self::FROM . ' WHERE r.deleted_at IS NOT NULL AND r.deleted_at >= :l ORDER BY r.deleted_at DESC, r.id DESC', ['l' => $limit]));
    }

    /** @return list<int> */
    public function expiredTrashIds(int $retentionDays): array
    {
        $limit = Clock::utc(Clock::now()->modify('-' . $retentionDays . ' days'));
        return array_map(static fn (array $r): int => (int) $r['id'], $this->db->select('SELECT id FROM ' . self::TABLE . ' WHERE deleted_at IS NOT NULL AND deleted_at < :l', ['l' => $limit]));
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function columns(array $data): array
    {
        $string = static fn (string $key): ?string => isset($data[$key]) && $data[$key] !== '' ? (string) $data[$key] : null;
        return [
            'account_id' => (int) $data['account_id'],
            'category_id' => isset($data['category_id']) && (int) $data['category_id'] > 0 ? (int) $data['category_id'] : null,
            'label' => (string) $data['label'],
            'payee' => $string('payee'),
            'amount' => (int) $data['amount'],
            'interval_unit' => (string) $data['interval_unit'],
            'interval_count' => max(1, (int) ($data['interval_count'] ?? 1)),
            'next_at' => (string) $data['next_at'],
            'ends_at' => $string('ends_at'),
            'active' => (bool) ($data['active'] ?? true),
            'notes' => $string('notes'),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function hydrate(array $row): array
    {
        foreach (['id', 'account_id', 'amount', 'interval_count'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        foreach (['category_id', 'created_by', 'deleted_by'] as $key) {
            $row[$key] = $row[$key] === null ? null : (int) $row[$key];
        }
        $row['active'] = (bool) $row['active'];
        $row['category_path'] = $row['category_name'] === null ? null : (($row['category_parent_name'] ?? null) !== null ? $row['category_parent_name'] . ' › ' : '') . $row['category_name'];
        return $row;
    }
}
