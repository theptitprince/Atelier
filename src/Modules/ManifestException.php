<?php

declare(strict_types=1);

namespace Atelier\Modules;

use RuntimeException;

/**
 * Manifeste invalide : porte l'identifiant (ou le nom de répertoire) et la liste des erreurs.
 */
final class ManifestException extends RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly string $moduleId, public readonly array $errors)
    {
        parent::__construct(sprintf('Manifeste du module "%s" invalide : %s', $moduleId, implode(' ', $errors)));
    }
}
