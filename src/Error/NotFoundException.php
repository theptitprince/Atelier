<?php

declare(strict_types=1);

namespace Atelier\Error;

/**
 * Ressource introuvable.
 */
final class NotFoundException extends AtelierException
{
    protected string $kind = 'not_found';
    protected int $httpStatus = 404;

    protected function defaultMessage(): string
    {
        return 'La ressource demandée est introuvable.';
    }
}
