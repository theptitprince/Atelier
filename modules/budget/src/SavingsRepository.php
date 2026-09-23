<?php

declare(strict_types=1);

namespace Atelier\Modules\Budget;

use Atelier\Persistence\Database;
use Atelier\Support\Clock;

/**
 * Accès aux tables budget_goal (objectifs d'épargne) et budget_saving (registre des économies réalisées).
 * Les deux tables ont une corbeille (deleted_at) ; un objectif lié à un compte en corbeille repasse en
 * progression manuelle tant que le compte n'est pas restauré.
 */
final class SavingsRepository
{
    public const GOALS = 'budget_goal';
    public const SAVINGS = 'budget_saving';
    public const SAVING_KINDS = ['one_off' => 'Ponctuelle', 'monthly' => 'Par mois', 'yearly' => 'Par an'];

    private const GOAL_SELECT = 'SELECT g.*, a.name AS account_name, CASE WHEN a.id IS NULL THEN NULL ELSE a.initial_balance + COALESCE((SELECT SUM(t.amount) FROM budget_transaction t WHERE t.account_id = a.id AND t.deleted_at IS NULL), 0) END AS account_balance FROM budget_goal g LEFT JOIN budget_account a ON a.id = g.account_id AND a.deleted_at IS NULL';
    private const SAVING_SELECT = 'SELECT s.*, c.name AS category_name FROM budget_saving s LEFT JOIN budget_category c ON c.id = s.category_id';

    public function __construct(private readonly Database $db)
    {
    }

    // ----- Objectifs -----

    /** @return list<array<string, mixed>> objectifs vivants avec solde du compte associé */
    public function goals(): array
    {
        return array_map([$this, 'hydrateGoal'], $this->db->select(self::GOAL_SELECT . ' WHERE g.deleted_at IS NULL ORDER BY g.due_at ASC, g.id ASC'));
    }

    /** @return array<string, mixed>|null objectif vivant */
    public function findGoal(int $id): ?array
    {
        $row = $this->db->selectOne(self::GOAL_SELECT . ' WHERE g.id = :id AND g.deleted_at IS NULL', ['id' => $id]);
        return $row === null ? null : $this->hydrateGoal($row);
    }

    /** @param array<string, mixed> $data */
    public function createGoal(array $data, ?int $userId): int
    {
        $now = Clock::utc();
        return $this->db->insert(self::GOALS, $this->goalColumns($data) + ['created_by' => $userId, 'created_at' => $now, 'updated_at' => $now]);
    }

    /** @param array<string, mixed> $data */
    public function updateGoal(int $id, array $data): void
    {
        $this->db->update(self::GOALS, $this->goalColumns($data) + ['updated_at' => Clock::utc()], 'id = :id', ['id' => $id]);
    }

    public function softDeleteGoal(int $id, ?int $userId): bool
    {
        return $this->db->update(self::GOALS, ['deleted_at' => Clock::utc(), 'deleted_by' => $userId], 'id = :id AND deleted_at IS NULL', ['id' => $id]) > 0;
    }

    public function restoreGoal(int $id): bool
    {
        return $this->db->update(self::GOALS, ['deleted_at' => null, 'deleted_by' => null, 'updated_at' => Clock::utc()], 'id = :id AND deleted_at IS NOT NULL', ['id' => $id]) > 0;
    }

    /** Suppression physique d'un objectif en corbeille. */
    public function purgeGoal(int $id): bool
    {
        return $this->db->delete(self::GOALS, 'id = :id AND deleted_at IS NOT NULL', ['id' => $id]) > 0;
    }

    /** @return array<string, mixed>|null */
    public function findTrashedGoal(int $id): ?array
    {
        $row = $this->db->selectOne(self::GOAL_SELECT . ' WHERE g.id = :id AND g.deleted_at IS NOT NULL', ['id' => $id]);
        return $row === null ? null : $this->hydrateGoal($row);
    }

