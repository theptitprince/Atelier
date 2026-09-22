<?php

declare(strict_types=1);

namespace Atelier\Modules\Budget;

use Atelier\Persistence\Database;
use Atelier\Support\Clock;

/** Accès à la table budget_account : comptes et soldes calculés (solde initial + opérations). */
final class AccountRepository
{
    public const TABLE = 'budget_account';
    public const KINDS = ['checking' => 'Compte courant', 'savings' => 'Épargne', 'cash' => 'Espèces', 'other' => 'Autre'];

    private const COLUMNS = 'a.id, a.name, a.kind, a.initial_balance, a.opened_at, a.notes, a.archived, a.sort_order, a.created_by, a.created_at, a.updated_at';
    private const BALANCES = ', COALESCE((SELECT SUM(t.amount) FROM budget_transaction t WHERE t.account_id = a.id), 0) AS movements, COALESCE((SELECT SUM(t.amount) FROM budget_transaction t WHERE t.account_id = a.id AND t.cleared = 1), 0) AS cleared_movements, (SELECT COUNT(*) FROM budget_transaction t WHERE t.account_id = a.id) AS transaction_count';

    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string, mixed>> comptes (actifs seulement par défaut) avec soldes */
    public function all(bool $includeArchived = false): array
    {
        $sql = 'SELECT ' . self::COLUMNS . self::BALANCES . ' FROM ' . self::TABLE . ' a' . ($includeArchived ? '' : ' WHERE a.archived = 0') . ' ORDER BY a.archived ASC, a.sort_order ASC, a.name ASC';
        return array_map([$this, 'hydrate'], $this->db->select($sql));
    }

    /** @return array<int, array<string, mixed>> */
    public function allById(bool $includeArchived = true): array
    {
        $result = [];
        foreach ($this->all($includeArchived) as $row) {
            $result[$row['id']] = $row;
        }
        return $result;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . self::BALANCES . ' FROM ' . self::TABLE . ' a WHERE a.id = :id', ['id' => $id]);
        return $row === null ? null : $this->hydrate($row);
    }

    /** Premier compte actif, de préférence un compte courant. */
    public function findDefault(): ?array
    {
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . self::BALANCES . ' FROM ' . self::TABLE . " a WHERE a.archived = 0 ORDER BY CASE a.kind WHEN 'checking' THEN 0 ELSE 1 END, a.sort_order ASC, a.id ASC");
        return $row === null ? null : $this->hydrate($row);
    }

    public function count(): int
    {
        return $this->db->count('SELECT COUNT(*) FROM ' . self::TABLE);
    }

    /** Solde total des comptes actifs (centimes). */
    public function totalBalance(): int
    {
        $total = 0;
        foreach ($this->all() as $account) {
            $total += $account['balance'];
        }
        return $total;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, ?int $userId): int
    {
        $now = Clock::utc();
        return $this->db->insert(self::TABLE, $this->columns($data) + ['archived' => false, 'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now]);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->db->update(self::TABLE, $this->columns($data) + ['updated_at' => Clock::utc()], 'id = :id', ['id' => $id]);
    }

    public function setArchived(int $id, bool $archived): void
    {
        $this->db->update(self::TABLE, ['archived' => $archived, 'updated_at' => Clock::utc()], 'id = :id', ['id' => $id]);
    }

    public function delete(int $id): bool
    {
        return $this->db->delete(self::TABLE, 'id = :id', ['id' => $id]) > 0;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function columns(array $data): array
    {
        return [
            'name' => (string) $data['name'],
            'kind' => (string) $data['kind'],
            'initial_balance' => (int) ($data['initial_balance'] ?? 0),
            'opened_at' => isset($data['opened_at']) && $data['opened_at'] !== '' ? (string) $data['opened_at'] : null,
            'notes' => isset($data['notes']) && $data['notes'] !== '' ? (string) $data['notes'] : null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['initial_balance'] = (int) $row['initial_balance'];
        $row['archived'] = (bool) $row['archived'];
        $row['sort_order'] = (int) $row['sort_order'];
        $row['transaction_count'] = (int) ($row['transaction_count'] ?? 0);
        $row['balance'] = $row['initial_balance'] + (int) ($row['movements'] ?? 0);
        $row['cleared_balance'] = $row['initial_balance'] + (int) ($row['cleared_movements'] ?? 0);
        $row['created_by'] = $row['created_by'] === null ? null : (int) $row['created_by'];
        return $row;
    }
}
