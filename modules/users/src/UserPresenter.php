<?php

declare(strict_types=1);

namespace Atelier\Modules\Users;

use Atelier\Support\Str;

/**
 * Aide à l'affichage : libellés d'état, badges, description des sujets et des permissions.
 */
final class UserPresenter
{
    /** @var array<string, string> */
    public const STATUS_LABELS = [
        'active' => 'Actif',
        'disabled' => 'Désactivé',
        'locked' => 'Bloqué temporairement',
        'temporary' => 'Mot de passe temporaire',
    ];

    /** @var array<string, string> */
    public const SUBJECT_LABELS = [
        'all' => 'Tous les utilisateurs connectés',
        'group' => 'Groupe',
        'user' => 'Utilisateur',
    ];

    /**
     * Badges d'état d'un compte (HTML) : actif / désactivé, puis bloqué temporairement et mot de passe temporaire.
     *
     * @param array<string, mixed> $user
     */
    public static function statusBadges(array $user): string
    {
        $html = $user['status'] === 'active'
            ? '<span class="badge badge--success badge--dot">Actif</span>'
            : '<span class="badge badge--muted badge--dot">Désactivé</span>';
        if (AccountRepository::isLocked($user)) {
            $html .= ' <span class="badge badge--danger" title="Bloqué jusqu’à ' . Str::e(\Atelier\Support\Clock::formatDateTime($user['locked_until'] ?? null)) . '">Bloqué temporairement</span>';
        }
        if ((int) ($user['must_change_password'] ?? 0) === 1) {
            $html .= ' <span class="badge badge--warning">Mot de passe temporaire</span>';
        }
        return $html;
    }

    /** Badge d'effet d'une règle. */
    public static function effectBadge(string $effect): string
    {
        return $effect === 'allow'
            ? '<span class="badge badge--success">autorise</span>'
            : '<span class="badge badge--danger">refuse</span>';
    }

    /** Badge de décision effective. */
    public static function decisionBadge(bool $allowed): string
    {
        return $allowed
            ? '<span class="badge badge--success badge--dot">Autorisé</span>'
            : '<span class="badge badge--danger badge--dot">Refusé</span>';
    }

    /**
     * Sujet d'une règle, lisible.
     *
     * @param array<string, mixed> $rule
     */
    public static function subject(array $rule): string
    {
        return match ($rule['subject_type']) {
            'user' => 'Utilisateur ' . ($rule['subject_name'] ?? ('#' . $rule['subject_id'])),
            'group' => 'Groupe ' . ($rule['subject_name'] ?? ('#' . $rule['subject_id'])),
            default => 'Tous les utilisateurs connectés',
        };
    }

    /** Libellé du type de ressource. */
    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            'app' => 'application',
            'module' => 'module',
            'screen' => 'écran',
            'dataset' => 'jeu de données',
            'action' => 'action',
            'group' => 'groupe',
            default => $kind,
        };
    }
}
