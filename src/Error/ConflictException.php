<?php

declare(strict_types=1);

namespace Atelier\Error;

/**
 * Conflit de modification : la donnée a été modifiée entre-temps ou l'opération viole une contrainte.
 */
final class ConflictException extends AtelierException
{
    protected string $kind = 'conflict';
    protected int $httpStatus = 409;

    protected function defaultMessage(): string
    {
        return 'La donnée a été modifiée entre-temps. Actualisez avant de réessayer.';
    }
}
