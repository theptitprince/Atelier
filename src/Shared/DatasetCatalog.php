<?php

declare(strict_types=1);

namespace Atelier\Shared;

use Atelier\Persistence\Database;
use Atelier\Security\Acl\AclService;
use Atelier\Support\Json;

/**
 * Catalogue des jeux de données : seuls les jeux déclarés "shared" sont exposés aux autres
 * modules ; les jeux privés n'apparaissent ni dans le catalogue ni dans les sélecteurs.
 * Le contrôle d'accès s'appuie sur la ressource atelier/{module}/data/{nom}.
 */
final class DatasetCatalog
{
    public function __construct(private readonly Database $db, private readonly AclService $acl)
    {
    }

    /**
     * Jeux de données partagés (catalogue commun), avec producteurs et consommateurs connus.
     *
     * @return list<array<string, mixed>>
     */
    public function shared(bool $includeAbsent = false): array
    {
        $rows = $this->db->select("SELECT * FROM datasets WHERE visibility = 'shared'" . ($includeAbsent ? '' : ' AND is_present = 1') . ' ORDER BY module_id, name');
        return array_map([$this, 'hydrate'], $rows);
    }

    /**
     * Tous les jeux de données (administration), y compris privés.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return array_map([$this, 'hydrate'], $this->db->select('SELECT * FROM datasets ORDER BY module_id, name'));
    }

    /** @return list<array<string, mixed>> */
    public function ofModule(string $moduleId): array
    {
        return array_map([$this, 'hydrate'], $this->db->select('SELECT * FROM datasets WHERE module_id = :m ORDER BY name', ['m' => $moduleId]));
    }

    /** @return array<string, mixed>|null */
    public function find(string $code): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM datasets WHERE code = :c', ['c' => $code]);
        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Jeu de données partagé, ou null s'il est absent ou privé (jamais exposé).
     *
     * @return array<string, mixed>|null
     */
    public function findShared(string $code): ?array
    {
        $dataset = $this->find($code);
        return $dataset !== null && $dataset['visibility'] === 'shared' && (int) $dataset['is_present'] === 1 ? $dataset : null;
    }

    public function isShared(string $code): bool
    {
        return $this->findShared($code) !== null;
    }

    /** Ressource ACL d'un jeu de données : atelier/{module}/data/{nom}. */
    public static function resource(string $code): string
    {
        [$module, $name] = array_pad(explode('.', $code, 2), 2, '');
        return AclService::module($module) . '/data/' . $name;
    }

    /**
     * L'utilisateur peut-il effectuer l'opération sur ce jeu partagé ? (read, create, update, delete)
     * Retourne faux pour un jeu privé, absent ou non déclaré : l'API intermodule ne l'expose pas.
     */
    public function canAccess(int $userId, string $code, string $operation = 'read'): bool
    {
        $dataset = $this->findShared($code);
        if ($dataset === null || !in_array($operation, $dataset['operations'], true)) {
            return false;
        }
        if ($this->moduleIsClosed($userId, $code)) {
            return false;
        }
        return $this->acl->can($userId, self::resource($code), $operation);
    }

    /** @return list<string> codes des jeux partagés lisibles par l'utilisateur */
    public function readableCodes(int $userId): array
    {
        $codes = [];
        foreach ($this->shared() as $dataset) {
            if (!$this->moduleIsClosed($userId, $dataset['code']) && $this->acl->can($userId, self::resource($dataset['code']), 'read')) {
                $codes[] = $dataset['code'];
            }
        }
        return $codes;
    }

    /**
     * Droit de lecture sur le jeu de données d'une information, partagé ou privé.
     * Le noyau s'en sert pour les pièces jointes : le fichier suit les droits de sa fiche.
     */
    public function canReadData(int $userId, string $code): bool
    {
        if ($this->moduleIsClosed($userId, $code)) {
            return false;
        }
        return $this->acl->can($userId, self::resource($code), 'read');
    }

    /**
     * Le module producteur est-il fermé à cet utilisateur par un refus explicite ?
     *
     * Retirer l'accès à un module en refusant « open » est le geste naturel d'un administrateur :
     * il doit aussi fermer la lecture indirecte des jeux partagés du module (Explorateur, recherche
     * transversale, sélecteurs). À l'inverse, l'absence de règle sur le module ne bloque rien : un
     * droit accordé sur le seul jeu de données reste une délégation volontaire et suffisante.
     */
    private function moduleIsClosed(int $userId, string $code): bool
    {
        $module = explode('.', $code, 2)[0];
        return $this->acl->isExplicitlyDenied($userId, AclService::module($module), 'open');
    }

    /**
     * Jeux de données produits par des modules désactivés ou disparus, et modules consommateurs concernés.
     *
     * @param array<string, string> $moduleStates id => état
     * @return list<array<string, mixed>>
     */
    public function orphaned(array $moduleStates): array
    {
        $result = [];
        foreach ($this->all() as $dataset) {
            $state = $moduleStates[$dataset['module_id']] ?? 'missing';
            if ($state === 'active' && (int) $dataset['is_present'] === 1) {
                continue;
            }
            $dataset['producer_state'] = $state;
            $result[] = $dataset;
        }
        return $result;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): array
    {
        $row['tables'] = $row['tables'] ? Json::decode((string) $row['tables']) : [];
        $row['fields'] = $row['fields'] ? Json::decode((string) $row['fields']) : [];
        $row['operations'] = $row['operations'] ? Json::decode((string) $row['operations']) : ['read'];
        $deps = $this->db->select('SELECT module_id, role FROM dataset_dependencies WHERE dataset_code = :c ORDER BY role, module_id', ['c' => $row['code']]);
        $row['producers'] = array_values(array_map(static fn (array $d): string => (string) $d['module_id'], array_filter($deps, static fn (array $d): bool => $d['role'] === 'producer')));
        $row['consumers'] = array_values(array_map(static fn (array $d): string => (string) $d['module_id'], array_filter($deps, static fn (array $d): bool => $d['role'] === 'consumer')));
        $row['resource'] = self::resource((string) $row['code']);
        return $row;
    }
}