    /** @return list<array<string, mixed>> */
    public function trashedGoals(int $retentionDays): array
    {
        $limit = Clock::utc(Clock::now()->modify('-' . $retentionDays . ' days'));
        return array_map([$this, 'hydrateGoal'], $this->db->select(self::GOAL_SELECT . ' WHERE g.deleted_at IS NOT NULL AND g.deleted_at >= :l ORDER BY g.deleted_at DESC, g.id DESC', ['l' => $limit]));
    }

    /** @return list<int> */
    public function expiredTrashGoalIds(int $retentionDays): array
    {
        return $this->expiredIds(self::GOALS, $retentionDays);
    }

    /** Détache les objectifs d'un compte purgé (ils repassent en progression manuelle). */
    public function detachAccount(int $accountId): int
    {
        return $this->db->update(self::GOALS, ['account_id' => null, 'updated_at' => Clock::utc()], 'account_id = :a', ['a' => $accountId]);
    }

    // ----- Économies réalisées -----

    /** @return list<array<string, mixed>> économies vivantes */
    public function savings(): array
    {
        return array_map([$this, 'hydrateSaving'], $this->db->select(self::SAVING_SELECT . ' WHERE s.deleted_at IS NULL ORDER BY s.effective_from DESC, s.id DESC'));
    }

    /** @return array<string, mixed>|null économie vivante */
    public function findSaving(int $id): ?array
    {
        $row = $this->db->selectOne(self::SAVING_SELECT . ' WHERE s.id = :id AND s.deleted_at IS NULL', ['id' => $id]);
        return $row === null ? null : $this->hydrateSaving($row);
    }

    /** Économies rattachées à une catégorie, corbeille comprise (garde-fou de suppression). */
    public function countSavingsForCategory(int $categoryId): int
    {
        return $this->db->count('SELECT COUNT(*) FROM ' . self::SAVINGS . ' WHERE category_id = :c', ['c' => $categoryId]);
    }

    /** @param array<string, mixed> $data */
    public function createSaving(array $data, ?int $userId): int
    {
        $now = Clock::utc();
        return $this->db->insert(self::SAVINGS, $this->savingColumns($data) + ['created_by' => $userId, 'created_at' => $now, 'updated_at' => $now]);
    }

    /** @param array<string, mixed> $data */
    public function updateSaving(int $id, array $data): void
    {
        $this->db->update(self::SAVINGS, $this->savingColumns($data) + ['updated_at' => Clock::utc()], 'id = :id', ['id' => $id]);
    }

    public function softDeleteSaving(int $id, ?int $userId): bool
    {
        return $this->db->update(self::SAVINGS, ['deleted_at' => Clock::utc(), 'deleted_by' => $userId], 'id = :id AND deleted_at IS NULL', ['id' => $id]) > 0;
    }

    public function restoreSaving(int $id): bool
    {
        return $this->db->update(self::SAVINGS, ['deleted_at' => null, 'deleted_by' => null, 'updated_at' => Clock::utc()], 'id = :id AND deleted_at IS NOT NULL', ['id' => $id]) > 0;
    }

    /** Suppression physique d'une économie en corbeille. */
    public function purgeSaving(int $id): bool
    {
        return $this->db->delete(self::SAVINGS, 'id = :id AND deleted_at IS NOT NULL', ['id' => $id]) > 0;
    }

    /** @return array<string, mixed>|null */
    public function findTrashedSaving(int $id): ?array
    {
        $row = $this->db->selectOne(self::SAVING_SELECT . ' WHERE s.id = :id AND s.deleted_at IS NOT NULL', ['id' => $id]);
        return $row === null ? null : $this->hydrateSaving($row);
    }

    /** @return list<array<string, mixed>> */
    public function trashedSavings(int $retentionDays): array
    {
        $limit = Clock::utc(Clock::now()->modify('-' . $retentionDays . ' days'));
        return array_map([$this, 'hydrateSaving'], $this->db->select(self::SAVING_SELECT . ' WHERE s.deleted_at IS NOT NULL AND s.deleted_at >= :l ORDER BY s.deleted_at DESC, s.id DESC', ['l' => $limit]));
    }

