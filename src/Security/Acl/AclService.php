<?php

declare(strict_types=1);

namespace Atelier\Security\Acl;

use Atelier\Error\ForbiddenException;
use Atelier\Persistence\Database;
use Atelier\Support\Clock;

/**
 * Résolution des droits d'accès (inspirée de DokuWiki).
 *
 * Ressources hiérarchiques séparées par "/" : atelier, atelier/notes, atelier/notes/screen/list...
 * Une règle : (sujet = user|group|all, ressource, permission, effet allow|deny).
 *
 * Ordre déterministe :
 *   1. la règle sur la ressource la plus précise (chemin le plus long) prévaut sur une règle héritée ;
 *   2. à précision égale, une règle utilisateur prévaut sur les règles de groupes, qui prévalent
 *      sur la règle générale "tous les connectés" ;
 *   3. entre plusieurs règles de groupes de même précision, un refus prévaut sur une autorisation ;
 *   4. sans règle applicable, l'accès est refusé.
 *
 * La permission "admin" accordée sur une ressource implique toutes les autres permissions sur
 * cette ressource et ses descendants, sauf refus plus précis.
 */
final class AclService
{
    public const ROOT = 'atelier';

    /** Permissions génériques du socle. */
    public const GENERIC_PERMISSIONS = ['view', 'open', 'read', 'create', 'update', 'delete', 'import', 'export', 'admin', 'execute'];

    /** @var array<int, list<array<string, mixed>>> cache des règles par utilisateur (id => règles applicables) */
    private array $ruleCache = [];

    /** @var array<int, list<int>> */
    private array $groupCache = [];

    public function __construct(private readonly Database $db)
    {
    }

    public function clearCache(?int $userId = null): void
    {
        if ($userId === null) {
            $this->ruleCache = [];
            $this->groupCache = [];
        } else {
            unset($this->ruleCache[$userId], $this->groupCache[$userId]);
        }
    }

    /** Vérifie un droit ; retourne vrai/faux sans lever d'exception. */
    public function can(int $userId, string $resource, string $permission): bool
    {
        return $this->resolve($userId, $resource, $permission)->allowed;
    }

    /** Lève ForbiddenException si le droit n'est pas accordé. */
    public function require(int $userId, string $resource, string $permission, string $message = ''): void
    {
        if (!$this->can($userId, $resource, $permission)) {
            throw new ForbiddenException($message, $resource, $permission);
        }
    }

    /**
     * Résolution détaillée : décision et explication (règle déterminante, règles candidates).
     */
    public function resolve(int $userId, string $resource, string $permission): Decision
    {
        $resource = self::normalize($resource);
        $rules = $this->rulesFor($userId);
        $ancestors = self::ancestors($resource); // du plus précis au plus général

        $candidates = [];
        foreach ($rules as $rule) {
            if (!in_array($rule['resource'], $ancestors, true)) {
                continue;
            }
            if ($rule['permission'] !== $permission && $rule['permission'] !== 'admin') {
                continue;
            }
            $candidates[] = $rule;
        }

        if ($candidates === []) {
            return new Decision(false, $resource, $permission, null, [], 'Aucune règle applicable : refus par défaut.');
        }

        // Tri : ressource la plus précise, puis sujet (user > group > all), puis pour les groupes deny > allow,
        // puis la permission exacte avant la permission "admin" implicite.
        $subjectRank = ['user' => 0, 'group' => 1, 'all' => 2];
        usort($candidates, static function (array $a, array $b) use ($subjectRank, $permission): int {
            $depth = self::depth($b['resource']) <=> self::depth($a['resource']);
            if ($depth !== 0) {
                return $depth;
            }
            $subject = $subjectRank[$a['subject_type']] <=> $subjectRank[$b['subject_type']];
            if ($subject !== 0) {
                return $subject;
            }
            $effect = ($a['effect'] === 'deny' ? 0 : 1) <=> ($b['effect'] === 'deny' ? 0 : 1);
            if ($effect !== 0) {
                return $effect;
            }
            return ($a['permission'] === $permission ? 0 : 1) <=> ($b['permission'] === $permission ? 0 : 1);
        });

        // Après tri, le premier candidat est déterminant, à une nuance près : au même niveau
        // de précision et de sujet, la permission exacte prévaut sur l'admin implicite,
        // et pour les groupes le refus prévaut. Le tri ci-dessus encode déjà ces règles,
        // mais un refus explicite exact doit l'emporter sur un allow admin implicite au même rang.
        $winner = $candidates[0];
        $sameRank = array_filter($candidates, static fn (array $r): bool => self::depth($r['resource']) === self::depth($winner['resource']) && $r['subject_type'] === $winner['subject_type']);
        foreach ($sameRank as $rule) {
            if ($rule['permission'] === $permission && $rule['effect'] === 'deny') {
                $winner = $rule;
                break;
            }
        }

        $allowed = $winner['effect'] === 'allow';
        $explanation = sprintf(
            '%s par la règle %s « %s » sur « %s » (%s)%s.',
            $allowed ? 'Autorisé' : 'Refusé',
            $winner['effect'] === 'allow' ? 'd’autorisation' : 'de refus',
            $winner['permission'] === $permission ? $permission : 'admin (implique ' . $permission . ')',
            $winner['resource'],
            self::describeSubject($winner),
            $winner['resource'] === $resource ? '' : ', héritée d’un niveau supérieur'
        );

        return new Decision($allowed, $resource, $permission, $winner, $candidates, $explanation);
    }

