<?php

declare(strict_types=1);

namespace Atelier\Modules\Budget;

use Atelier\Persistence\Database;
use Atelier\Support\Clock;

/** Accès à la table budget_recurring : opérations récurrentes du prévisionnel. */
final class RecurringRepository
{
    public const TABLE = 'budget_recurring';
    public const UNITS = ['day' => 'jour(s)', 'week' => 'semaine(s)', 'month' => 'mois', 'year' => 'an(s)'];

    private const COLUMNS = 'r.id, r.account_id, r.category_id, r.label, r.payee, r.amount, r.interval_unit, r.interval_count, r.next_at, r.ends_at, r.active, r.notes, r.created_by, r.created_at, r.updated_at, a.name AS account_name, c.name AS category_name, p.name AS category_parent_name';
    private const FROM = ' FROM budget_recurring r INNER JOIN budget_account a ON a.id = r.account_id LEFT JOIN budget_category c ON c.id = r.category_id LEFT JOIN budget_category p ON p.id = c.parent_id';

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . self::FROM . ' WHERE r.id = :id', ['id' => $id]);
        return $row === null ? null : $this->hydrate($row);
    }

    /** @return list<array<string, mixed>> */
    public function all(bool $activeOnly = false): array
    {
        return array_map([$this, 'hydrate'], $this->db->select('SELECT ' . self::COLUMNS . self::FROM . ($activeOnly ? ' WHERE r.active = 1' : '') . ' ORDER BY r.active DESC, r.next_at ASC, r.id ASC'));
    }

    /** @return list<array<string, mixed>> récurrences actives dont l'échéance est atteinte */
    public function due(string $today): array
    {
        return array_map([$this, 'hydrate'], $this->db->select('SELECT ' . self::COLUMNS . self::FROM . ' WHERE r.active = 1 AND r.next_at <= :d ORDER BY r.next_at ASC, r.id ASC', ['d' => $today]));
    }

    public function countDue(string $today): int
    {
        return $this->db->count('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE active = 1 AND next_at <= :d', ['d' => $today]);
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

    public function delete(int $id): bool
    {
        return $this->db->delete(self::TABLE, 'id = :id', ['id' => $id]) > 0;
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
        foreach (['category_id', 'created_by'] as $key) {
            $row[$key] = $row[$key] === null ? null : (int) $row[$key];
        }
        $row['active'] = (bool) $row['active'];
        $row['category_path'] = $row['category_name'] === null ? null : (($row['category_parent_name'] ?? null) !== null ? $row['category_parent_name'] . ' › ' : '') . $row['category_name'];
        return $row;
    }
}
