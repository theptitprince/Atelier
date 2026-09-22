<?php

declare(strict_types=1);

namespace Atelier\Modules\Activity;

use Atelier\Support\Clock;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Lecture et normalisation des paramètres de consultation du journal (filtres, pagination, tri).
 *
 * Les dates saisies par l'utilisateur (jj/mm/aaaa ou aaaa-mm-jj) sont interprétées dans le fuseau
 * d'affichage puis converties en UTC pour interroger la base, conformément à la convention du noyau.
 */
final class ActivityFilters
{
    public const RESULTS = ['success', 'failure', 'denied', 'error'];
    public const PER_PAGE_OPTIONS = [25, 50, 100];
    public const SORTS = ['occurred_at', 'username', 'module_id', 'action', 'result', 'category', 'duration_ms'];
    public const CATEGORIES = ['security', 'data', 'admin', 'technical', 'debug'];

    /** Clés de la chaîne de requête correspondant aux filtres (hors pagination et tri). */
    private const FILTER_KEYS = ['from', 'to', 'user_id', 'module', 'action', 'result', 'category', 'request', 'resource', 'q'];

    /** @var array<string, string> valeurs saisies, normalisées, sans les vides */
    private array $values = [];

    private int $page = 1;
    private int $perPage = 25;
    private string $sort = 'occurred_at';
    private string $direction = 'desc';

    public function __construct(private readonly string $timezone)
    {
    }

    /**
     * Construit les filtres depuis un tableau de paramètres (query string ou corps d'action).
     *
     * @param array<string, mixed> $input
     */
    public static function fromArray(array $input, string $timezone, int $defaultPerPage = 25): self
    {
        $filters = new self($timezone);
        foreach (self::FILTER_KEYS as $key) {
            $value = $input[$key] ?? null;
            if (!is_scalar($value)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $filters->values[$key] = $value;
        }

        // Dates : on ne conserve que les saisies interprétables, normalisées au format ISO.
        foreach (['from', 'to'] as $key) {
            if (isset($filters->values[$key])) {
                $date = self::parseDate($filters->values[$key]);
                if ($date === null) {
                    unset($filters->values[$key]);
                } else {
                    $filters->values[$key] = $date->format('Y-m-d');
                }
            }
        }
        if (isset($filters->values['user_id']) && (!ctype_digit($filters->values['user_id']) || (int) $filters->values['user_id'] <= 0)) {
            unset($filters->values['user_id']);
        }
        if (isset($filters->values['result']) && !in_array($filters->values['result'], self::RESULTS, true)) {
            unset($filters->values['result']);
        }
        if (isset($filters->values['category']) && !in_array($filters->values['category'], self::CATEGORIES, true)) {
            unset($filters->values['category']);
        }
        if (isset($filters->values['request']) && preg_match('/^[a-f0-9]{6,32}$/', $filters->values['request']) !== 1) {
            unset($filters->values['request']);
        }

        $filters->page = max(1, (int) ($input['page'] ?? 1));
        $perPage = (int) ($input['per_page'] ?? $defaultPerPage);
        $filters->perPage = in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : (in_array($defaultPerPage, self::PER_PAGE_OPTIONS, true) ? $defaultPerPage : 25);
        $sort = (string) ($input['sort'] ?? 'occurred_at');
        $filters->sort = in_array($sort, self::SORTS, true) ? $sort : 'occurred_at';
        $filters->direction = strtolower((string) ($input['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        return $filters;
    }

    /** Valeur saisie d'un filtre (chaîne vide si absent), pour le pré-remplissage du formulaire. */
    public function value(string $key): string
    {
        return $this->values[$key] ?? '';
    }

    public function isActive(): bool
    {
        return $this->values !== [];
    }

    public function page(): int
    {
        return $this->page;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    public function sort(): string
    {
        return $this->sort;
    }

    public function direction(): string
    {
        return $this->direction;
    }

    /**
     * Filtres au format attendu par ActivityLog::paginate() (dates converties en UTC).
     *
     * @return array<string, mixed>
     */
    public function toRepository(): array
    {
        $tz = new DateTimeZone($this->timezone);
        $repo = [];
        if (isset($this->values['from'])) {
            $repo['from'] = Clock::utc(new DateTimeImmutable($this->values['from'] . ' 00:00:00', $tz));
        }
        if (isset($this->values['to'])) {
            $repo['to'] = Clock::utc(new DateTimeImmutable($this->values['to'] . ' 23:59:59', $tz));
        }
        if (isset($this->values['user_id'])) {
            $repo['user_id'] = (int) $this->values['user_id'];
        }
        if (isset($this->values['module'])) {
            $repo['module_id'] = $this->values['module'];
        }
        if (isset($this->values['action'])) {
            $repo['action'] = $this->values['action'];
        }
        if (isset($this->values['result'])) {
            $repo['result'] = $this->values['result'];
        }
        if (isset($this->values['category'])) {
            $repo['category'] = $this->values['category'];
        }
        if (isset($this->values['request'])) {
            $repo['request_id'] = $this->values['request'];
        }
        if (isset($this->values['resource'])) {
            $repo['resource_ref'] = $this->values['resource'];
        }
        if (isset($this->values['q'])) {
            $repo['search'] = $this->values['q'];
        }
        return $repo;
    }

    /**
     * Paramètres de la chaîne de requête : filtres seuls, ou filtres + tri (+ pagination).
     *
     * @return array<string, string|int>
     */
    public function toQuery(bool $withSort = true, bool $withPage = false): array
    {
        $query = $this->values;
        if ($withSort) {
            $query['sort'] = $this->sort;
            $query['dir'] = $this->direction;
            if ($this->perPage !== 25) {
                $query['per_page'] = $this->perPage;
            }
        }
        if ($withPage && $this->page > 1) {
            $query['page'] = $this->page;
        }
        return $query;
    }

    /** Route "list?…" reflétant l'état courant (utile pour « Actualiser » et la pagination). */
    public function route(string $base = 'list', array $overrides = []): string
    {
        $query = array_filter($overrides + $this->toQuery(true, true), static fn ($v): bool => $v !== null && $v !== '');
        return $query === [] ? $base : $base . '?' . http_build_query($query);
    }

    /** Interprète "jj/mm/aaaa" ou "aaaa-mm-jj" ; null si invalide. */
    private static function parseDate(string $value): ?DateTimeImmutable
    {
        foreach (['d/m/Y', 'Y-m-d', 'd-m-Y', 'd.m.Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value, new DateTimeZone('UTC'));
            if ($date !== false && $date->format($format) === $value) {
                return $date;
            }
        }
        return null;
    }
}