    /**
     * Droits effectifs d'un utilisateur sur une ressource pour un ensemble de permissions.
     *
     * @param list<string> $permissions
     * @return array<string, bool>
     */
    public function effective(int $userId, string $resource, array $permissions = self::GENERIC_PERMISSIONS): array
    {
        $result = [];
        foreach ($permissions as $permission) {
            $result[$permission] = $this->can($userId, $resource, $permission);
        }
        return $result;
    }

    /**
     * Filtre une liste d'éléments selon un droit (ex. entrées de navigation).
     *
     * @template T
     * @param list<T> $items
     * @param callable(T): array{0: string, 1: string} $extractor retourne [ressource, permission]
     * @return list<T>
     */
    public function filter(int $userId, array $items, callable $extractor): array
    {
        return array_values(array_filter($items, function ($item) use ($userId, $extractor): bool {
            [$resource, $permission] = $extractor($item);
            return $this->can($userId, $resource, $permission);
        }));
    }

    /** @return list<int> identifiants des groupes de l'utilisateur */
    public function groupIds(int $userId): array
    {
        if (!isset($this->groupCache[$userId])) {
            $rows = $this->db->select('SELECT group_id FROM user_groups WHERE user_id = :u', ['u' => $userId]);
            $this->groupCache[$userId] = array_map(static fn (array $r): int => (int) $r['group_id'], $rows);
        }
        return $this->groupCache[$userId];
    }

