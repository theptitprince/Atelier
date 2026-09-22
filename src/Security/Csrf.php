<?php

declare(strict_types=1);

namespace Atelier\Security;

use Atelier\Error\CsrfException;
use Atelier\Http\Request;
use Atelier\Support\Str;

/**
 * Jeton anti-CSRF par session, accepté dans l'en-tête X-CSRF-Token ou le champ _token.
 */
final class Csrf
{
    private const SESSION_KEY = 'csrf_token';

    public function __construct(private readonly Session $session, private readonly string $headerName = 'X-CSRF-Token', private readonly string $fieldName = '_token')
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);
        if (!is_string($token) || $token === '') {
            $token = Str::random(32);
            $this->session->set(self::SESSION_KEY, $token);
        }
        return $token;
    }

    public function rotate(): string
    {
        $token = Str::random(32);
        $this->session->set(self::SESSION_KEY, $token);
        return $token;
    }

    public function isValid(?string $candidate): bool
    {
        $expected = $this->session->get(self::SESSION_KEY);
        return is_string($expected) && is_string($candidate) && $candidate !== '' && hash_equals($expected, $candidate);
    }

    /**
     * Vérifie une requête en écriture ; lève CsrfException si le jeton est absent ou invalide.
     */
    public function verify(Request $request): void
    {
        if (!$request->isWrite()) {
            return;
        }
        $candidate = $request->header($this->headerName);
        if ($candidate === null) {
            $value = $request->input($this->fieldName);
            $candidate = is_string($value) ? $value : null;
        }
        if (!$this->isValid($candidate)) {
            throw new CsrfException();
        }
    }

    public function fieldName(): string
    {
        return $this->fieldName;
    }

    public function headerName(): string
    {
        return $this->headerName;
    }

    /** Champ caché prêt à insérer dans un formulaire. */
    public function field(): string
    {
        return sprintf('<input type="hidden" name="%s" value="%s">', Str::e($this->fieldName), Str::e($this->token()));
    }
}
