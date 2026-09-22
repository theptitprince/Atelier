<?php

declare(strict_types=1);

namespace Atelier\Modules\Maintenance;

use Atelier\Error\ForbiddenException;
use Atelier\Modules\ModuleContext;
use DateTimeImmutable;

/**
 * Service intermodule du module Entretien : lecture des équipements et des rappels d'entretien
 * par les autres modules (tableau de bord, notifications…). Chaque méthode vérifie via le
 * catalogue que le demandeur peut lire le jeu concerné ; les tables ne sont jamais exposées.
 */
final class MaintenanceService
{
    public const DATASET_ASSET = 'maintenance.asset';
    public const DATASET_JOB = 'maintenance.job';

    public function __construct(
        private readonly ModuleContext $ctx,
        private readonly AssetRepository $assets,
        private readonly JobRepository $jobs,
        private readonly DateTimeImmutable $today,
    ) {
    }

    /**
     * Équipements actifs (champs publics).
     *
     * @return list<array{id: int, name: string, category: string, category_label: string, meter_unit: ?string, meter_value: ?int}>
     */
    public function assets(int $viewerUserId): array
    {
        $this->assertReadable($viewerUserId, self::DATASET_ASSET);
        return array_map(static fn (array $a): array => [
            'id' => $a['id'],
            'name' => (string) $a['name'],
            'category' => (string) $a['category'],
            'category_label' => AssetRepository::CATEGORIES[$a['category']] ?? (string) $a['category'],
            'meter_unit' => $a['meter_unit'],
            'meter_value' => $a['meter_value'],
        ], $this->assets->all());
    }

    /** Libellé d'un équipement, ou « Équipement #id » s'il a disparu. */
    public function assetLabel(int $viewerUserId, int $id): string
    {
        $this->assertReadable($viewerUserId, self::DATASET_ASSET);
        $asset = $this->assets->find($id);
        return $asset === null ? 'Équipement #' . $id : (string) $asset['name'];
    }

    /**
     * Tâches ouvertes en rappel (bientôt, à faire, en retard), les plus pressantes d'abord.
     *
     * @return list<array{id: int, title: string, asset_id: int, asset_name: string, kind: string, priority: string, state: string, days_left: ?int, meter_left: ?int, next_due_at: ?string, next_due_meter: ?int}>
     */
    public function reminders(int $viewerUserId, int $limit = 50): array
    {
        $this->assertReadable($viewerUserId, self::DATASET_JOB);
        $rows = [];
        foreach ($this->jobs->open() as $job) {
            $state = Scheduler::state($job, $job['asset_meter_value'], $this->today);
            if (!in_array($state['code'], Scheduler::ALERTING, true)) {
                continue;
            }
            $rows[] = [
                'id' => $job['id'],
                'title' => (string) $job['title'],
                'asset_id' => $job['asset_id'],
                'asset_name' => (string) $job['asset_name'],
                'kind' => (string) $job['kind'],
                'priority' => (string) $job['priority'],
                'state' => $state['code'],
                'days_left' => $state['days_left'],
                'meter_left' => $state['meter_left'],
                'next_due_at' => $job['next_due_at'],
                'next_due_meter' => $job['next_due_meter'],
            ];
        }
        usort($rows, static fn (array $a, array $b): int => Scheduler::SEVERITY[$b['state']] <=> Scheduler::SEVERITY[$a['state']] ?: ($a['days_left'] ?? PHP_INT_MAX) <=> ($b['days_left'] ?? PHP_INT_MAX));
        return array_slice($rows, 0, max(1, $limit));
    }

    /** Nombre de rappels actifs (sans contrôle de droit : utilisé par le badge du module). */
    public function reminderCount(): int
    {
        $count = 0;
        foreach ($this->jobs->open() as $job) {
            if (in_array(Scheduler::state($job, $job['asset_meter_value'], $this->today)['code'], Scheduler::ALERTING, true)) {
                $count++;
            }
        }
        return $count;
    }

    private function assertReadable(int $viewerUserId, string $dataset): void
    {
        if (!$this->ctx->shared->catalog->canAccess($viewerUserId, $dataset, 'read')) {
            throw new ForbiddenException('Accès au jeu de données « ' . $dataset . ' » refusé.', 'atelier/maintenance/data/' . explode('.', $dataset)[1], 'read');
        }
    }
}
