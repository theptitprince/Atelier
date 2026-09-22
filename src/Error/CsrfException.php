<?php

declare(strict_types=1);

namespace Atelier\Error;

/**
 * Jeton anti-CSRF absent ou invalide.
 */
final class CsrfException extends AtelierException
{
    protected string $kind = 'csrf';
    protected int $httpStatus = 419;

    protected function defaultMessage(): string
    {
        return 'Le formulaire a expiré ou la requête est invalide. Actualisez la page puis réessayez.';
    }
}
