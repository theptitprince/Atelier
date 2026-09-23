<?php

declare(strict_types=1);

namespace Atelier\Modules\Budget;

use Atelier\Persistence\Database;
use Atelier\Support\Clock;

/**
 * Accès à la table budget_transaction : opérations datées à montant signé (négatif = dépense).
 * Les lignes sont retournées avec le nom du compte et le chemin de la catégorie.
 *
 * Suppression logique : une opération dont deleted_at est renseigné est en corbeille ; une opération
 * dont le compte est en corbeille est masquée (listes, soldes, totaux) sans être elle-même supprimée.
 * Toutes les lectures « métier » appliquent la condition ALIVE ; seules les méthodes de corbeille
 * (findTrashed, trashed, expiredTrashIds) voient les lignes supprimées.
 */
final class TransactionRepository
{
    public const TABLE = 'budget_transaction';
    public const SOURCES = ['manual' => 'Saisie', 'import' => 'Import', 'recurring' => 'Récurrence', 'maintenance' => 'Entretien'];

    private const COLUMNS = 't.id, t.account_id, t.category_id, t.done_at, t.amount, t.label, t.payee, t.notes, t.cleared, t.source, t.source_ref, t.recurring_id, t.import_hash, t.created_by, t.created_at, t.updated_at, t.deleted_at, t.deleted_by, a.name AS account_name, a.deleted_at AS account_deleted_at, c.name AS category_name, c.kind AS category_kind, p.name AS category_parent_name';
    private const FROM = ' FROM budget_transaction t INNER JOIN budget_account a ON a.id = t.account_id LEFT JOIN budget_category c ON c.id = t.category_id LEFT JOIN budget_category p ON p.id = c.parent_id';
    /** Opération vivante sur un compte vivant (alias t et a obligatoires). */
    private const ALIVE = 't.deleted_at IS NULL AND a.deleted_at IS NULL';
    /** Jointure minimale pour les agrégats (pas de catégorie). */
    private const FROM_ALIVE = ' FROM budget_transaction t INNER JOIN budget_account a ON a.id = t.account_id WHERE t.deleted_at IS NULL AND a.deleted_at IS NULL';

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed>|null opération vivante */
    public function find(int $id): ?array
    {
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . self::FROM . ' WHERE t.id = :id AND ' . self::ALIVE, ['id' => $id]);
        return $row === null ? null : $this->hydrate($row);
    }

    /** @return array<string, mixed>|null opération vivante créée par un autre module (référence d'origine) */
    public function findBySourceRef(string $sourceRef): ?array
    {
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . self::FROM . ' WHERE t.source_ref = :r AND ' . self::ALIVE . ' ORDER BY t.id DESC', ['r' => $sourceRef]);
        return $row === null ? null : $this->hydrate($row);
    }

    /** Une empreinte d'import reste connue tant que la ligne existe, corbeille comprise (pas de doublon à la restauration). */
    public function hashExists(string $hash): bool
    {
        return $this->db->scalar('SELECT id FROM ' . self::TABLE . ' WHERE import_hash = :h', ['h' => $hash]) !== null;
    }

    /**
     * @param array{account: int, category: int, month: string, from: string, to: string, q: string, uncleared: bool, source: string} $criteria
     * @return array{rows: list<array<string, mixed>>, total: int, sum: int, income: int, expense: int}
     */
    public function paginate(array $criteria, int $page, int $perPage): array
    {
        [$where, $params] = $this->where($criteria);
        $totals = $this->db->selectOne('SELECT COUNT(*) AS n, COALESCE(SUM(t.amount), 0) AS s, COALESCE(SUM(CASE WHEN t.amount > 0 THEN t.amount ELSE 0 END), 0) AS i, COALESCE(SUM(CASE WHEN t.amount < 0 THEN t.amount ELSE 0 END), 0) AS e' . self::FROM . " WHERE $where", $params) ?? ['n' => 0, 's' => 0, 'i' => 0, 'e' => 0];
        $perPage = max(1, min(500, $perPage));
        $offset = max(0, ($page - 1) * $perPage);
        $rows = $this->db->select('SELECT ' . self::COLUMNS . self::FROM . " WHERE $where ORDER BY t.done_at DESC, t.id DESC LIMIT $perPage OFFSET $offset", $params);
        return ['rows' => array_map([$this, 'hydrate'], $rows), 'total' => (int) $totals['n'], 'sum' => (int) $totals['s'], 'income' => (int) $totals['i'], 'expense' => (int) $totals['e']];
    }

    /** @param array<string, mixed> $criteria @return list<array<string, mixed>> */
    public function export(array $criteria, int $limit = 20000): array
    {
        [$where, $params] = $this->where($criteria);
        return array_map([$this, 'hydrate'], $this->db->select('SELECT ' . self::COLUMNS . self::FROM . " WHERE $where ORDER BY t.done_at DESC, t.id DESC LIMIT " . max(1, $limit), $params));
    }

    /** @return list<array<string, mixed>> dernières opérations */
    public function recent(int $limit = 10): array
    {
        return array_map([$this, 'hydrate'], $this->db->select('SELECT ' . self::COLUMNS . self::FROM . ' WHERE ' . self::ALIVE . ' ORDER BY t.done_at DESC, t.id DESC LIMIT ' . max(1, $limit)));
    }

    /**
     * Totaux par catégorie entre deux jours inclus.
     *
     * @return array<int, int> category_id (0 = sans catégorie) => somme signée
     */
    public function sumByCategory(string $from, string $to): array
    {
        $result = [];
        foreach ($this->db->select('SELECT COALESCE(t.category_id, 0) AS cid, SUM(t.amount) AS s' . self::FROM_ALIVE . ' AND t.done_at >= :f AND t.done_at <= :t GROUP BY COALESCE(t.category_id, 0)', ['f' => $from, 't' => $to]) as $row) {
            $result[(int) $row['cid']] = (int) $row['s'];
        }
        return $result;
    }

    /**
     * Totaux par mois et par catégorie sur une période (vue annuelle des économies).
     *
     * @return array<string, array<int, int>> mois => (category_id => somme)
     */
    public function sumByMonthAndCategory(string $from, string $to): array
    {
        $result = [];
        foreach ($this->db->select('SELECT SUBSTR(t.done_at, 1, 7) AS m, COALESCE(t.category_id, 0) AS cid, SUM(t.amount) AS s' . self::FROM_ALIVE . ' AND t.done_at >= :f AND t.done_at <= :t GROUP BY SUBSTR(t.done_at, 1, 7), COALESCE(t.category_id, 0)', ['f' => $from, 't' => $to]) as $row) {
            $result[(string) $row['m']][(int) $row['cid']] = (int) $row['s'];
        }
        return $result;
    }

    /** @return array{income: int, expense: int} totaux d'une période */
    public function totals(string $from, string $to): array
    {
        $row = $this->db->selectOne('SELECT COALESCE(SUM(CASE WHEN t.amount > 0 THEN t.amount ELSE 0 END), 0) AS i, COALESCE(SUM(CASE WHEN t.amount < 0 THEN t.amount ELSE 0 END), 0) AS e' . self::FROM_ALIVE . ' AND t.done_at >= :f AND t.done_at <= :t', ['f' => $from, 't' => $to]);
        return ['income' => (int) ($row['i'] ?? 0), 'expense' => (int) ($row['e'] ?? 0)];
    }

    /** Catégorie de la dernière opération vivante portant le même libellé (auto-catégorisation). */
    public function guessCategory(string $label): ?int
    {
        $value = $this->db->scalar('SELECT category_id FROM ' . self::TABLE . ' WHERE ' . $this->db->lower('label') . ' = :l AND category_id IS NOT NULL AND deleted_at IS NULL ORDER BY done_at DESC, id DESC LIMIT 1', ['l' => mb_strtolower(trim($label), 'UTF-8')]);
        return $value === null ? null : (int) $value;
    }

    /** @return list<int> années présentes, décroissantes */
    public function years(): array
    {
        $rows = $this->db->select('SELECT DISTINCT SUBSTR(t.done_at, 1, 4) AS y' . self::FROM_ALIVE . ' ORDER BY y DESC');
        return array_values(array_filter(array_map(static fn (array $r): int => (int) $r['y'], $rows), static fn (int $y): bool => $y > 0));
    }

    /** Nombre d'opérations vivantes. */
    public function count(): int
    {
        return $this->db->count('SELECT COUNT(*)' . self::FROM_ALIVE);
    }

    /** Opérations rattachées à une catégorie, corbeille comprise (garde-fou de suppression). */
    public function countForCategory(int $categoryId): int
    {
        return $this->db->count('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE category_id = :c', ['c' => $categoryId]);
    }

    /** Opérations vivantes d'un compte (masquées si le compte passe en corbeille). */
    public function countForAccount(int $accountId): int
    {
        return $this->db->count('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE account_id = :a AND deleted_at IS NULL', ['a' => $accountId]);
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

    public function setCleared(int $id, bool $cleared): void
    {
        $this->db->update(self::TABLE, ['cleared' => $cleared, 'updated_at' => Clock::utc()], 'id = :id', ['id' => $id]);
    }

    /** @param list<int> $ids */
    public function bulkUpdate(array $ids, array $data): int
    {
        if ($ids === []) {
            return 0;
        }
        [$in, $params] = $this->inClause($ids);
        $sets = [];
        foreach ($data as $column => $value) {
            $sets[] = $this->db->quoteIdentifier($column) . ' = :set_' . $column;
            $params['set_' . $column] = $value;
        }
        $params['set_updated_at'] = Clock::utc();
        $sets[] = 'updated_at = :set_updated_at';
        return $this->db->execute('UPDATE ' . self::TABLE . ' SET ' . implode(', ', $sets) . ' WHERE deleted_at IS NULL AND id IN (' . $in . ')', $params);
    }

    public function detachCategory(int $categoryId): int
    {
        return $this->db->update(self::TABLE, ['category_id' => null, 'updated_at' => Clock::utc()], 'category_id = :c', ['c' => $categoryId]);
    }

    // ----- Corbeille -----

    /** Mise en corbeille d'une opération vivante. */
    public function softDelete(int $id, ?int $userId): bool
    {
        return $this->db->update(self::TABLE, ['deleted_at' => Clock::utc(), 'deleted_by' => $userId], 'id = :id AND deleted_at IS NULL', ['id' => $id]) > 0;
    }

    /** @param list<int> $ids @return int nombre d'opérations placées en corbeille */
    public function softDeleteMany(array $ids, ?int $userId): int
    {
        if ($ids === []) {
            return 0;
        }
        [$in, $params] = $this->inClause($ids);
        $params['deleted_at'] = Clock::utc();
        $params['deleted_by'] = $userId;
        return $this->db->execute('UPDATE ' . self::TABLE . ' SET deleted_at = :deleted_at, deleted_by = :deleted_by WHERE deleted_at IS NULL AND id IN (' . $in . ')', $params);
    }

    public function restore(int $id): bool
    {
        return $this->db->update(self::TABLE, ['deleted_at' => null, 'deleted_by' => null, 'updated_at' => Clock::utc()], 'id = :id AND deleted_at IS NOT NULL', ['id' => $id]) > 0;
    }

    /** Suppression physique d'une opération en corbeille. */
    public function purge(int $id): bool
    {
        return $this->db->delete(self::TABLE, 'id = :id AND deleted_at IS NOT NULL', ['id' => $id]) > 0;
    }

    /** @return list<int> identifiants de toutes les opérations d'un compte (purge physique du compte) */
    public function idsForAccount(int $accountId): array
    {
        return array_map(static fn (array $r): int => (int) $r['id'], $this->db->select('SELECT id FROM ' . self::TABLE . ' WHERE account_id = :a', ['a' => $accountId]));
    }

    /** Suppression physique de toutes les opérations d'un compte (purge du compte). */
    public function deleteForAccount(int $accountId): int
    {
        return $this->db->delete(self::TABLE, 'account_id = :a', ['a' => $accountId]);
    }

    /** @return array<string, mixed>|null opération en corbeille (son compte peut l'être aussi) */
    public function findTrashed(int $id): ?array
    {
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . self::FROM . ' WHERE t.id = :id AND t.deleted_at IS NOT NULL', ['id' => $id]);
        return $row === null ? null : $this->hydrate($row);
    }

    /** @return list<array<string, mixed>> opérations en corbeille depuis moins de $retentionDays jours */
    public function trashed(int $retentionDays): array
    {
        $limit = Clock::utc(Clock::now()->modify('-' . $retentionDays . ' days'));
        return array_map([$this, 'hydrate'], $this->db->select('SELECT ' . self::COLUMNS . self::FROM . ' WHERE t.deleted_at IS NOT NULL AND t.deleted_at >= :l ORDER BY t.deleted_at DESC, t.id DESC', ['l' => $limit]));
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
            'done_at' => (string) $data['done_at'],
            'amount' => (int) $data['amount'],
            'label' => (string) $data['label'],
            'payee' => $string('payee'),
            'notes' => $string('notes'),
            'cleared' => (bool) ($data['cleared'] ?? false),
            'source' => (string) ($data['source'] ?? 'manual'),
            'source_ref' => $string('source_ref'),
            'recurring_id' => isset($data['recurring_id']) && (int) $data['recurring_id'] > 0 ? (int) $data['recurring_id'] : null,
            'import_hash' => $string('import_hash'),
        ];
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function where(array $criteria): array
    {
        $where = [self::ALIVE];
        $params = [];
        if (($criteria['account'] ?? 0) > 0) {
            $where[] = 't.account_id = :account';
            $params['account'] = (int) $criteria['account'];
        }
        if (($criteria['category'] ?? 0) > 0) {
            $where[] = '(t.category_id = :category OR c.parent_id = :category)';
            $params['category'] = (int) $criteria['category'];
        } elseif (($criteria['category'] ?? 0) === -1) {
            $where[] = 't.category_id IS NULL';
        }
        if (($criteria['month'] ?? '') !== '') {
            [$from, $to] = Period::monthRange((string) $criteria['month']);
            $where[] = 't.done_at >= :mf AND t.done_at <= :mt';
            $params['mf'] = $from;
            $params['mt'] = $to;
        }
        if (($criteria['from'] ?? '') !== '') {
            $where[] = 't.done_at >= :from';
            $params['from'] = (string) $criteria['from'];
        }
        if (($criteria['to'] ?? '') !== '') {
            $where[] = 't.done_at <= :to';
            $params['to'] = (string) $criteria['to'];
        }
        if (!empty($criteria['uncleared'])) {
            $where[] = 't.cleared = 0';
        }
        if (($criteria['source'] ?? '') !== '' && isset(self::SOURCES[$criteria['source']])) {
            $where[] = 't.source = :source';
            $params['source'] = (string) $criteria['source'];
        }
        $q = trim((string) ($criteria['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(' . $this->db->lower('t.label') . ' LIKE :q OR ' . $this->db->lower("COALESCE(t.payee, '')") . ' LIKE :q OR ' . $this->db->lower("COALESCE(t.notes, '')") . ' LIKE :q)';
            $params['q'] = '%' . mb_strtolower($q, 'UTF-8') . '%';
        }
        return [implode(' AND ', $where), $params];
    }

    /** @param list<int> $ids @return array{0: string, 1: array<string, mixed>} */
    private function inClause(array $ids): array
    {
        $placeholders = [];
        $params = [];
        foreach (array_values(array_unique($ids)) as $i => $id) {
            $placeholders[] = ':id' . $i;
            $params['id' . $i] = (int) $id;
        }
        return [implode(', ', $placeholders), $params];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function hydrate(array $row): array
    {
        foreach (['id', 'account_id', 'amount'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        foreach (['category_id', 'recurring_id', 'created_by', 'deleted_by'] as $key) {
            $row[$key] = $row[$key] === null ? null : (int) $row[$key];
        }
        $row['cleared'] = (bool) $row['cleared'];
        $row['category_path'] = $row['category_name'] === null ? null : (($row['category_parent_name'] ?? null) !== null ? $row['category_parent_name'] . ' › ' : '') . $row['category_name'];
        return $row;
    }
}
