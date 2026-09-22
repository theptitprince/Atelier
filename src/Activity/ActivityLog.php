<?php

declare(strict_types=1);

namespace Atelier\Activity;

use Atelier\Persistence\Database;
use Atelier\Support\Clock;
use Atelier\Support\Json;
use Throwable;

/**
 * Journal d'activité fonctionnel et de sécurité (base de données), distinct du journal technique.
 *
 * Les entrées ne sont ni modifiables ni supprimables depuis l'interface ; seule la purge de
 * rétention (12 mois par défaut) les retire.
 */
final class ActivityLog
{
    public const SUCCESS = 'success';
    public const FAILURE = 'failure';
    public const DENIED = 'denied';
    public const ERROR = 'error';

    /** Clés dont la valeur est toujours masquée dans les détails. */
    private const SENSITIVE_KEYS = ['password', 'password_hash', 'new_password', 'old_password', 'token', '_token', 'secret', 'api_key', 'session', 'content'];

    private ?int $userId = null;
    private ?string $username = null;
    private ?string $ip = null;

    public function __construct(private readonly Database $db)
    {
    }

    /** Contexte de la requête courante, appliqué à toutes les entrées suivantes. */
    public function setContext(?int $userId, ?string $username, ?string $ip): void
    {
        $this->userId = $userId;
        $this->username = $username;
        $this->ip = $ip;
    }

    /**
     * @param array<string, mixed> $details
     */
    public function record(string $moduleId, string $action, string $result = self::SUCCESS, ?string $resourceRef = null, ?string $message = null, array $details = [], ?string $errorId = null): void
    {
        try {
            $this->db->insert('activity_log', [
                'occurred_at' => Clock::utc(),
                'user_id' => $this->userId,
                'username' => $this->username,
                'module_id' => $moduleId,
                'action' => $action,
                'result' => $result,
                'resource_ref' => $resourceRef,
                'message' => $message,
                'details' => $details === [] ? null : Json::encode($this->sanitize($details)),
                'ip' => $this->ip,
                'error_id' => $errorId,
            ]);
        } catch (Throwable) {
            // Le journal ne doit jamais interrompre l'action métier ; le journal technique prend le relais.
        }
    }

    /** @param array<string, mixed> $details */
    public function success(string $moduleId, string $action, ?string $resourceRef = null, ?string $message = null, array $details = []): void
    {
        $this->record($moduleId, $action, self::SUCCESS, $resourceRef, $message, $details);
    }

    /** @param array<string, mixed> $details */
    public function failure(string $moduleId, string $action, ?string $resourceRef = null, ?string $message = null, array $details = []): void
    {
        $this->record($moduleId, $action, self::FAILURE, $resourceRef, $message, $details);
    }

    /** @param array<string, mixed> $details */
    public function denied(string $moduleId, string $action, ?string $resourceRef = null, ?string $message = null, array $details = []): void
    {
        $this->record($moduleId, $action, self::DENIED, $resourceRef, $message, $details);
    }

    /**
     * Consultation paginée côté serveur avec filtres cumulables et tri.
     *
     * @param array<string, mixed> $filters from, to, user_id, module_id, action, result, resource_ref, search
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function paginate(array $filters, int $page, int $perPage, string $sort = 'occurred_at', string $direction = 'desc'): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (!empty($filters['from'])) {
            $where[] = 'occurred_at >= :from';
            $params['from'] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'occurred_at <= :to';
            $params['to'] = $filters['to'];
        }
        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = :user_id';
            $params['user_id'] = (int) $filters['user_id'];
        }
        if (!empty($filters['module_id'])) {
            $where[] = 'module_id = :module_id';
            $params['module_id'] = $filters['module_id'];
        }
        if (!empty($filters['action'])) {
            $where[] = 'action = :action';
            $params['action'] = $filters['action'];
        }
        if (!empty($filters['result'])) {
            $where[] = 'result = :result';
            $params['result'] = $filters['result'];
        }
        if (!empty($filters['resource_ref'])) {
            $where[] = 'resource_ref LIKE :resource_ref';
            $params['resource_ref'] = '%' . $filters['resource_ref'] . '%';
        }
        if (!empty($filters['search'])) {
            $where[] = '(' . $this->db->lower('message') . ' LIKE :search OR ' . $this->db->lower('resource_ref') . ' LIKE :search OR ' . $this->db->lower('username') . ' LIKE :search OR error_id LIKE :search_raw)';
            $params['search'] = '%' . mb_strtolower((string) $filters['search'], 'UTF-8') . '%';
            $params['search_raw'] = '%' . $filters['search'] . '%';
        }
        $sorts = ['occurred_at', 'username', 'module_id', 'action', 'result'];
        $orderBy = (in_array($sort, $sorts, true) ? $sort : 'occurred_at') . ' ' . (strtolower($direction) === 'asc' ? 'ASC' : 'DESC');
        $whereSql = implode(' AND ', $where);
        $total = $this->db->count("SELECT COUNT(*) FROM activity_log WHERE $whereSql", $params);
        $offset = max(0, ($page - 1) * $perPage);
        $rows = $this->db->select("SELECT * FROM activity_log WHERE $whereSql ORDER BY $orderBy, id DESC LIMIT $perPage OFFSET $offset", $params);
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Export complet (limité) selon les mêmes filtres.
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function export(array $filters, int $limit = 10000): array
    {
        return $this->paginate($filters, 1, $limit)['rows'];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM activity_log WHERE id = :id', ['id' => $id]);
    }

    /** @return list<string> */
    public function distinctActions(): array
    {
        return array_map(static fn (array $r): string => (string) $r['action'], $this->db->select('SELECT DISTINCT action FROM activity_log ORDER BY action'));
    }

    /** @return list<string> */
    public function distinctModules(): array
    {
        return array_map(static fn (array $r): string => (string) $r['module_id'], $this->db->select('SELECT DISTINCT module_id FROM activity_log ORDER BY module_id'));
    }

    /** Purge des entrées plus anciennes que la rétention. */
    public function purge(int $retentionMonths): int
    {
        $limit = Clock::now()->modify("-{$retentionMonths} months");
        return $this->db->delete('activity_log', 'occurred_at < :limit', ['limit' => Clock::utc($limit)]);
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function sanitize(array $details): array
    {
        foreach ($details as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                $details[$key] = '[masqué]';
            } elseif (is_array($value)) {
                $details[$key] = $this->sanitize($value);
            } elseif (is_string($value) && mb_strlen($value, 'UTF-8') > 500) {
                $details[$key] = mb_substr($value, 0, 500, 'UTF-8') . '…';
            }
        }
        return $details;
    }
}
