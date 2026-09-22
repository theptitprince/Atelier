<?php

declare(strict_types=1);

namespace Atelier\Activity;

use Atelier\Persistence\Database;
use Atelier\Support\Clock;
use Atelier\Support\Json;
use Throwable;

/**
 * Journal d'activité fonctionnel, de sécurité et de diagnostic (base de données), distinct du
 * journal technique fichier.
 *
 * Chaque entrée porte une catégorie :
 *   - security  : connexions, échecs, blocages, refus d'accès, CSRF, sessions ;
 *   - data      : créations, modifications, suppressions, imports, exports des modules ;
 *   - admin     : comptes, groupes, ACL, modules, paramètres, sauvegardes, console ;
 *   - technical : erreurs serveur, manifestes invalides, migrations, routes introuvables ;
 *   - debug     : trace de chaque vue et action exécutée (module, route, durée) — activée par
 *                 logging.activity_level = debug.
 *
 * Un identifiant de requête (request_id) relie toutes les entrées produites par un même
 * traitement HTTP ou console. Les entrées ne sont ni modifiables ni supprimables depuis
 * l'interface ; seule la purge de rétention les retire.
 */
final class ActivityLog
{
    public const SUCCESS = 'success';
    public const FAILURE = 'failure';
    public const DENIED = 'denied';
    public const ERROR = 'error';

    public const SECURITY = 'security';
    public const DATA = 'data';
    public const ADMIN = 'admin';
    public const TECHNICAL = 'technical';
    public const DEBUG = 'debug';

    public const CATEGORIES = [self::SECURITY, self::DATA, self::ADMIN, self::TECHNICAL, self::DEBUG];
    public const RESULTS = [self::SUCCESS, self::FAILURE, self::DENIED, self::ERROR];

    /** Niveaux d'enregistrement : minimal < standard < debug. */
    public const LEVELS = ['minimal', 'standard', 'debug'];

    /** Préfixes d'action → catégorie déduite lorsqu'aucune n'est fournie. */
    private const CATEGORY_BY_PREFIX = [
        'auth.' => self::SECURITY,
        'access.' => self::SECURITY,
        'csrf.' => self::SECURITY,
        'session.' => self::SECURITY,
        'error.' => self::TECHNICAL,
        'route.' => self::TECHNICAL,
        'module.' => self::ADMIN,
        'modules.' => self::ADMIN,
        'settings.' => self::ADMIN,
        'backup.' => self::ADMIN,
        'console.' => self::ADMIN,
        'maintenance.' => self::ADMIN,
        'user.' => self::ADMIN,
        'group.' => self::ADMIN,
        'acl.' => self::ADMIN,
        'debug.' => self::DEBUG,
    ];

    /** Clés dont la valeur est toujours masquée dans les détails. */
    private const SENSITIVE_KEYS = ['password', 'password_hash', 'new_password', 'old_password', 'current_password', 'password_confirmation', 'token', '_token', 'secret', 'api_key', 'session', 'content', 'cookie', 'authorization'];

    private ?int $userId = null;
    private ?string $username = null;
    private ?string $ip = null;
    private ?string $requestId = null;
    private string $level = 'debug';
    private ?bool $hasCategoryColumn = null;

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

