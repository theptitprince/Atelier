<?php

declare(strict_types=1);

namespace Atelier\Modules\Profile;

use Atelier\Error\ValidationException;
use Atelier\Http\Request;
use Atelier\Modules\AbstractModule;
use Atelier\Modules\ActionResult;
use Atelier\Modules\ModuleView;
use Atelier\Modules\RouteCollection;
use Atelier\Support\Clock;

/**
 * Profil et préférences de l'utilisateur connecté : informations du compte, nom affiché et email,
 * changement de mot de passe, préférences d'interface (stockées dans la portée "core" pour être
 * lisibles par tous les modules via Settings::preference()).
 */
final class ProfileModule extends AbstractModule
{
    private const DISPLAY_NAME_MAX = 100;
    private const EMAIL_MAX = 190;

    /** Préférences gérées : nom => [valeur par défaut, valeurs admises (null = booléen)]. */
    public const PREFERENCES = [
        'pageSize' => [25, [10, 25, 50, 100]],
        'sidebarCollapsed' => [false, null],
        'confirmClose' => [false, null],
    ];

    public function routes(RouteCollection $r): void
    {
        $r->view('index', [$this, 'index'], permission: 'open');
        $r->view('preferences', [$this, 'preferences'], permission: 'open');
        $r->action('save', [$this, 'save'], permission: 'open');
        $r->action('password', [$this, 'password'], permission: 'open');
        $r->action('save-preferences', [$this, 'savePreferences'], permission: 'open');
    }

    // ----- Vues -----

    public function index(Request $request, array $params): ModuleView
    {
        $user = $this->ctx->user();
        $userId = (int) $user['id'];

        $content = $this->render('index', [
            'user' => $user,
            'groups' => $this->ctx->users->groupsOf($userId),
            'lastLogin' => Clock::formatDateTime($user['last_login_at'] ?? null, 'Première connexion'),
            'passwordChangedAt' => Clock::formatDateTime($user['password_changed_at'] ?? null, 'Jamais (mot de passe initial)'),
            'createdAt' => Clock::formatDateTime($user['created_at'] ?? null, '—'),
            'mustChangePassword' => (int) ($user['must_change_password'] ?? 0) === 1,
        ]);

        $banner = $this->renderCore('banner', [
            'icon' => 'user',
            'title' => 'Mon profil',
            'subtitle' => (string) $user['display_name'] . ' (' . (string) $user['username'] . ')',
            'actions' => $this->bannerActions('index'),
        ]);

        return ModuleView::make('Mon profil')
            ->banner($banner)
            ->content($content)
            ->status('Compte ' . (string) $user['username']);
    }

    public function preferences(Request $request, array $params): ModuleView
    {
        $userId = $this->ctx->userId();
        $stored = $this->ctx->settings->preferencesOf($userId, 'core');
        $values = [];
        foreach (self::PREFERENCES as $name => [$default]) {
            $values[$name] = array_key_exists($name, $stored) ? $stored[$name] : $default;
        }

        $content = $this->render('preferences', [
            'values' => $values,
            'pageSizes' => self::PREFERENCES['pageSize'][1],
        ]);

        $banner = $this->renderCore('banner', [
            'icon' => 'sliders',
            'title' => 'Préférences',
            'subtitle' => 'Affichage et comportement de l’interface',
            'actions' => $this->bannerActions('preferences'),
        ]);

        return ModuleView::make('Préférences')
            ->banner($banner)
            ->content($content)
            ->status('Préférences personnelles');
    }

    // ----- Actions -----

