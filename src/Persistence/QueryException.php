<?php

declare(strict_types=1);

namespace Atelier\Persistence;

use PDOException;
use RuntimeException;

/**
 * Erreur SQL enrichie de la requête et des paramètres (réservée aux journaux, jamais affichée).
 */
final class QueryException extends RuntimeException
{
    /** @param array<int|string, mixed> $params */
    public function __construct(private readonly string $sql, private readonly array $params, PDOException $previous)
    {
        parent::__construct($previous->getMessage(), (int) $previous->getCode(), $previous);
    }

    public function sql(): string
    {
        return $this->sql;
    }

    /** @return array<int|string, mixed> */
    public function params(): array
    {
        return $this->params;
    }

    /** Violation de contrainte d'unicité ou de clé étrangère. */
    public function isConstraintViolation(): bool
    {
        $message = $this->getMessage();
        return str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'FOREIGN KEY constraint failed')
            || str_contains($message, 'Duplicate entry')
            || str_contains($message, 'a foreign key constraint fails')
            || (string) $this->getCode() === '23000';
    }
}