    public function setRequestId(?string $requestId): void
    {
        $this->requestId = $requestId;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    /** Niveau d'enregistrement : minimal (sécurité, administration, erreurs), standard (+ données), debug (tout). */
    public function setLevel(string $level): void
    {
        $this->level = in_array($level, self::LEVELS, true) ? $level : 'standard';
    }

    public function level(): string
    {
        return $this->level;
    }

    public function isDebug(): bool
    {
        return $this->level === 'debug';
    }

    /**
     * Enregistre une entrée. La catégorie est déduite du préfixe de l'action si elle n'est pas fournie.
     *
     * @param array<string, mixed> $details
     */
    public function record(string $moduleId, string $action, string $result = self::SUCCESS, ?string $resourceRef = null, ?string $message = null, array $details = [], ?string $errorId = null, ?string $category = null, ?int $durationMs = null): void
    {
        $category = in_array($category, self::CATEGORIES, true) ? $category : $this->inferCategory($action, $result);
        if (!$this->shouldRecord($category, $result)) {
            return;
        }
        try {
            $row = [
                'occurred_at' => Clock::utc(),
                'user_id' => $this->userId,
                'username' => $this->username,
                'module_id' => $moduleId,
                'action' => $action,
                'result' => in_array($result, self::RESULTS, true) ? $result : self::SUCCESS,
                'resource_ref' => $resourceRef,
                'message' => $message,
                'details' => $details === [] ? null : Json::encode($this->sanitize($details)),
                'ip' => $this->ip,
                'error_id' => $errorId,
            ];
            if ($this->hasCategoryColumn()) {
                $row['category'] = $category;
                $row['request_id'] = $this->requestId;
                $row['duration_ms'] = $durationMs;
            }
            $this->db->insert('activity_log', $row);
        } catch (Throwable) {
            // Le journal ne doit jamais interrompre l'action métier ; le journal technique prend le relais.
        }
    }

    /** @param array<string, mixed> $details */
    public function success(string $moduleId, string $action, ?string $resourceRef = null, ?string $message = null, array $details = [], ?string $category = null): void
    {
        $this->record($moduleId, $action, self::SUCCESS, $resourceRef, $message, $details, null, $category);
    }

    /** @param array<string, mixed> $details */
    public function failure(string $moduleId, string $action, ?string $resourceRef = null, ?string $message = null, array $details = [], ?string $category = null): void
    {
        $this->record($moduleId, $action, self::FAILURE, $resourceRef, $message, $details, null, $category);
    }

    /** @param array<string, mixed> $details */
    public function denied(string $moduleId, string $action, ?string $resourceRef = null, ?string $message = null, array $details = []): void
    {
        $this->record($moduleId, $action, self::DENIED, $resourceRef, $message, $details, null, self::SECURITY);
    }

    /** Entrée de diagnostic (catégorie debug), ignorée hors niveau debug. @param array<string, mixed> $details */
    public function debug(string $moduleId, string $action, ?string $message = null, array $details = [], ?string $resourceRef = null, ?int $durationMs = null): void
    {
        $this->record($moduleId, $action, self::SUCCESS, $resourceRef, $message, $details, null, self::DEBUG, $durationMs);
    }

    /** Événement technique (migrations, manifestes, routes). @param array<string, mixed> $details */
    public function technical(string $moduleId, string $action, string $result = self::SUCCESS, ?string $message = null, array $details = [], ?string $resourceRef = null, ?string $errorId = null): void
    {
        $this->record($moduleId, $action, $result, $resourceRef, $message, $details, $errorId, self::TECHNICAL);
    }

    /**
     * Consultation paginée côté serveur avec filtres cumulables et tri.
     *
     * @param array<string, mixed> $filters from, to, user_id, module_id, action, result, category, request_id, resource_ref, search
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function paginate(array $filters, int $page, int $perPage, string $sort = 'occurred_at', string $direction = 'desc'): array
    {
        [$whereSql, $params] = $this->where($filters);
        $sorts = ['occurred_at', 'username', 'module_id', 'action', 'result', 'category', 'duration_ms'];
        $orderBy = (in_array($sort, $sorts, true) ? $sort : 'occurred_at') . ' ' . (strtolower($direction) === 'asc' ? 'ASC' : 'DESC');
        $perPage = max(1, min(10000, $perPage));
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

    /** Toutes les entrées d'une même requête (corrélation), dans l'ordre chronologique. @return list<array<string, mixed>> */
    public function byRequest(string $requestId): array
    {
        if (!$this->hasCategoryColumn()) {
            return [];
        }
        return $this->db->select('SELECT * FROM activity_log WHERE request_id = :r ORDER BY id ASC', ['r' => $requestId]);
    }

    /** @return list<string> */
    public function distinctActions(?string $moduleId = null): array
    {
        $rows = $moduleId === null
            ? $this->db->select('SELECT DISTINCT action FROM activity_log ORDER BY action')
            : $this->db->select('SELECT DISTINCT action FROM activity_log WHERE module_id = :m ORDER BY action', ['m' => $moduleId]);
        return array_map(static fn (array $r): string => (string) $r['action'], $rows);
    }

    /** @return list<string> */
    public function distinctModules(): array
    {
        return array_map(static fn (array $r): string => (string) $r['module_id'], $this->db->select('SELECT DISTINCT module_id FROM activity_log ORDER BY module_id'));
    }

    /** @return array<string, int> catégorie => nombre d'entrées */
    public function countByCategory(): array
    {
        if (!$this->hasCategoryColumn()) {
            return [];
        }
        $result = [];
        foreach ($this->db->select('SELECT category, COUNT(*) AS n FROM activity_log GROUP BY category ORDER BY category') as $row) {
            $result[(string) $row['category']] = (int) $row['n'];
        }
        return $result;
    }

    /**
     * Purge ciblée (administration) : supprime les entrées correspondant aux filtres (mêmes clés que paginate)
     * et éventuellement plus anciennes que $olderThanDays. Retourne le nombre d'entrées supprimées.
     *
     * @param array<string, mixed> $filters
     */
    public function purgeBy(array $filters, ?int $olderThanDays = null): int
    {
        [$whereSql, $params] = $this->where($filters);
        if ($olderThanDays !== null && $olderThanDays > 0) {
            $whereSql .= ' AND occurred_at < :older';
            $params['older'] = Clock::utc(Clock::now()->modify('-' . $olderThanDays . ' days'));
        }
        if ($whereSql === '1 = 1') {
            $whereSql = '1 = 1'; // purge totale explicite : autorisée mais journalisée par l'appelant
        }
        return $this->db->delete('activity_log', $whereSql, $params);
    }

    /** Nombre d'entrées correspondant aux filtres (aperçu avant purge). @param array<string, mixed> $filters */
    public function countBy(array $filters, ?int $olderThanDays = null): int
    {
        [$whereSql, $params] = $this->where($filters);
        if ($olderThanDays !== null && $olderThanDays > 0) {
            $whereSql .= ' AND occurred_at < :older';
            $params['older'] = Clock::utc(Clock::now()->modify('-' . $olderThanDays . ' days'));
        }
        return $this->db->count("SELECT COUNT(*) FROM activity_log WHERE $whereSql", $params);
    }

    /** Purge des entrées plus anciennes que la rétention ; les entrées debug ont une rétention propre (jours). */
    public function purge(int $retentionMonths, int $debugRetentionDays = 7): int
    {
        $limit = Clock::now()->modify("-{$retentionMonths} months");
        $deleted = $this->db->delete('activity_log', 'occurred_at < :limit', ['limit' => Clock::utc($limit)]);
        if ($this->hasCategoryColumn()) {
            $debugLimit = Clock::now()->modify("-{$debugRetentionDays} days");
            $deleted += $this->db->delete('activity_log', "category = 'debug' AND occurred_at < :limit", ['limit' => Clock::utc($debugLimit)]);
        }
        return $deleted;
    }

    /** Libellés français des catégories et résultats (pour les interfaces). @return array<string, string> */
    public static function categoryLabels(): array
    {
        return [
            self::SECURITY => 'Sécurité',
            self::DATA => 'Données',
            self::ADMIN => 'Administration',
            self::TECHNICAL => 'Technique',
            self::DEBUG => 'Débogage',
        ];
    }

    /** @return array<string, string> */
    public static function resultLabels(): array
    {
        return [
            self::SUCCESS => 'Succès',
            self::FAILURE => 'Échec',
            self::DENIED => 'Refusé',
            self::ERROR => 'Erreur',
        ];
    }

    // ----- Interne -----

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function where(array $filters): array
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
            if (str_ends_with((string) $filters['action'], '*')) {
                $where[] = 'action LIKE :action';
                $params['action'] = rtrim((string) $filters['action'], '*') . '%';
            } else {
                $where[] = 'action = :action';
                $params['action'] = $filters['action'];
            }
        }
        if (!empty($filters['result'])) {
            $where[] = 'result = :result';
            $params['result'] = $filters['result'];
        }
        if (!empty($filters['category']) && $this->hasCategoryColumn()) {
            $where[] = 'category = :category';
            $params['category'] = $filters['category'];
        }
        if (!empty($filters['request_id']) && $this->hasCategoryColumn()) {
            $where[] = 'request_id = :request_id';
            $params['request_id'] = $filters['request_id'];
        }
        if (!empty($filters['error_id'])) {
            $where[] = 'error_id = :error_id';
            $params['error_id'] = $filters['error_id'];
        }
        if (!empty($filters['resource_ref'])) {
            $where[] = 'resource_ref LIKE :resource_ref';
            $params['resource_ref'] = '%' . $filters['resource_ref'] . '%';
        }
        if (!empty($filters['search'])) {
            $where[] = '(' . $this->db->lower('message') . ' LIKE :search OR ' . $this->db->lower('resource_ref') . ' LIKE :search OR ' . $this->db->lower('username') . ' LIKE :search OR ' . $this->db->lower('action') . ' LIKE :search OR error_id LIKE :search_raw' . ($this->hasCategoryColumn() ? ' OR request_id LIKE :search_raw' : '') . ')';
            $params['search'] = '%' . mb_strtolower((string) $filters['search'], 'UTF-8') . '%';
            $params['search_raw'] = '%' . $filters['search'] . '%';
        }
        return [implode(' AND ', $where), $params];
    }

    private function inferCategory(string $action, string $result): string
    {
        foreach (self::CATEGORY_BY_PREFIX as $prefix => $category) {
            if (str_starts_with($action, $prefix)) {
                return $category;
            }
        }
        if ($result === self::DENIED) {
            return self::SECURITY;
        }
        if ($result === self::ERROR) {
            return self::TECHNICAL;
        }
        return self::DATA;
    }

    private function shouldRecord(string $category, string $result): bool
    {
        return match ($this->level) {
            'minimal' => $category === self::SECURITY || $category === self::ADMIN || $result === self::ERROR || $result === self::DENIED,
            'standard' => $category !== self::DEBUG,
            default => true,
        };
    }

    private function hasCategoryColumn(): bool
    {
        if ($this->hasCategoryColumn === null) {
            try {
                $this->hasCategoryColumn = in_array('category', $this->db->columns('activity_log'), true);
            } catch (Throwable) {
                $this->hasCategoryColumn = false;
            }
        }
        return $this->hasCategoryColumn;
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
