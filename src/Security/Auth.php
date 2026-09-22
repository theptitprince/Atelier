<?php

declare(strict_types=1);

namespace Atelier\Security;

use Atelier\Activity\ActivityLog;
use Atelier\Error\AuthenticationRequiredException;
use Atelier\Error\ValidationException;
use Atelier\Kernel\Config;
use Atelier\Support\Clock;

/**
 * Authentification locale (identifiant + mot de passe), gestion de la session utilisateur,
 * blocage temporaire après échecs répétés et expirations.
 */
final class Auth
{
    private const KEY_USER = 'user_id';
    private const KEY_LOGIN_AT = 'login_at';
    private const KEY_LAST_ACTIVITY = 'last_activity';
    private const KEY_ATTEMPTS = 'anon_attempts';

    /** @var array<string, mixed>|null */
    private ?array $user = null;
    private bool $resolved = false;
    private ?string $expiryReason = null;

    public function __construct(
        private readonly Config $config,
        private readonly Session $session,
        private readonly UserRepository $users,
        private readonly PasswordPolicy $passwords,
        private readonly ActivityLog $activity,
    ) {
    }

    /**
     * Utilisateur connecté ou null. Applique les expirations d'inactivité et absolue.
     *
     * @return array<string, mixed>|null
     */
    public function user(): ?array
    {
        if ($this->resolved) {
            return $this->user;
        }
        $this->resolved = true;

        $userId = $this->session->get(self::KEY_USER);
        if (!is_int($userId)) {
            return null;
        }

        $now = time();
        $loginAt = (int) $this->session->get(self::KEY_LOGIN_AT, 0);
        $lastActivity = (int) $this->session->get(self::KEY_LAST_ACTIVITY, $now);
        if ($now - $lastActivity > $this->config->int('session.idle_timeout', 3600)) {
            $this->expiryReason = 'idle';
            $this->clearSession();
            return null;
        }
        if ($now - $loginAt > $this->config->int('session.absolute_timeout', 43200)) {
            $this->expiryReason = 'absolute';
            $this->clearSession();
            return null;
        }

        $user = $this->users->find($userId);
        if ($user === null || $user['status'] !== 'active') {
            $this->clearSession();
            return null;
        }

        $this->session->set(self::KEY_LAST_ACTIVITY, $now);
        $this->user = $user;
        return $user;
    }

    public function userId(): ?int
    {
        $user = $this->user();
        return $user === null ? null : (int) $user['id'];
    }

    public function isAuthenticated(): bool
    {
        return $this->user() !== null;
    }

    /** La session vient d'expirer pendant cette requête (utile pour le message à l'utilisateur). */
    public function sessionExpired(): bool
    {
        return $this->expiryReason !== null;
    }

    /** @return array<string, mixed> */
    public function requireUser(): array
    {
        $user = $this->user();
        if ($user === null) {
            throw $this->expiryReason !== null ? AuthenticationRequiredException::expired() : new AuthenticationRequiredException();
        }
        return $user;
    }

    public function mustChangePassword(): bool
    {
        $user = $this->user();
        return $user !== null && (int) $user['must_change_password'] === 1;
    }

