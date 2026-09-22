<?php

declare(strict_types=1);

namespace Atelier\Modules\Budget;

use Atelier\Persistence\Database;
use Atelier\Support\Clock;

/**
 * Accès aux tables budget_goal (objectifs d'épargne) et budget_saving (registre des économies réalisées).
 */
final class SavingsRepository
{
    public const GOALS = 'budget_goal';
    public const SAVINGS = 'budget_saving';
    public const SAVING_KINDS = ['one_off' => 'Ponctuelle', 'monthly' => 'Par mois', 'yearly' => 'Par an'];

    public function __construct(private readonly Database $db)
    {
    }

    // ----- Objectifs -----

    /** @return list<array<string, mixed>> objectifs avec solde du compte associé */
    public function goals(): array
    {
        $rows = $this->db->select('SELECT g.*, a.name AS account_name, CASE WHEN a.id IS NULL THEN NULL ELSE a.initial_balance + COALESCE((SELECT SUM(t.amount) FROM budget_transaction t WHERE t.account_id = a.id), 0) END AS account_balance FROM ' . self::GOALS . ' g LEFT JOIN budget_account a ON a.id = g.account_id ORDER BY g.due_at ASC, g.id ASC');
        return array_map([$this, 'hydrateGoal'], $rows);
    }

    /** @return array<string, mixed>|null */
    public function findGoal(int $id): ?array
    {
        $row = $this->db->selectOne('SELECT g.*, a.name AS account_name, CASE WHEN a.id IS NULL THEN NULL ELSE a.initial_balance + COALESCE((SELECT SUM(t.amount) FROM budget_transaction t WHERE t.account_id = a.id), 0) END AS account_balance FROM ' . self::GOALS . ' g LEFT JOIN budget_account a ON a.id = g.account_id WHERE g.id = :id', ['id' => $id]);
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

    public function deleteGoal(int $id): bool
    {
        return $this->db->delete(self::GOALS, 'id = :id', ['id' => $id]) > 0;
    }

    // ----- Économies réalisées -----

    /** @return list<array<string, mixed>> */
    public function savings(): array
    {
        $rows = $this->db->select('SELECT s.*, c.name AS category_name FROM ' . self::SAVINGS . ' s LEFT JOIN budget_category c ON c.id = s.category_id ORDER BY s.effective_from DESC, s.id DESC');
        return array_map([$this, 'hydrateSaving'], $rows);
    }

    /** @return array<string, mixed>|null */
    public function findSaving(int $id): ?array
    {
        $row = $this->db->selectOne('SELECT s.*, c.name AS category_name FROM ' . self::SAVINGS . ' s LEFT JOIN budget_category c ON c.id = s.category_id WHERE s.id = :id', ['id' => $id]);
        return $row === null ? null : $this->hydrateSaving($row);
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

    public function deleteSaving(int $id): bool
    {
        return $this->db->delete(self::SAVINGS, 'id = :id', ['id' => $id]) > 0;
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
        return $row;
    }
}
