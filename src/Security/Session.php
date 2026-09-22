<?php

declare(strict_types=1);

namespace Atelier\Security;

use Atelier\Kernel\Config;
use Atelier\Support\Files;

/**
 * Session PHP native, cookie sécurisé, expirations d'inactivité (60 min) et absolue (12 h).
 *
 * Toutes les données de session Atelier sont regroupées sous la clé "atelier" de $_SESSION.
 */
final class Session
{
    private bool $started = false;

    public function __construct(private readonly Config $config, private readonly bool $secureCookie)
    {
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }
        if (PHP_SAPI === 'cli') {
            // Console et tests : session en mémoire, sans cookie.
            $_SESSION ??= [];
            $_SESSION['atelier'] ??= [];
            $this->started = true;
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }

        $savePath = $this->config->string('session.save_path');
        if ($savePath !== '') {
            Files::ensureDirectory($savePath);
            session_save_path($savePath);
        }

        session_name($this->config->string('session.name', 'ATELIER_SESSION'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => ($this->config->string('app.base_url') ?: '') . '/',
            'domain' => '',
            'secure' => $this->secureCookie,
            'httponly' => true,
            'samesite' => $this->config->string('session.cookie_samesite', 'Lax'),
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) max(3600, $this->config->int('session.absolute_timeout', 43200)));

        session_start();
        $this->started = true;
        if (!isset($_SESSION['atelier']) || !is_array($_SESSION['atelier'])) {
            $_SESSION['atelier'] = [];
        }
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION['atelier'][$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION['atelier'][$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION['atelier'][$key]);
    }

    public function has(string $key): bool
    {
        return isset($_SESSION['atelier'][$key]);
    }

    /** Régénère l'identifiant de session (après connexion ou changement de privilèges). */
    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /** Détruit la session et son cookie. */
    public function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'],
            ]);
            session_destroy();
        }
        $this->started = false;
    }

    /**
     * Message flash à usage unique (ex. après redirection de la page de connexion).
     */
    public function flash(string $key, mixed $value): void
    {
        $_SESSION['atelier']['_flash'][$key] = $value;
    }

    public function pullFlash(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION['atelier']['_flash'][$key] ?? $default;
        unset($_SESSION['atelier']['_flash'][$key]);
        return $value;
    }

    public function id(): string
    {
        return session_id() ?: '';
    }
}
