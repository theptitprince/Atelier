<?php

/*
 * Atelier, par ETDEL — https://github.com/theptitprince/Atelier
 * Copyright (C) 2026 ETDEL — licence GPL-3.0-or-later
 * Conditions additionnelles (article 7) : voir le fichier NOTICE.
 */

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
