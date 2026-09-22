<?php

declare(strict_types=1);

namespace Atelier\Modules\Trash;

use Atelier\Error\ForbiddenException;
use Atelier\Error\NotFoundException;
use Atelier\Modules\ModuleContext;
use Atelier\Modules\ModuleDescriptor;
use Atelier\Modules\TrashProviderInterface;
use Atelier\Security\Acl\AclService;
use Atelier\Shared\DatasetCatalog;
use Atelier\Support\Clock;
use Atelier\Support\Str;

/**
 * Agrégation des éléments en corbeille pour l'utilisateur courant :
 *   1. les modules actifs dont la classe d'entrée implémente TrashProviderInterface ;
 *   2. les pièces jointes du noyau supprimées logiquement (table attachments).
 *
 * Chaque élément normalisé possède une clé « source:module:id » (module = « core » pour les
 * pièces jointes) qui sert d'identifiant dans les actions et le journal d'activité.
 * Un fournisseur défaillant n'interrompt jamais l'agrégation : l'erreur est consignée et
 * remontée sous forme d'avertissement.
 */
final class TrashAggregator
{
    public const SOURCE_MODULE = 'module';
    public const SOURCE_ATTACHMENT = 'attachment';
    public const CORE_MODULE = 'core';
    public const CORE_MODULE_NAME = 'Pièces jointes';

    /** Colonnes de tri acceptées par sort(). */
    public const SORTS = ['label', 'type', 'module', 'deleted_at', 'purge_at'];

    /** @var array{items: list<array<string, mixed>>, warnings: list<string>}|null */
    private ?array $cache = null;

    public function __construct(
        private readonly ModuleContext $ctx,
        private readonly TrashQueries $queries,
        private readonly string $selfId,
        private readonly int $retentionDays,
    ) {
    }

    // =====================================================================
    // Collecte
    // =====================================================================

    /**
     * Éléments visibles par l'utilisateur courant, toutes sources confondues, et avertissements.
     *
     * @return array{items: list<array<string, mixed>>, warnings: list<string>}
     */
    public function collect(bool $fresh = false): array
    {
        if ($this->cache !== null && !$fresh) {
            return $this->cache;
        }
        $userId = $this->ctx->userId();
        $items = [];
        $warnings = [];

        foreach ($this->providers($warnings) as [$descriptor, $provider]) {
            try {
                $entries = $provider->trashItems();
            } catch (\Throwable $e) {
                $this->ctx->logger->warning('Corbeille : le fournisseur a échoué', ['module' => $descriptor->id, 'error' => $e->getMessage()]);
                $warnings[] = 'Le module « ' . $descriptor->name() . ' » n’a pas pu fournir ses éléments supprimés (' . $e->getMessage() . ').';
                continue;
            }
            foreach ($entries as $entry) {
                if (!is_array($entry) || (string) ($entry['id'] ?? '') === '') {
                    continue;
                }
                $items[] = $this->normalizeModuleItem($descriptor, $entry);
            }
        }

        foreach ($this->queries->deletedAttachments() as $row) {
            if ($this->canManageAttachment($userId, $row)) {
                $items[] = $this->normalizeAttachment($row);
            }
        }

        return $this->cache = ['items' => $items, 'warnings' => $warnings];
    }