    /** Modification du nom affiché et de l'email de l'utilisateur connecté (et de lui seul). */
    public function save(Request $request, array $params): ActionResult
    {
        $user = $this->ctx->user();
        $userId = (int) $user['id'];

        $displayName = $request->string('display_name');
        $email = $request->string('email');
        $errors = [];

        if ($displayName === '') {
            $errors['display_name'] = 'Le nom affiché est obligatoire.';
        } elseif (mb_strlen($displayName, 'UTF-8') > self::DISPLAY_NAME_MAX) {
            $errors['display_name'] = sprintf('Le nom affiché ne peut dépasser %d caractères.', self::DISPLAY_NAME_MAX);
        } elseif (preg_match('/[\x00-\x1F\x7F<>]/u', $displayName) === 1) {
            $errors['display_name'] = 'Le nom affiché contient des caractères non autorisés.';
        }

        if ($email !== '') {
            if (mb_strlen($email, 'UTF-8') > self::EMAIL_MAX) {
                $errors['email'] = sprintf('L’adresse email ne peut dépasser %d caractères.', self::EMAIL_MAX);
            } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors['email'] = 'L’adresse email est invalide.';
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $changes = [];
        if ($displayName !== (string) $user['display_name']) {
            $changes['display_name'] = $displayName;
        }
        $newEmail = $email === '' ? null : $email;
        if ($newEmail !== ($user['email'] ?? null)) {
            $changes['email'] = $newEmail;
        }

        if ($changes === []) {
            return ActionResult::info(['displayName' => $displayName], 'Aucune modification à enregistrer.');
        }

        $this->ctx->users->update($userId, $changes);
        $this->log('profile.update', 'success', 'user:' . $userId, 'Profil modifié par l’utilisateur', [
            'fields' => array_keys($changes),
            'display_name' => $changes['display_name'] ?? null,
        ]);

        return ActionResult::ok(['displayName' => $displayName], 'Profil enregistré.')->refresh();
    }

    /** Changement de mot de passe : la logique (vérifications, politique, journal) est portée par Auth. */
    public function password(Request $request, array $params): ActionResult
    {
        $this->ctx->auth->changePassword(
            $request->string('current_password'),
            (string) ($request->input('password') ?? ''),
            (string) ($request->input('password_confirmation') ?? '')
        );
        $this->log('profile.update', 'success', 'user:' . $this->ctx->userId(), 'Mot de passe modifié depuis le profil', ['fields' => ['password']]);

        return ActionResult::ok(['csrfToken' => $this->ctx->csrf->token()], 'Votre mot de passe a été modifié.')->refresh();
    }

    /** Enregistrement des préférences d'interface dans la portée "core". */
    public function savePreferences(Request $request, array $params): ActionResult
    {
        $userId = $this->ctx->userId();
        $errors = [];

        $pageSize = $request->int('pageSize', 0) ?? 0;
        if (!in_array($pageSize, self::PREFERENCES['pageSize'][1], true)) {
            $errors['pageSize'] = 'Choisissez une taille de page parmi les valeurs proposées.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $values = [
            'pageSize' => $pageSize,
            'sidebarCollapsed' => $request->bool('sidebarCollapsed'),
            'confirmClose' => $request->bool('confirmClose'),
        ];
        foreach ($values as $name => $value) {
            $this->ctx->settings->setPreference($userId, $name, $value, 'core');
        }
        $this->log('profile.preferences', 'success', 'user:' . $userId, 'Préférences modifiées', $values);

        return ActionResult::ok($values, 'Préférences enregistrées.');
    }

    // ----- Helpers -----

    /** Boutons du bandeau : bascule entre les deux écrans du module. */
    private function bannerActions(string $current): string
    {
        $links = [
            ['index', 'user', 'Profil'],
            ['preferences', 'sliders', 'Préférences'],
        ];
        $html = '<div class="btn-group" role="group" aria-label="Écrans du profil">';
        foreach ($links as [$route, $icon, $label]) {
            $active = $route === $current;
            $html .= '<a class="btn btn--sm' . ($active ? ' is-active' : '') . '" href="#" data-route="' . $this->e($route) . '"' . ($active ? ' aria-current="page"' : '') . '>'
                . '<svg class="icon" aria-hidden="true"><use href="#i-' . $this->e($icon) . '"></use></svg> ' . $this->e($label) . '</a>';
        }
        return $html . '</div>';
    }
}
