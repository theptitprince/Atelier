<?php

declare(strict_types=1);

namespace Atelier\Error;

/**
 * Module inactif, en maintenance ou dont le chargement a échoué.
 */
final class ModuleUnavailableException extends AtelierException
{
    protected string $kind = 'unavailable';
    protected int $httpStatus = 503;

    public function __construct(string $message = '', ?string $moduleId = null, ?string $state = null)
    {
        parent::__construct($message, array_filter(['module' => $moduleId, 'state' => $state]));
    }

    protected function defaultMessage(): string
    {
        return 'Ce module est momentanément indisponible.';
    }
}
