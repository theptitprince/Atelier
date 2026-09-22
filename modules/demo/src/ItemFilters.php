<?php

declare(strict_types=1);

namespace Atelier\Modules\Demo;

/**
 * Normalisation des paramètres de la vue « Tableaux » : recherche, catégorie, état,
 * tri, page. Construit aussi les routes (chaîne de requête) réutilisées par les en-têtes
 * de tri, la pagination et l'export.
 */
final class ItemFilters
{
    public const PER_PAGE = 25;
    public const SORTS = ['id', 'name', 'category', 'quantity', 'price', 'active', 'created_at'];

    private string $q = '';
    private string $category = '';
    /** '' (tous), '1' (actifs) ou '0' (inactifs) */
    private string $active = '';
    private string $sort = 'id';
    private string $direction = 'asc';
    private int $page = 1;

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        $filters = new self();
        $filters->q = is_scalar($input['q'] ?? null) ? trim((string) $input['q']) : '';
        $category = is_scalar($input['category'] ?? null) ? trim((string) $input['category']) : '';
        $filters->category = in_array($category, ItemRepository::CATEGORIES, true) ? $category : '';
        $active = is_scalar($input['active'] ?? null) ? (string) $input['active'] : '';
        $filters->active = in_array($active, ['0', '1'], true) ? $active : '';
        $sort = is_scalar($input['sort'] ?? null) ? (string) $input['sort'] : 'id';
        $filters->sort = in_array($sort, self::SORTS, true) ? $sort : 'id';
        $filters->direction = (($input['dir'] ?? 'asc') === 'desc') ? 'desc' : 'asc';
        $filters->page = max(1, (int) ($input['page'] ?? 1));
        return $filters;
    }

    public function q(): string
    {
        return $this->q;
    }

    public function category(): string
    {
        return $this->category;
    }

    public function active(): string
    {
        return $this->active;
    }

    public function sort(): string
    {
        return $this->sort;
    }

    public function direction(): string
    {
        return $this->direction;
    }

    public function page(): int
    {
        return $this->page;
    }

    public function perPage(): int
    {
        return self::PER_PAGE;
    }

    /** Un filtre est-il actif (hors tri et pagination) ? */
    public function isActive(): bool
    {
        return $this->q !== '' || $this->category !== '' || $this->active !== '';
    }

    /**
     * Critères pour le dépôt.
     *
     * @return array{q: string, category: string, active: ?bool}
     */
    public function criteria(): array
    {
        return [
            'q' => $this->q,
            'category' => $this->category,
            'active' => $this->active === '' ? null : $this->active === '1',
        ];
    }

    /**
     * Paramètres de requête à conserver dans les liens (tri et page inclus selon les options).
     *
     * @return array<string, string|int>
     */
    public function toQuery(bool $withSort = true, bool $withPage = false): array
    {
        $query = [];
        if ($this->q !== '') {
            $query['q'] = $this->q;
        }
        if ($this->category !== '') {
            $query['category'] = $this->category;
        }
        if ($this->active !== '') {
            $query['active'] = $this->active;
        }
        if ($withSort && ($this->sort !== 'id' || $this->direction !== 'asc')) {
            $query['sort'] = $this->sort;
            $query['dir'] = $this->direction;
        }
        if ($withPage && $this->page > 1) {
            $query['page'] = $this->page;
        }
        return $query;
    }

    /** Route du module avec la chaîne de requête courante (ex. « tables?q=vis&page=2 »). */
    public function route(string $base = 'tables', array $overrides = []): string
    {
        $query = array_filter($this->toQuery(true, true) + $overrides, static fn ($v): bool => $v !== null && $v !== '');
        foreach ($overrides as $key => $value) {
            if ($value === null || $value === '') {
                unset($query[$key]);
            } else {
                $query[$key] = $value;
            }
        }
        return $query === [] ? $base : $base . '?' . http_build_query($query);
    }
}
