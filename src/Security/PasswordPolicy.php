<?php

declare(strict_types=1);

namespace Atelier\Security;

use Atelier\Support\Str;

/**
 * Politique de mots de passe : longueur minimale (12 par défaut), hachage PHP natif,
 * génération de mots de passe temporaires lisibles.
 */
final class PasswordPolicy
{
    public function __construct(private readonly int $minLength = 12)
    {
    }

    public function minLength(): int
    {
        return $this->minLength;
    }

    /**
     * @return list<string> messages d'erreur (vide si conforme)
     */
    public function validate(string $password, ?string $username = null): array
    {
        $errors = [];
        $length = mb_strlen($password, 'UTF-8');
        if ($length < $this->minLength) {
            $errors[] = sprintf('Le mot de passe doit comporter au moins %d caractères.', $this->minLength);
        }
        if ($length > 200) {
            $errors[] = 'Le mot de passe est trop long (200 caractères maximum).';
        }
        if ($username !== null && $username !== '' && mb_stripos($password, $username, 0, 'UTF-8') !== false) {
            $errors[] = 'Le mot de passe ne doit pas contenir l’identifiant.';
        }
        if (preg_match('/^(.)\1*$/u', $password) === 1) {
            $errors[] = 'Le mot de passe ne doit pas répéter un seul caractère.';
        }
        return $errors;
    }

    public function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public function verify(string $password, string $hash): bool
    {
        return $hash !== '' && password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_DEFAULT);
    }

    /**
     * Mot de passe temporaire : groupes de caractères sans ambiguïté, ex. "kx7t-3mqv-p9rz-w2h".
     */
    public function generateTemporary(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $groups = [];
        for ($g = 0; $g < 4; $g++) {
            $group = '';
            for ($i = 0; $i < 4; $i++) {
                $group .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $groups[] = $group;
        }
        return implode('-', $groups);
    }

    /** Jeton opaque (réinitialisations, etc.). */
    public function token(): string
    {
        return Str::random(24);
    }
}
