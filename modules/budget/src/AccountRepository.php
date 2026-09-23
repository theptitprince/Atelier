<?php

declare(strict_types=1);

namespace Atelier\Modules\Budget;

use Atelier\Persistence\Database;
use Atelier\Support\Clock;

/**
 * Accès à la table budget_account : comptes et soldes calculés (solde initial + opérations vivantes).
 * Un compte en corbeille (deleted_at) disparaît des listes et des soldes et masque ses opérations.
 */
final class AccountRepository
{
    public const TABLE = 'budget_account';
    public const KINDS = ['checking' => 'Compte courant', 'savings' => 'Épargne', 'cash' => 'Espèces', 'other' => 'Autre'];

    private const COLUMNS = 'a.id, a.name, a.kind, a.initial_balance, a.opened_at, a.notes, a.archived, a.sort_order, a.created_by, a.created_at, a.updated_at, a.deleted_at, a.deleted_by';
    private const BALANCES = ', COALESCE((SELECT SUM(t.amount) FROM budget_transaction t WHERE t.account_id = a.id AND t.deleted_at IS NULL), 0) AS movements, COALESCE((SELECT SUM(t.amount) FROM budget_transaction t WHERE t.account_id = a.id AND t.cleared = 1 AND t.deleted_at IS NULL), 0) AS cleared_movements, (SELECT COUNT(*) FROM budget_transaction t WHERE t.account_id = a.id AND t.deleted_at IS NULL) AS transaction_count';

    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string, mixed>> comptes vivants (actifs seulement par défaut) avec soldes */
    public function all(bool $includeArchived = false): array
    {
        $sql = 'SELECT ' . self::COLUMNS . self::BALANCES . ' FROM ' . self::TABLE . ' a WHERE a.deleted_at IS NULL' . ($includeArchived ? '' : ' AND a.archived = 0') . ' ORDER BY a.archived ASC, a.sort_order ASC, a.name ASC';
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

    /** @return array<string, mixed>|null compte vivant */
    public function find(int $id): ?array
    {
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . self::BALANCES . ' FROM ' . self::TABLE . ' a WHERE a.id = :id AND a.deleted_at IS NULL', ['id' => $id]);
        return $row === null ? null : $this->hydrate($row);
    }

    /** Premier compte actif, de préférence un compte courant. */
    public function findDefault(): ?array
    {
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . self::BALANCES . ' FROM ' . self::TABLE . " a WHERE a.archived = 0 AND a.deleted_at IS NULL ORDER BY CASE a.kind WHEN 'checking' THEN 0 ELSE 1 END, a.sort_order ASC, a.id ASC");
        return $row === null ? null : $this->hydrate($row);
    }

    /** Nombre de comptes vivants. */
    public function count(): int
    {
        return $this->db->count('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE deleted_at IS NULL');
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

    // ----- Corbeille -----

    public function softDelete(int $id, ?int $userId): bool
    {
        return $this->db->update(self::TABLE, ['deleted_at' => Clock::utc(), 'deleted_by' => $userId], 'id = :id AND deleted_at IS NULL', ['id' => $id]) > 0;
    }

    public function restore(int $id): bool
    {
        return $this->db->update(self::TABLE, ['deleted_at' => null, 'deleted_by' => null, 'updated_at' => Clock::utc()], 'id = :id AND deleted_at IS NOT NULL', ['id' => $id]) > 0;
    }

    /** Suppression physique d'un compte en corbeille (ses opérations sont purgées par le module). */
    public function purge(int $id): bool
    {
        return $this->db->delete(self::TABLE, 'id = :id AND deleted_at IS NOT NULL', ['id' => $id]) > 0;
    }

    /** @return array<string, mixed>|null compte en corbeille, avec le nombre d'opérations masquées */
    public function findTrashed(int $id): ?array
    {
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . self::BALANCES . ' FROM ' . self::TABLE . ' a WHERE a.id = :id AND a.deleted_at IS NOT NULL', ['id' => $id]);
        return $row === null ? null : $this->hydrate($row);
    }

    /** @return list<array<string, mixed>> comptes en corbeille depuis moins de $retentionDays jours */
    public function trashed(int $retentionDays): array
    {
        $limit = Clock::utc(Clock::now()->modify('-' . $retentionDays . ' days'));
        return array_map([$this, 'hydrate'], $this->db->select('SELECT ' . self::COLUMNS . self::BALANCES . ' FROM ' . self::TABLE . ' a WHERE a.deleted_at IS NOT NULL AND a.deleted_at >= :l ORDER BY a.deleted_at DESC, a.id DESC', ['l' => $limit]));
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
        $row['deleted_by'] = ($row['deleted_by'] ?? null) === null ? null : (int) $row['deleted_by'];
        return $row;
    }
}
