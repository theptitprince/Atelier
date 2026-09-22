<?php

declare(strict_types=1);

namespace Atelier\Security\Acl;

/**
 * Résultat détaillé d'une résolution ACL : décision, règle déterminante, candidates et explication.
 */
final class Decision
{
    /**
     * @param array<string, mixed>|null $winner
     * @param list<array<string, mixed>> $candidates
     */
    public function __construct(
        public readonly bool $allowed,
        public readonly string $resource,
        public readonly string $permission,
        public readonly ?array $winner,
        public readonly array $candidates,
        public readonly string $explanation,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'resource' => $this->resource,
            'permission' => $this->permission,
            'explanation' => $this->explanation,
            'winner' => $this->winner,
            'candidates' => $this->candidates,
        ];
    }
}
