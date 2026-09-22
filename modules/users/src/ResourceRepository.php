<?php

declare(strict_types=1);

namespace Atelier\Modules\Users;

use Atelier\Persistence\Database;
use Atelier\Security\Acl\AclService;
use Atelier\Support\Json;

/**
 * Lecture des ressources protégées (table resources) et des libellés de permissions.
 * Les ressources sont synchronisées par le noyau depuis les manifestes ; ce dépôt ne les modifie jamais.
 */
final class ResourceRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Ressources présentes, triées par chemin, avec la liste des permissions décodée.
     *
     * @return list<array<string, mixed>>
     */
    public function allPresent(): array
    {
        $rows = $this->db->select('SELECT * FROM resources WHERE is_present = 1 ORDER BY path');
        return array_map([$this, 'hydrate'], $rows);
    }

    /** @return array<string, mixed>|null */
    public function find(string $path): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM resources WHERE path = :p', ['p' => AclService::normalize($path)]);
        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Arborescence : chaque nœud contient 'resource' et 'children' (triés par libellé, modules par chemin).
     *
     * @return array<string, mixed>|null nœud racine ou null si aucune ressource
     */
    public function tree(): ?array
    {
        $nodes = [];
        foreach ($this->allPresent() as $resource) {
            $nodes[$resource['path']] = ['resource' => $resource, 'children' => []];
        }
        $root = null;
        foreach ($nodes as $path => &$node) {
            $parent = $node['resource']['parent_path'];
            if ($parent === null || !isset($nodes[$parent])) {
                if ($path === AclService::ROOT) {
                    $root = &$node;
                }
                continue;
            }
            $nodes[$parent]['children'][] = &$node;
        }
        unset($node);
        if ($root === null) {
            return null;
        }
        // Les nœuds orphelins (parent absent) sont rattachés à la racine pour rester visibles.
        foreach ($nodes as $path => &$node) {
            $parent = $node['resource']['parent_path'];
            if ($path !== AclService::ROOT && ($parent === null || !isset($nodes[$parent]))) {
                $root['children'][] = &$node;
            }
        }
        unset($node);
        return $root;
    }

    /**
     * Libellés des permissions connues : code => libellé (génériques puis propres aux modules).
     *
     * @return array<string, string>
     */
    public function permissionLabels(?string $moduleId = null): array
    {
        $rows = $this->db->select('SELECT code, module_id, label FROM permissions ORDER BY module_id IS NOT NULL, id');
        $labels = [];
        foreach ($rows as $row) {
            if ($row['module_id'] !== null && $moduleId !== null && $row['module_id'] !== $moduleId) {
                continue;
            }
            $labels[(string) $row['code']] ??= (string) $row['label'];
        }
        return $labels;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): array
    {
        $permissions = [];
        if (!empty($row['permissions'])) {
            try {
                $decoded = Json::decode((string) $row['permissions']);
                $permissions = is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
            } catch (\Throwable) {
                $permissions = [];
            }
        }
        $row['permission_list'] = $permissions === [] ? AclService::GENERIC_PERMISSIONS : $permissions;
        $row['depth'] = AclService::depth((string) $row['path']);
        return $row;
    }
}
