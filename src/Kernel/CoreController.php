<?php

declare(strict_types=1);

namespace Atelier\Kernel;

use Atelier\Activity\ActivityLog;
use Atelier\Error\ForbiddenException;
use Atelier\Error\NotFoundException;
use Atelier\Error\ValidationException;
use Atelier\Http\Request;
use Atelier\Http\Response;
use Atelier\Security\Acl\AclService;
use Atelier\Shared\DatasetCatalog;
use Atelier\Support\Clock;
use Atelier\Support\Json;
use Atelier\Support\Str;
use Throwable;

/**
 * Points d'entrée du noyau : interface générale, connexion, déconnexion, services /core/*,
 * ressources statiques des modules et téléchargement des pièces jointes.
 */
final class CoreController
{
    private const ASSET_TYPES = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'mjs' => 'application/javascript; charset=UTF-8',
        'map' => 'application/json; charset=UTF-8',
        'json' => 'application/json; charset=UTF-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
    ];

    public function __construct(private readonly Application $app)
    {
    }

    // ----- Interface générale -----

    public function shell(Request $request, ?string $moduleId, ?string $route): Response
    {
        $user = $this->app->auth->user();
        if ($user === null) {
            $target = $this->app->baseUrl() . '/login';
            if ($request->path() !== '/') {
                $target .= '?next=' . rawurlencode($request->path());
            }
            if ($this->app->auth->sessionExpired()) {
                $target .= (str_contains($target, '?') ? '&' : '?') . 'expired=1';
            }
            return Response::redirect($target);
        }
        $userId = (int) $user['id'];
        $this->app->activity->setContext($userId, (string) $user['username'], $request->ip());
        $this->app->synchronizer()->syncIfNeeded();

        $config = $this->app->config;
        $tree = $this->app->modules->navigationTree($userId, $this->app->acl, $this->app->baseUrl());
        $clientConfig = [
            'appName' => $config->string('app.name', 'Atelier'),
            'version' => $config->string('app.version'),
            'baseUrl' => $this->app->baseUrl(),
            'csrfToken' => $this->app->csrf->token(),
            'csrfHeader' => $this->app->csrf->headerName(),
            'user' => $this->publicUser($user),
            'mustChangePassword' => $this->app->auth->mustChangePassword(),
            'passwordMinLength' => $this->app->passwords->minLength(),
            'sessionIdleSeconds' => $config->int('session.idle_timeout', 3600),
            'initial' => ['module' => $moduleId, 'route' => $route],
            'homeModule' => $this->app->modules->has('home') ? 'home' : null,
            'tree' => $tree,
            'isRootAdmin' => $this->app->acl->can($userId, AclService::ROOT, 'admin'),
            'debug' => $config->isDebug(),
            'serverTime' => Clock::iso(Clock::utc()),
        ];

        $html = $this->app->template->render('core::shell', [
            'appName' => $clientConfig['appName'],
            'version' => $clientConfig['version'],
            'baseUrl' => $this->app->baseUrl(),
            'user' => $user,
            'tree' => $tree,
            'clientConfig' => Json::encode($clientConfig),
            'csrfToken' => $this->app->csrf->token(),
            'minWidth' => $config->int('app.min_width', 1280),
        ]);
        return Response::html($html);
    }

    // ----- Connexion -----

    public function loginForm(Request $request, ?string $error = null, string $username = ''): Response
    {
        if ($this->app->auth->isAuthenticated()) {
            return Response::redirect($this->app->baseUrl() . '/');
        }
        $next = (string) $request->query('next', '');
        $html = $this->app->template->render('core::login', [
            'appName' => $this->app->config->string('app.name', 'Atelier'),
            'version' => $this->app->config->string('app.version'),
            'baseUrl' => $this->app->baseUrl(),
            'csrfField' => $this->app->csrf->field(),
            'error' => $error,
            'username' => $username,
            'next' => $this->safeNext($next),
            'expired' => $request->query('expired') === '1',
            'loggedOut' => $request->query('logout') === '1',
            'storageOk' => $this->app->db->isAvailable(),
        ]);
        return Response::html($html, $error !== null ? 422 : 200);
    }

    public function loginSubmit(Request $request): Response
    {
        $username = $request->string('username');
        $password = (string) ($request->input('password') ?? '');
        $next = $this->safeNext($request->string('next'));
        try {
            $this->app->csrf->verify($request);
            $this->app->auth->login($username, $password, $request->ip());
            $this->app->csrf->rotate();
            if ($request->isAtelierRequest()) {
                return Response::json(['redirect' => $this->app->baseUrl() . ($next ?: '/')]);
            }
            return Response::redirect($this->app->baseUrl() . ($next ?: '/'));
        } catch (ValidationException | \Atelier\Error\CsrfException $e) {
            if ($request->isAtelierRequest()) {
                return Response::jsonError($e->getMessage(), $e->kind(), $e->httpStatus());
            }
            return $this->loginForm($request, $e->getMessage(), $username);
        }
    }

    public function logout(Request $request): Response
    {
        if ($request->isPost()) {
            try {
                $this->app->csrf->verify($request);
            } catch (Throwable) {
                // Déconnexion tolérée même sans jeton valide : l'effet est bénin.
            }
        }
        $this->app->auth->logout();
        if ($request->isAtelierRequest()) {
            return Response::json(['redirect' => $this->app->baseUrl() . '/login?logout=1']);
        }
        return Response::redirect($this->app->baseUrl() . '/login?logout=1');
    }

    // ----- Services /core/* -----

    /** @param list<string> $rest */
    public function endpoint(Request $request, string $name, array $rest): Response
    {
        $user = $this->app->auth->requireUser();
        $userId = (int) $user['id'];

        switch ($name) {
            case 'nav':
                return Response::json(['tree' => $this->app->modules->navigationTree($userId, $this->app->acl, $this->app->baseUrl())]);

            case 'session':
                return Response::json([
                    'authenticated' => true,
                    'user' => $this->publicUser($user),
                    'expiresIn' => $this->app->auth->secondsUntilIdleExpiry(),
                    'csrfToken' => $this->app->csrf->token(),
                    'mustChangePassword' => $this->app->auth->mustChangePassword(),
                    'serverTime' => Clock::iso(Clock::utc()),
                ]);

            case 'password':
                if (!$request->isPost()) {
                    throw new NotFoundException();
                }
                $this->app->csrf->verify($request);
                $this->app->auth->changePassword(
                    (string) ($request->input('current_password') ?? ''),
                    (string) ($request->input('password') ?? ''),
                    (string) ($request->input('password_confirmation') ?? '')
                );
                return Response::json(['csrfToken' => $this->app->csrf->token()], 'Votre mot de passe a été modifié.');

            case 'preferences':
                if ($request->isPost()) {
                    $this->app->csrf->verify($request);
                    $moduleId = $request->string('module', 'core') ?: 'core';
                    foreach ($request->arrayInput('values') as $key => $value) {
                        if (is_string($key) && Str::isSlug(str_replace('.', '-', $key), 64)) {
                            $this->app->settings->setPreference($userId, $key, $value, $moduleId);
                        }
                    }
                    return Response::json(['saved' => true]);
                }
                $moduleId = (string) $request->query('module', 'core');
                return Response::json(['values' => $this->app->settings->preferencesOf($userId, $moduleId ?: 'core')]);

            case 'badges':
                return Response::json(['badges' => $this->badges($request, $userId)]);

            case 'ping':
                return Response::json(['time' => Clock::iso(Clock::utc())]);

            case 'tags':
                // Suggestions de tags partagés pour le composant commun de saisie (data-tags-input).
                $term = trim((string) $request->query('q', ''));
                $scope = (string) $request->query('scope', \Atelier\Shared\TagService::SHARED);
                if (!Str::isSlug($scope, 64) && $scope !== \Atelier\Shared\TagService::SHARED) {
                    $scope = \Atelier\Shared\TagService::SHARED;
                }
                $tags = $term === '' ? array_slice($this->app->shared->tags->all($scope), 0, 20) : $this->app->shared->tags->search($term, $scope, 20);
                return Response::json(['tags' => array_map(static fn (array $t): array => [
                    'id' => (int) $t['id'],
                    'name' => (string) $t['name'],
                    'count' => (int) ($t['usage_count'] ?? 0),
                ], $tags)]);

            case 'changelog':
                return Response::json([
                    'version' => $this->app->config->string('app.version'),
                    'html' => $this->changelogHtml(),
                ]);

            default:
                throw new NotFoundException();
        }
    }

    /**
     * Indicateurs numériques déclarés par les modules (route "badge" du manifeste).
     *
     * @return array<string, mixed>
     */
    private function badges(Request $request, int $userId): array
    {
        $badges = [];
        foreach ($this->app->modules->all() as $descriptor) {
            $badgeRoute = $descriptor->manifest?->badgeRoute();
            if ($badgeRoute === null || !$descriptor->isUsable()) {
                continue;
            }
            if (!$this->app->acl->can($userId, AclService::module($descriptor->id), 'open')) {
                continue;
            }
            try {
                [, $routes] = $this->app->modules->boot($descriptor->id, $this->app->context($request));
                $match = $routes->match($badgeRoute, 'GET');
                $result = ($match['route']['handler'])($request, $match['params']);
                $badges[$descriptor->id] = $result instanceof \Atelier\Modules\ActionResult ? $result->data() : $result;
            } catch (Throwable $e) {
                $this->app->logger->warning('Badge indisponible pour ' . $descriptor->id, ['error' => $e->getMessage()]);
            }
        }
        return $badges;
    }

    // ----- Ressources statiques des modules -----

    /**
     * Sert un fichier statique d'un module. Seuls les types connus sont servis, uniquement
     * depuis le répertoire du module, sans remontée de chemin.
     */
    public function moduleAsset(string $moduleId, string $path): Response
    {
        $descriptor = $this->app->modules->get($moduleId);
        $path = str_replace('\\', '/', $path);
        if ($descriptor === null || $path === '' || str_contains($path, '..') || str_starts_with($path, '/')) {
            throw new NotFoundException();
        }
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!isset(self::ASSET_TYPES[$extension])) {
            throw new NotFoundException();
        }
        $file = $descriptor->directory . '/' . $path;
        $real = realpath($file);
        $root = realpath($descriptor->directory);
        if ($real === false || $root === false || !str_starts_with(str_replace('\\', '/', $real), str_replace('\\', '/', $root) . '/') || !is_file($real)) {
            throw new NotFoundException();
        }
        $content = (string) file_get_contents($real);
        return Response::raw($content, self::ASSET_TYPES[$extension])
            ->withHeader('Cache-Control', 'public, max-age=86400')
            ->withHeader('Last-Modified', gmdate('D, d M Y H:i:s', filemtime($real) ?: time()) . ' GMT');
    }

    // ----- Pièces jointes -----

    /**
     * Téléchargement d'une pièce jointe : l'utilisateur doit pouvoir consulter le jeu de données
     * de l'information rattachée ; un fichier orphelin n'est accessible qu'à son auteur.
     */
    public function file(Request $request, string $attachmentId): Response
    {
        $user = $this->app->auth->requireUser();
        $userId = (int) $user['id'];
        $attachment = $this->app->shared->attachments->find($attachmentId);
        if ($attachment === null) {
            throw new NotFoundException('Pièce jointe introuvable.');
        }
        $allowed = false;
        if ($attachment['info_id'] !== null) {
            $info = $this->app->shared->registry->get((string) $attachment['info_id']);
            if ($info !== null) {
                $allowed = $this->app->acl->can($userId, DatasetCatalog::resource((string) $info['dataset_code']), 'read');
            }
        }
        if (!$allowed && (int) ($attachment['uploaded_by'] ?? 0) === $userId) {
            $allowed = true;
        }
        if (!$allowed) {
            throw new ForbiddenException('Vous ne pouvez pas télécharger cette pièce jointe.');
        }
        $this->app->activity->record('core', 'attachment.download', ActivityLog::SUCCESS, 'attachment:' . $attachmentId, (string) $attachment['original_name']);
        return $this->app->shared->attachments->download($attachmentId, $request->query('inline') === '1');
    }

    // ----- Journal des versions -----

    /**
     * Convertit docs/CHANGELOG.md (sous-ensemble de Markdown : titres, listes, paragraphes,
     * code inline, gras) en HTML échappé.
     */
    private function changelogHtml(): string
    {
        $file = $this->app->config->rootPath() . '/docs/CHANGELOG.md';
        if (!is_file($file)) {
            return '<p class="text-muted">Aucun journal des versions disponible.</p>';
        }
        $html = '';
        $inList = false;
        $closeList = static function () use (&$html, &$inList): void {
            if ($inList) {
                $html .= "</ul>\n";
                $inList = false;
            }
        };
        $inline = static function (string $text): string {
            $text = Str::e($text);
            $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text) ?? $text;
            $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text) ?? $text;
            return $text;
        };
        foreach (preg_split('/\R/', (string) file_get_contents($file)) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                $closeList();
                continue;
            }
            if (preg_match('/^(#{1,4})\s+(.*)$/', $trimmed, $m) === 1) {
                $closeList();
                $level = min(4, strlen($m[1]) + 1); // # du fichier devient h2 dans la fenêtre
                $html .= sprintf("<h%d>%s</h%d>\n", $level, $inline($m[2]), $level);
                continue;
            }
            if (preg_match('/^[-*]\s+(.*)$/', $trimmed, $m) === 1) {
                if (!$inList) {
                    $html .= "<ul>\n";
                    $inList = true;
                }
                $html .= '<li>' . $inline($m[1]) . "</li>\n";
                continue;
            }
            $closeList();
            $html .= '<p>' . $inline($trimmed) . "</p>\n";
        }
        $closeList();
        return $html;
    }

    // ----- Utilitaires -----

    /** @param array<string, mixed> $user @return array<string, mixed> */
    private function publicUser(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'username' => (string) $user['username'],
            'displayName' => (string) $user['display_name'],
            'lastLoginAt' => Clock::formatDateTime($user['last_login_at'] ?? null),
        ];
    }

    /** Autorise uniquement un chemin interne relatif à l'application. */
    private function safeNext(string $next): string
    {
        if ($next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//') || str_contains($next, "\n")) {
            return '';
        }
        return $next;
    }
}
