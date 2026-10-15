<?php

/*
 * Atelier, par ETDEL — https://github.com/theptitprince/Atelier
 * Copyright (C) 2026 ETDEL — licence GPL-3.0-or-later
 * Conditions additionnelles (article 7) : voir le fichier NOTICE.
 */

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
