<?php

declare(strict_types=1);

namespace Atelier\Error;

/**
 * Erreur de validation : les erreurs par champ sont transmises au client pour affichage près des champs.
 */
final class ValidationException extends AtelierException
{
    protected string $kind = 'validation';
    protected int $httpStatus = 422;

    /** @param array<string, string> $fieldErrors champ => message */
    public function __construct(array $fieldErrors = [], string $message = '')
    {
        parent::__construct($message, ['fields' => $fieldErrors]);
    }

    protected function defaultMessage(): string
    {
        return 'Certaines informations saisies sont invalides.';
    }

    /** @return array<string, string> */
    public function fieldErrors(): array
    {
        return $this->payload['fields'] ?? [];
    }

    public static function single(string $field, string $message): self
    {
        return new self([$field => $message], $message);
    }
}
