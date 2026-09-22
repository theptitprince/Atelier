<?php

declare(strict_types=1);

namespace Atelier\Error;

/**
 * Authentification requise ou session expirée.
 */
final class AuthenticationRequiredException extends AtelierException
{
    protected string $kind = 'auth';
    protected int $httpStatus = 401;

    public function __construct(string $message = '', bool $expired = false)
    {
        parent::__construct($message, ['expired' => $expired]);
    }

    protected function defaultMessage(): string
    {
        return 'Vous devez être connecté pour accéder à cette ressource.';
    }

    public static function expired(): self
    {
        return new self('Votre session a expiré. Veuillez vous reconnecter.', true);
    }
}