    /**
     * Tentative de connexion. Retourne l'utilisateur ou lève ValidationException avec un message générique.
     *
     * @return array<string, mixed>
     */
    public function login(string $username, string $password, string $ip): array
    {
        $username = trim($username);
        $this->applyProgressiveDelay();

        if ($username === '' || $password === '') {
            $this->activity->record('core', 'auth.login', ActivityLog::FAILURE, null, 'Identifiants incomplets', ['username' => $username]);
            throw new ValidationException([], 'Identifiant ou mot de passe incorrect.');
        }

        $user = $this->users->findByUsername($username);
        if ($user === null) {
            $this->bumpAnonymousAttempts();
            $this->activity->record('core', 'auth.login', ActivityLog::FAILURE, null, 'Identifiant inconnu', ['username' => $username]);
            throw new ValidationException([], 'Identifiant ou mot de passe incorrect.');
        }

        $userId = (int) $user['id'];
        if (UserRepository::isLocked($user)) {
            $this->activity->record('core', 'auth.login', ActivityLog::DENIED, 'user:' . $userId, 'Compte temporairement bloqué', ['username' => $username]);
            throw new ValidationException([], 'Compte temporairement bloqué après plusieurs échecs. Réessayez dans quelques minutes.');
        }
        if ($user['status'] !== 'active') {
            $this->activity->record('core', 'auth.login', ActivityLog::DENIED, 'user:' . $userId, 'Compte désactivé', ['username' => $username]);
            throw new ValidationException([], 'Identifiant ou mot de passe incorrect.');
        }

        if (!$this->passwords->verify($password, (string) $user['password_hash'])) {
            $attempts = $this->users->recordFailedAttempt($userId, $this->config->int('security.lockout_attempts', 5), $this->config->int('security.lockout_duration', 900));
            $this->bumpAnonymousAttempts();
            $this->activity->record('core', 'auth.login', ActivityLog::FAILURE, 'user:' . $userId, 'Mot de passe incorrect', ['username' => $username, 'attempts' => $attempts]);
            if ($attempts >= $this->config->int('security.lockout_attempts', 5)) {
                $this->activity->record('core', 'auth.lockout', ActivityLog::DENIED, 'user:' . $userId, 'Blocage temporaire après échecs répétés', ['attempts' => $attempts]);
                throw new ValidationException([], 'Compte temporairement bloqué après plusieurs échecs. Réessayez dans 15 minutes.');
            }
            throw new ValidationException([], 'Identifiant ou mot de passe incorrect.');
        }

        if ($this->passwords->needsRehash((string) $user['password_hash'])) {
            $this->users->update($userId, ['password_hash' => $this->passwords->hash($password)]);
        }

        $this->users->recordSuccessfulLogin($userId);
        $this->session->regenerate();
        $this->session->set(self::KEY_USER, $userId);
        $this->session->set(self::KEY_LOGIN_AT, time());
        $this->session->set(self::KEY_LAST_ACTIVITY, time());
        $this->session->remove(self::KEY_ATTEMPTS);

        $this->resolved = true;
        $this->user = $this->users->find($userId);
        $this->activity->setContext($userId, (string) $user['username'], $ip);
        $this->activity->record('core', 'auth.login', ActivityLog::SUCCESS, 'user:' . $userId, 'Connexion réussie');

        return $this->user ?? $user;
    }

    public function logout(): void
    {
        $user = $this->user();
        if ($user !== null) {
            $this->activity->record('core', 'auth.logout', ActivityLog::SUCCESS, 'user:' . $user['id'], 'Déconnexion');
        }
        $this->session->destroy();
        $this->user = null;
        $this->resolved = true;
    }

    /**
     * Changement de mot de passe par l'utilisateur lui-même (ancien mot de passe requis sauf
     * si le compte est en changement obligatoire).
     */
    public function changePassword(string $current, string $new, string $confirmation): void
    {
        $user = $this->requireUser();
        $userId = (int) $user['id'];
        $errors = [];

        if ((int) $user['must_change_password'] !== 1 || $current !== '') {
            if (!$this->passwords->verify($current, (string) $user['password_hash'])) {
                $errors['current_password'] = 'Le mot de passe actuel est incorrect.';
            }
        }
        if ($new !== $confirmation) {
            $errors['password_confirmation'] = 'La confirmation ne correspond pas.';
        }
        foreach ($this->passwords->validate($new, (string) $user['username']) as $message) {
            $errors['password'] = $message;
            break;
        }
        if ($this->passwords->verify($new, (string) $user['password_hash'])) {
            $errors['password'] = 'Le nouveau mot de passe doit être différent de l’actuel.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->users->setPassword($userId, $this->passwords->hash($new), false);
        $this->session->regenerate();
        $this->user = $this->users->find($userId);
        $this->activity->record('core', 'auth.password_changed', ActivityLog::SUCCESS, 'user:' . $userId, 'Mot de passe modifié par l’utilisateur');
    }

    /** Secondes restantes avant expiration par inactivité. */
    public function secondsUntilIdleExpiry(): int
    {
        $last = (int) $this->session->get(self::KEY_LAST_ACTIVITY, time());
        return max(0, $this->config->int('session.idle_timeout', 3600) - (time() - $last));
    }

    public function loginAt(): ?string
    {
        $at = $this->session->get(self::KEY_LOGIN_AT);
        return is_int($at) ? Clock::utcFromTimestamp($at) : null;
    }

    private function clearSession(): void
    {
        $this->session->remove(self::KEY_USER);
        $this->session->remove(self::KEY_LOGIN_AT);
        $this->session->remove(self::KEY_LAST_ACTIVITY);
        $this->user = null;
    }

    /** Temporisation progressive côté serveur, en complément du blocage de compte. */
    private function applyProgressiveDelay(): void
    {
        $attempts = (int) $this->session->get(self::KEY_ATTEMPTS, 0);
        if ($attempts <= 1 || PHP_SAPI === 'cli') {
            return;
        }
        $base = $this->config->int('security.progressive_delay_base', 1);
        $delay = min(8, $base * ($attempts - 1));
        if ($delay > 0) {
            usleep($delay * 250000); // 0,25 s par palier, plafonné à 2 s
        }
    }

    private function bumpAnonymousAttempts(): void
    {
        $this->session->set(self::KEY_ATTEMPTS, (int) $this->session->get(self::KEY_ATTEMPTS, 0) + 1);
    }
}
