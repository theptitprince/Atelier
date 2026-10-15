<?php

/*
 * Atelier, par ETDEL — https://github.com/theptitprince/Atelier
 * Copyright (C) 2026 ETDEL — licence GPL-3.0-or-later
 * Conditions additionnelles (article 7) : voir le fichier NOTICE.
 */

declare(strict_types=1);

namespace Atelier\Error;

/**
 * Stockage applicatif (base de données) indisponible : refus par défaut et état de maintenance.
 */
final class StorageUnavailableException extends AtelierException
{
    protected string $kind = 'unavailable';
    protected int $httpStatus = 503;

    protected function defaultMessage(): string
    {
        return 'Le stockage applicatif est indisponible. L’application est en maintenance.';
    }
}
