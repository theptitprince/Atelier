<?php

declare(strict_types=1);

namespace Atelier\Error;

/**
 * Accès interdit : l'utilisateur est connecté mais ne dispose pas du droit nécessaire.
 */
final class ForbiddenException extends AtelierException
{
    protected string $kind = 'forbidden';
    protected int $httpStatus = 403;

    public function __construct(string $message = '', ?string $resource = null, ?string $permission = null)
    {
        parent::__construct($message, array_filter(['resource' => $resource, 'permission' => $permission]));
    }

    protected function defaultMessage(): string
    {
        return 'Vous ne disposez pas des droits nécessaires pour effectuer cette action.';
    }
}