    /** @return list<int> */
    public function expiredTrashSavingIds(int $retentionDays): array
    {
        return $this->expiredIds(self::SAVINGS, $retentionDays);
    }

    /**
     * Gain cumulé d'une économie enregistrée entre deux jours inclus (par exemple sur une année civile).
     *
     * @param array<string, mixed> $saving kind, amount, effective_from, effective_to
     */
    public static function realized(array $saving, string $from, string $to): int
    {
        $start = max((string) $saving['effective_from'], $from);
        $end = $saving['effective_to'] !== null ? min((string) $saving['effective_to'], $to) : $to;
        if ($start > $end) {
            return 0;
        }
        return match ((string) $saving['kind']) {
            'one_off' => ((string) $saving['effective_from'] >= $from && (string) $saving['effective_from'] <= $to) ? (int) $saving['amount'] : 0,
            'monthly' => (int) $saving['amount'] * (Period::monthsBetween(Period::monthOf($start), Period::monthOf($end)) + 1),
            'yearly' => (int) round((int) $saving['amount'] / 12 * (Period::monthsBetween(Period::monthOf($start), Period::monthOf($end)) + 1)),
            default => 0,
        };
    }

    /** @return list<int> */
    private function expiredIds(string $table, int $retentionDays): array
    {
        $limit = Clock::utc(Clock::now()->modify('-' . $retentionDays . ' days'));
        return array_map(static fn (array $r): int => (int) $r['id'], $this->db->select('SELECT id FROM ' . $table . ' WHERE deleted_at IS NOT NULL AND deleted_at < :l', ['l' => $limit]));
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function goalColumns(array $data): array
    {
        return [
            'name' => (string) $data['name'],
            'target' => (int) $data['target'],
            'current' => (int) ($data['current'] ?? 0),
            'account_id' => isset($data['account_id']) && (int) $data['account_id'] > 0 ? (int) $data['account_id'] : null,
            'due_at' => isset($data['due_at']) && $data['due_at'] !== '' ? (string) $data['due_at'] : null,
            'notes' => isset($data['notes']) && $data['notes'] !== '' ? (string) $data['notes'] : null,
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function savingColumns(array $data): array
    {
        return [
            'label' => (string) $data['label'],
            'kind' => (string) $data['kind'],
            'amount' => (int) $data['amount'],
            'effective_from' => (string) $data['effective_from'],
            'effective_to' => isset($data['effective_to']) && $data['effective_to'] !== '' ? (string) $data['effective_to'] : null,
            'category_id' => isset($data['category_id']) && (int) $data['category_id'] > 0 ? (int) $data['category_id'] : null,
            'notes' => isset($data['notes']) && $data['notes'] !== '' ? (string) $data['notes'] : null,
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function hydrateGoal(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['target'] = (int) $row['target'];
        $row['current'] = (int) $row['current'];
        $row['account_id'] = $row['account_id'] === null ? null : (int) $row['account_id'];
        $row['account_balance'] = $row['account_balance'] === null ? null : (int) $row['account_balance'];
        $row['deleted_by'] = ($row['deleted_by'] ?? null) === null ? null : (int) $row['deleted_by'];
        $row['progress'] = $row['account_balance'] ?? $row['current'];
        $row['percent'] = $row['target'] > 0 ? (int) min(100, max(0, round($row['progress'] * 100 / $row['target']))) : 0;
        $row['reached'] = $row['target'] > 0 && $row['progress'] >= $row['target'];
        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function hydrateSaving(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['amount'] = (int) $row['amount'];
        $row['category_id'] = $row['category_id'] === null ? null : (int) $row['category_id'];
        $row['deleted_by'] = ($row['deleted_by'] ?? null) === null ? null : (int) $row['deleted_by'];
        return $row;
    }
}