    /**
     * Règles applicables à un utilisateur (directes, de ses groupes, générales), avec le nom du sujet.
     *
     * @return list<array<string, mixed>>
     */
    public function rulesFor(int $userId): array
    {
        if (isset($this->ruleCache[$userId])) {
            return $this->ruleCache[$userId];
        }
        $groupIds = $this->groupIds($userId);
        $params = ['u' => $userId];
        $groupClause = '';
        if ($groupIds !== []) {
            $placeholders = [];
            foreach ($groupIds as $i => $gid) {
                $placeholders[] = ':g' . $i;
                $params['g' . $i] = $gid;
            }
            $groupClause = " OR (r.subject_type = 'group' AND r.subject_id IN (" . implode(', ', $placeholders) . '))';
        }
        $sql = "SELECT r.id, r.subject_type, r.subject_id, r.resource, r.permission, r.effect, r.comment,
                       CASE r.subject_type WHEN 'user' THEN u.username WHEN 'group' THEN g.label ELSE NULL END AS subject_name
                FROM acl_rules r
                LEFT JOIN users u ON r.subject_type = 'user' AND u.id = r.subject_id
                LEFT JOIN groups g ON r.subject_type = 'group' AND g.id = r.subject_id
                WHERE r.subject_type = 'all' OR (r.subject_type = 'user' AND r.subject_id = :u)$groupClause";
        $rows = $this->db->select($sql, $params);
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['subject_id'] = $row['subject_id'] === null ? null : (int) $row['subject_id'];
        }
        unset($row);
        $this->ruleCache[$userId] = $rows;
        return $rows;
    }

    // ----- Gestion des règles -----

    /**
     * Ajoute ou remplace une règle.
     */
    public function setRule(string $subjectType, ?int $subjectId, string $resource, string $permission, string $effect, ?int $createdBy = null, ?string $comment = null): int
    {
        if (!in_array($subjectType, ['user', 'group', 'all'], true)) {
            throw new \InvalidArgumentException('Type de sujet invalide : ' . $subjectType);
        }
        if (!in_array($effect, ['allow', 'deny'], true)) {
            throw new \InvalidArgumentException('Effet invalide : ' . $effect);
        }
        if ($subjectType === 'all') {
            $subjectId = null;
        } elseif ($subjectId === null) {
            throw new \InvalidArgumentException('Un identifiant de sujet est requis.');
        }
        $resource = self::normalize($resource);

        $existing = $this->db->selectOne(
            'SELECT id FROM acl_rules WHERE subject_type = :t AND ' . ($subjectId === null ? 'subject_id IS NULL' : 'subject_id = :s') . ' AND resource = :r AND permission = :p',
            $subjectId === null ? ['t' => $subjectType, 'r' => $resource, 'p' => $permission] : ['t' => $subjectType, 's' => $subjectId, 'r' => $resource, 'p' => $permission]
        );
        $this->clearCache();
        if ($existing !== null) {
            $this->db->update('acl_rules', ['effect' => $effect, 'comment' => $comment, 'created_by' => $createdBy, 'created_at' => Clock::utc()], 'id = :id', ['id' => (int) $existing['id']]);
            return (int) $existing['id'];
        }
        return $this->db->insert('acl_rules', [
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'resource' => $resource,
            'permission' => $permission,
            'effect' => $effect,
            'comment' => $comment,
            'created_by' => $createdBy,
            'created_at' => Clock::utc(),
        ]);
    }

    public function removeRule(int $ruleId): bool
    {
        $this->clearCache();
        return $this->db->delete('acl_rules', 'id = :id', ['id' => $ruleId]) > 0;
    }

    /** @return array<string, mixed>|null */
    public function rule(int $ruleId): ?array
    {
        return $this->db->selectOne('SELECT * FROM acl_rules WHERE id = :id', ['id' => $ruleId]);
    }

    /**
     * Règles explicites définies sur une ressource (tous sujets), pour l'interface d'administration.
     *
     * @return list<array<string, mixed>>
     */
    public function rulesOnResource(string $resource): array
    {
        return $this->db->select(
            "SELECT r.*, CASE r.subject_type WHEN 'user' THEN u.username WHEN 'group' THEN g.label ELSE 'Tous les utilisateurs connectés' END AS subject_name
             FROM acl_rules r
             LEFT JOIN users u ON r.subject_type = 'user' AND u.id = r.subject_id
             LEFT JOIN groups g ON r.subject_type = 'group' AND g.id = r.subject_id
             WHERE r.resource = :r
             ORDER BY r.subject_type, subject_name, r.permission",
            ['r' => self::normalize($resource)]
        );
    }

    /**
     * Règles définies sur une ressource et tous ses ancêtres, marquées explicite/héritée.
     *
     * @return list<array<string, mixed>>
     */
    public function rulesOnResourceWithInheritance(string $resource): array
    {
        $resource = self::normalize($resource);
        $result = [];
        foreach (self::ancestors($resource) as $path) {
            foreach ($this->rulesOnResource($path) as $rule) {
                $rule['inherited'] = $path !== $resource;
                $result[] = $rule;
            }
        }
        return $result;
    }

    /** @return list<array<string, mixed>> */
    public function rulesForSubject(string $subjectType, ?int $subjectId): array
    {
        if ($subjectType === 'all') {
            return $this->db->select("SELECT * FROM acl_rules WHERE subject_type = 'all' ORDER BY resource, permission");
        }
        return $this->db->select('SELECT * FROM acl_rules WHERE subject_type = :t AND subject_id = :s ORDER BY resource, permission', ['t' => $subjectType, 's' => $subjectId]);
    }

    /**
     * Nombre d'utilisateurs actifs disposant effectivement du droit admin sur la racine.
     * Sert à empêcher de retirer le dernier accès d'administration.
     */
    public function countRootAdmins(?int $excludingUserId = null): int
    {
        $rows = $this->db->select("SELECT id FROM users WHERE status = 'active'");
        $count = 0;
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if ($id === $excludingUserId) {
                continue;
            }
            if ($this->can($id, self::ROOT, 'admin')) {
                $count++;
            }
        }
        return $count;
    }

    /** @return list<int> identifiants des utilisateurs actifs administrateurs de la racine */
    public function rootAdminIds(): array
    {
        $ids = [];
        foreach ($this->db->select("SELECT id FROM users WHERE status = 'active'") as $row) {
            if ($this->can((int) $row['id'], self::ROOT, 'admin')) {
                $ids[] = (int) $row['id'];
            }
        }
        return $ids;
    }

    // ----- Utilitaires de chemins -----

    public static function normalize(string $resource): string
    {
        $resource = trim(str_replace('\\', '/', $resource), '/');
        $resource = preg_replace('#/+#', '/', $resource) ?? $resource;
        if ($resource === '') {
            return self::ROOT;
        }
        if ($resource !== self::ROOT && !str_starts_with($resource, self::ROOT . '/')) {
            $resource = self::ROOT . '/' . $resource;
        }
        return $resource;
    }

    /** Chemin d'un module : atelier/{id}. */
    public static function module(string $moduleId): string
    {
        return self::ROOT . '/' . $moduleId;
    }

    /** @return list<string> la ressource puis ses ancêtres jusqu'à la racine */
    public static function ancestors(string $resource): array
    {
        $resource = self::normalize($resource);
        $segments = explode('/', $resource);
        $paths = [];
        for ($i = count($segments); $i >= 1; $i--) {
            $paths[] = implode('/', array_slice($segments, 0, $i));
        }
        return $paths;
    }

    public static function depth(string $resource): int
    {
        return substr_count($resource, '/') + 1;
    }

    /** @param array<string, mixed> $rule */
    private static function describeSubject(array $rule): string
    {
        return match ($rule['subject_type']) {
            'user' => 'règle directe de l’utilisateur ' . ($rule['subject_name'] ?? '#' . $rule['subject_id']),
            'group' => 'groupe ' . ($rule['subject_name'] ?? '#' . $rule['subject_id']),
            default => 'règle générale pour tous les utilisateurs connectés',
        };
    }
}