    /** Élément identifié par sa clé « source:module:id », ou null s'il n'est pas visible. */
    public function find(string $key): ?array
    {
        $parsed = self::parseKey($key);
        if ($parsed === null) {
            return null;
        }
        foreach ($this->collect()['items'] as $item) {
            if ($item['key'] === $parsed['key']) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Nombre d'éléments dont la date de purge est dépassée mais toujours présents.
     * Hors requête utilisateur (console), seules les pièces jointes sont comptées :
     * les fournisseurs ne répondent que pour un utilisateur connecté.
     */
    public function countExpired(): int
    {
        $limit = Clock::utc(Clock::now()->modify('-' . $this->retentionDays . ' days'));
        $count = $this->queries->countExpiredAttachments($limit);
        if ($this->ctx->auth->userId() === null) {
            return $count;
        }
        $now = Clock::utc();
        foreach ($this->collect()['items'] as $item) {
            if ($item['source'] === self::SOURCE_MODULE && $item['purge_at'] !== null && $item['purge_at'] <= $now) {
                $count++;
            }
        }
        return $count;
    }

    // =====================================================================
    // Restauration et purge
    // =====================================================================

    /** Restaure un élément normalisé ; le fournisseur revérifie ses propres droits. */
    public function restore(array $item): void
    {
        if (!$item['can_restore']) {
            throw new ForbiddenException('Vous ne pouvez pas restaurer « ' . $item['label'] . ' ».', $this->resourceOf($item), 'update');
        }
        if ($item['source'] === self::SOURCE_MODULE) {
            $this->provider((string) $item['module_id'])->restoreTrashItem((string) $item['id']);
            return;
        }
        $attachment = $this->requireDeletedAttachment((string) $item['id']);
        $this->ctx->shared->attachments->restore((string) $attachment['id']);
    }

    /** Supprime définitivement un élément normalisé (fichier + métadonnées pour une pièce jointe). */
    public function purge(array $item): void
    {
        if (!$item['can_purge']) {
            throw new ForbiddenException('Vous ne pouvez pas supprimer définitivement « ' . $item['label'] . ' ».', $this->resourceOf($item), 'delete');
        }
        if ($item['source'] === self::SOURCE_MODULE) {
            $this->provider((string) $item['module_id'])->purgeTrashItem((string) $item['id']);
            return;
        }
        $attachment = $this->requireDeletedAttachment((string) $item['id']);
        if (!$this->ctx->shared->attachments->purge((string) $attachment['id'])) {
            throw new \RuntimeException('Le fichier « ' . $attachment['original_name'] . ' » n’a pas pu être supprimé du stockage.');
        }
    }

    /**
     * Fournisseur d'un module : actif, ouvert à l'utilisateur et implémentant l'interface.
     */
    public function provider(string $moduleId): TrashProviderInterface
    {
        $descriptor = $this->ctx->modules()->get($moduleId);
        if ($descriptor === null || $moduleId === $this->selfId || !$descriptor->isUsable()) {
            throw new NotFoundException('Le module « ' . Str::e($moduleId) . ' » est indisponible.');
        }
        $this->ctx->require(AclService::module($moduleId), 'open', 'Vous n’avez pas accès au module « ' . $descriptor->name() . ' ».');
        [$module] = $this->ctx->modules()->boot($moduleId, $this->ctx);
        if (!$module instanceof TrashProviderInterface) {
            throw new NotFoundException('Le module « ' . $descriptor->name() . ' » ne gère pas de corbeille.');
        }
        return $module;
    }

    // =====================================================================
    // Filtrage, tri, clés (fonctions pures)
    // =====================================================================

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public static function filter(array $items, string $source, string $module, string $search): array
    {
        $needle = mb_strtolower(trim($search), 'UTF-8');
        return array_values(array_filter($items, static function (array $item) use ($source, $module, $needle): bool {
            if ($source !== '' && $item['source'] !== $source) {
                return false;
            }
            if ($module !== '' && $item['module_id'] !== $module) {
                return false;
            }
            if ($needle !== '') {
                $haystack = mb_strtolower($item['label'] . ' ' . ($item['context'] ?? ''), 'UTF-8');
                if (!str_contains($haystack, $needle)) {
                    return false;
                }
            }
            return true;
        }));
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public static function sort(array $items, string $sort, string $direction): array
    {
        $sort = in_array($sort, self::SORTS, true) ? $sort : 'deleted_at';
        $factor = $direction === 'asc' ? 1 : -1;
        $value = static fn (array $item): string => match ($sort) {
            'label' => mb_strtolower((string) $item['label'], 'UTF-8'),
            'type' => mb_strtolower((string) $item['type_label'], 'UTF-8'),
            'module' => mb_strtolower((string) $item['module_name'], 'UTF-8'),
            'purge_at' => (string) ($item['purge_at'] ?? ($direction === 'asc' ? '9999' : '')),
            default => (string) $item['deleted_at'],
        };
        usort($items, static function (array $a, array $b) use ($value, $factor): int {
            $cmp = strcmp($value($a), $value($b)) * $factor;
            return $cmp !== 0 ? $cmp : strcmp((string) $b['deleted_at'], (string) $a['deleted_at']);
        });
        return $items;
    }

    /**
     * Modules représentés dans une liste d'éléments : id => nom, triés par nom.
     *
     * @param list<array<string, mixed>> $items
     * @return array<string, string>
     */
    public static function moduleOptions(array $items): array
    {
        $options = [];
        foreach ($items as $item) {
            $options[(string) $item['module_id']] = (string) $item['module_name'];
        }
        uasort($options, static fn (string $a, string $b): int => strcasecmp($a, $b));
        return $options;
    }

    public static function key(string $source, string $module, string $id): string
    {
        return $source . ':' . $module . ':' . $id;
    }

    /**
     * Décompose « source:module:id » ; null si la forme est invalide.
     *
     * @return array{key: string, source: string, module: string, id: string}|null
     */
    public static function parseKey(string $key): ?array
    {
        $parts = explode(':', trim($key), 3);
        if (count($parts) !== 3) {
            return null;
        }
        [$source, $module, $id] = $parts;
        if (!in_array($source, [self::SOURCE_MODULE, self::SOURCE_ATTACHMENT], true) || !Str::isSlug($module) || $id === '') {
            return null;
        }
        return ['key' => self::key($source, $module, $id), 'source' => $source, 'module' => $module, 'id' => $id];
    }

    /** Jours restants avant la purge (0 si dépassée), null si aucune purge n'est prévue. */
    public static function daysUntil(?string $purgeAtUtc): ?int
    {
        $purgeAt = Clock::parseUtc($purgeAtUtc);
        if ($purgeAt === null) {
            return null;
        }
        $seconds = $purgeAt->getTimestamp() - Clock::now()->getTimestamp();
        return max(0, (int) ceil($seconds / 86400));
    }

    // =====================================================================
    // Interne
    // =====================================================================

    /**
     * Modules fournisseurs utilisables par l'utilisateur courant (le module Corbeille lui-même est ignoré).
     *
     * @param list<string> $warnings
     * @return list<array{0: ModuleDescriptor, 1: TrashProviderInterface}>
     */
    private function providers(array &$warnings): array
    {
        $userId = $this->ctx->userId();
        $providers = [];
        foreach ($this->ctx->modules()->all() as $id => $descriptor) {
            if ($id === $this->selfId || !$descriptor->isUsable()) {
                continue;
            }
            if (!$this->ctx->acl->can($userId, AclService::module($id), 'open')) {
                continue;
            }
            try {
                [$module] = $this->ctx->modules()->boot($id, $this->ctx);
            } catch (\Throwable $e) {
                $this->ctx->logger->warning('Corbeille : module impossible à charger', ['module' => $id, 'error' => $e->getMessage()]);
                $warnings[] = 'Le module « ' . $descriptor->name() . ' » n’a pas pu être chargé.';
                continue;
            }
            if ($module instanceof TrashProviderInterface) {
                $providers[] = [$descriptor, $module];
            }
        }
        return $providers;
    }

    /** @param array<string, mixed> $entry */
    private function normalizeModuleItem(ModuleDescriptor $descriptor, array $entry): array
    {
        $dataset = (string) ($entry['dataset'] ?? '');
        $purgeAt = isset($entry['purge_at']) && $entry['purge_at'] !== '' ? (string) $entry['purge_at'] : null;
        return $this->item(
            self::SOURCE_MODULE,
            $descriptor->id,
            $descriptor->name(),
            (string) $entry['id'],
            (string) ($entry['label'] ?? ''),
            $dataset,
            $this->datasetName($descriptor, $dataset),
            (string) ($entry['deleted_at'] ?? ''),
            isset($entry['deleted_by']) && is_numeric($entry['deleted_by']) ? (int) $entry['deleted_by'] : null,
            $purgeAt,
            (bool) ($entry['can_restore'] ?? false),
            (bool) ($entry['can_purge'] ?? false),
            null
        );
    }

    /** @param array<string, mixed> $row */
    private function normalizeAttachment(array $row): array
    {
        $deletedAt = (string) $row['deleted_at'];
        $purgeAt = Clock::parseUtc($deletedAt)?->modify('+' . $this->retentionDays . ' days');
        $context = Str::humanSize((int) ($row['size'] ?? 0));
        if (($row['info_label'] ?? null) !== null && $row['info_label'] !== '') {
            $context .= ' · rattachée à « ' . $row['info_label'] . ' »';
        }
        if (($row['uploader_name'] ?? null) !== null && $row['uploader_name'] !== '') {
            $context .= ' · déposée par ' . $row['uploader_name'];
        }
        return $this->item(
            self::SOURCE_ATTACHMENT,
            self::CORE_MODULE,
            self::CORE_MODULE_NAME,
            (string) $row['id'],
            (string) $row['original_name'],
            (string) ($row['dataset_code'] ?? ''),
            'Pièce jointe',
            $deletedAt,
            isset($row['uploaded_by']) ? (int) $row['uploaded_by'] : null,
            $purgeAt === null ? null : Clock::utc($purgeAt),
            true,
            true,
            $context
        );
    }

    /** Élément normalisé (structure unique consommée par les gabarits et les actions). */
    private function item(string $source, string $moduleId, string $moduleName, string $id, string $label, string $dataset, string $datasetName, string $deletedAt, ?int $deletedBy, ?string $purgeAt, bool $canRestore, bool $canPurge, ?string $context): array
    {
        return [
            'key' => self::key($source, $moduleId, $id),
            'source' => $source,
            'module_id' => $moduleId,
            'module_name' => $moduleName,
            'id' => $id,
            'label' => $label !== '' ? $label : '(sans libellé)',
            'dataset' => $dataset,
            'dataset_name' => $datasetName,
            'type_label' => $source === self::SOURCE_ATTACHMENT ? 'Pièce jointe' : $datasetName,
            'context' => $context,
            'deleted_at' => $deletedAt,
            'deleted_by' => $deletedBy,
            'purge_at' => $purgeAt,
            'expires_in_days' => self::daysUntil($purgeAt),
            'can_restore' => $canRestore,
            'can_purge' => $canPurge,
        ];
    }

    /** Nom du jeu de données dans le manifeste du module, sinon son code. */
    private function datasetName(ModuleDescriptor $descriptor, string $code): string
    {
        foreach ($descriptor->manifest?->datasets() ?? [] as $dataset) {
            if (($dataset['code'] ?? null) === $code) {
                return (string) ($dataset['name'] ?? $code);
            }
        }
        return $code !== '' ? $code : 'Donnée';
    }

    /**
     * Une pièce jointe supprimée est visible si l'utilisateur en est l'auteur, s'il a « update »
     * sur le jeu de données de l'information rattachée, ou s'il est administrateur racine.
     *
     * @param array<string, mixed> $row
     */
    private function canManageAttachment(int $userId, array $row): bool
    {
        if (isset($row['uploaded_by']) && (int) $row['uploaded_by'] === $userId) {
            return true;
        }
        if ($this->ctx->acl->can($userId, AclService::ROOT, 'admin')) {
            return true;
        }
        $dataset = (string) ($row['dataset_code'] ?? $row['info_dataset'] ?? '');
        return $dataset !== '' && $this->ctx->acl->can($userId, DatasetCatalog::resource($dataset), 'update');
    }

    /**
     * Relit une pièce jointe en corbeille et revérifie les droits (les données ont pu changer).
     *
     * @return array<string, mixed>
     */
    private function requireDeletedAttachment(string $id): array
    {
        $attachment = preg_match('/^[0-9a-f]{32}$/', $id) === 1 ? $this->ctx->shared->attachments->find($id, true) : null;
        if ($attachment === null || $attachment['deleted_at'] === null) {
            throw new NotFoundException('Cette pièce jointe n’est pas dans la corbeille.');
        }
        if (!$this->canManageAttachment($this->ctx->userId(), $attachment)) {
            throw new ForbiddenException('Vous ne pouvez pas gérer cette pièce jointe.', AclService::ROOT . '/attachments', 'update');
        }
        return $attachment;
    }

    /** Ressource ACL associée à un élément (pour les messages de refus). */
    private function resourceOf(array $item): string
    {
        if ($item['source'] === self::SOURCE_MODULE) {
            return $item['dataset'] !== '' ? DatasetCatalog::resource((string) $item['dataset']) : AclService::module((string) $item['module_id']);
        }
        return AclService::ROOT . '/attachments';
    }
}
