<?php

declare(strict_types=1);

namespace Atelier\Error;

use RuntimeException;
use Throwable;

/**
 * Exception applicative de base. Son message est destiné à l'utilisateur (compréhensible,
 * sans détail technique) ; les informations techniques vont dans les journaux.
 */
class AtelierException extends RuntimeException
{
    /** Type normalisé exploité par le client : validation, auth, forbidden, not_found, conflict, server, unavailable. */
    protected string $kind = 'server';

    protected int $httpStatus = 500;

    /** @var array<string, mixed> données complémentaires renvoyées au client (ex. erreurs de champs) */
    protected array $payload = [];

    /** @param array<string, mixed> $payload */
    public function __construct(string $message = '', array $payload = [], ?Throwable $previous = null)
    {
        parent::__construct($message !== '' ? $message : $this->defaultMessage(), 0, $previous);
        $this->payload = $payload;
    }

    protected function defaultMessage(): string
    {
        return 'Une erreur inattendue est survenue.';
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }
}
