<?php

declare(strict_types=1);

namespace Atelier\Modules;

/**
 * Module découvert : manifeste (si valide), surcharges administratives appliquées, erreur éventuelle.
 */
final class ModuleDescriptor
{
    /**
     * @param list<string> $errors
     * @param array<string, mixed> $overrides
     */
    public function __construct(
        public readonly string $id,
        public readonly string $directory,
        public readonly ?Manifest $manifest,
        public readonly array $errors,
        public readonly array $overrides,
    ) {
    }

    public function isValid(): bool
    {
        return $this->manifest !== null && $this->errors === [];
    }

    /** État effectif : active | inactive | maintenance | error. */
    public function state(): string
    {
        if (!$this->isValid()) {
            return 'error';
        }
        $status = $this->overrides['status'] ?? $this->manifest?->get('status', 'active');
        return in_array($status, Manifest::STATUSES, true) ? $status : 'active';
    }

    /** Le module peut être chargé et utilisé (actif et valide). */
    public function isUsable(): bool
    {
        return $this->state() === 'active';
    }

    public function name(): string
    {
        return $this->manifest?->name() ?? $this->id;
    }

    public function version(): string
    {
        return $this->manifest?->version() ?? '—';
    }

    public function icon(): string
    {
        return (string) ($this->manifest?->get('icon', 'module') ?? 'module');
    }

    public function description(): string
    {
        return (string) ($this->manifest?->get('description', '') ?? '');
    }

    public function group(): string
    {
        return (string) ($this->overrides['group'] ?? $this->manifest?->get('group', 'tools') ?? 'tools');
    }

    public function order(): int
    {
        return (int) ($this->overrides['order'] ?? $this->manifest?->get('order', 100) ?? 100);
    }

    public function defaultRoute(): string
    {
        return $this->manifest?->defaultRoute() ?? 'index';
    }

    /**
     * Entrées de navigation avec l'ordre personnalisé appliqué, triées.
     *
     * @return list<array<string, mixed>>
     */
    public function navigation(): array
    {
        if ($this->manifest === null) {
            return [];
        }
        $entries = $this->manifest->navigation();
        $navOverrides = is_array($this->overrides['navigation'] ?? null) ? $this->overrides['navigation'] : [];
        foreach ($entries as &$entry) {
            if (isset($navOverrides[$entry['id']]['order']) && is_numeric($navOverrides[$entry['id']]['order'])) {
                $entry['order'] = (int) $navOverrides[$entry['id']]['order'];
            }
        }
        unset($entry);
        usort($entries, static fn (array $a, array $b): int => [$a['order'], $a['label'], $a['id']] <=> [$b['order'], $b['label'], $b['id']]);
        return $entries;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name(),
            'version' => $this->version(),
            'description' => $this->description(),
            'icon' => $this->icon(),
            'group' => $this->group(),
            'order' => $this->order(),
            'state' => $this->state(),
            'manifestStatus' => $this->manifest?->get('status'),
            'errors' => $this->errors,
            'overrides' => $this->overrides,
            'defaultRoute' => $this->defaultRoute(),
            'navigation' => $this->navigation(),
            'datasets' => $this->manifest?->datasets() ?? [],
            'consumes' => $this->manifest?->consumes() ?? [],
            'permissions' => $this->manifest?->permissions() ?? [],
            'assets' => $this->manifest?->assets() ?? ['css' => [], 'js' => [], 'vendor' => []],
            'author' => $this->manifest?->get('author', ''),
        ];
    }
}
