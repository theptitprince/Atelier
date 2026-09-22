<?php

declare(strict_types=1);

namespace Atelier\Kernel;

use Atelier\Activity\ActivityLog;
use Atelier\Error\AuthenticationRequiredException;
use Atelier\Error\ErrorHandler;
use Atelier\Error\ForbiddenException;
use Atelier\Error\NotFoundException;
use Atelier\Error\StorageUnavailableException;
use Atelier\Http\Request;
use Atelier\Http\Response;
use Atelier\Logging\Logger;
use Atelier\Modules\ActionResult;
use Atelier\Modules\ModuleContext;
use Atelier\Modules\ModuleManager;
use Atelier\Modules\ModuleSynchronizer;
use Atelier\Modules\ModuleView;
use Atelier\Persistence\Database;
use Atelier\Security\Acl\AclService;
use Atelier\Security\Auth;
use Atelier\Security\Csrf;
use Atelier\Security\PasswordPolicy;
use Atelier\Security\Session;
use Atelier\Security\UserRepository;
use Atelier\Shared\AttachmentService;
use Atelier\Shared\DatasetCatalog;
use Atelier\Shared\FileCrypto;
use Atelier\Shared\InfoRegistry;
use Atelier\Shared\RelationService;
use Atelier\Shared\SharedServices;
use Atelier\Shared\TagService;
use Atelier\Support\Clock;
use Atelier\View\Template;
use Throwable;

/**
 * Noyau : construction des services, routage du point d'entrée unique, contrôles d'accès,
 * dispatch vers les modules et traitement homogène des erreurs.
 *
 * Schéma d'URL :
 *   /                         interface générale (ou redirection vers /login)
 *   /login, /logout           authentification
 *   /m/{module}[/{route}]     vue de module : JSON si requête Atelier, sinon interface générale ;
 *                             action ou réponse brute (téléchargement) selon la route
 *   /api/{module}/{route}     alias explicite pour les actions
 *   /core/{endpoint}          services du noyau (nav, session, password, preferences, badges)
 *   /module-assets/{m}/{path} ressources statiques déclarées par un module
 *   /files/{id}               téléchargement d'une pièce jointe après contrôle des ACL
 */
final class Application
{
    public readonly Config $config;
    public readonly Logger $logger;
    public readonly Autoloader $autoloader;
    public readonly Database $db;
    public readonly Template $template;
    public readonly Session $session;
    public readonly Csrf $csrf;
    public readonly PasswordPolicy $passwords;
    public readonly UserRepository $users;
    public readonly ActivityLog $activity;
    public readonly Auth $auth;
    public readonly AclService $acl;
    public readonly Settings $settings;
    public readonly ModuleManager $modules;
    public readonly SharedServices $shared;
    public readonly ErrorHandler $errors;

    private ?ModuleSynchronizer $synchronizer = null;

    private function __construct(string $rootPath, Autoloader $autoloader, ?Config $config = null, ?Database $db = null)
    {
        $this->autoloader = $autoloader;
        $this->config = $config ?? Config::load($rootPath);
        Clock::setDisplayTimezone($this->config->string('app.timezone', 'Europe/Paris'));
        date_default_timezone_set('UTC');

        $this->logger = new Logger(
            $this->config->path('logs'),
            $this->config->string('logging.level', 'debug'),
            $this->config->int('logging.technical_retention_days', 30)
        );
        $this->db = $db ?? Database::fromConfig($this->config);
        $this->template = new Template();
        $this->template->addRoot('core', __DIR__ . '/templates');

        $secure = $this->config->get('session.cookie_secure');
        $this->session = new Session($this->config, is_bool($secure) ? $secure : $this->detectHttps());
        $this->csrf = new Csrf($this->session, $this->config->string('security.csrf_header', 'X-CSRF-Token'), $this->config->string('security.csrf_token_name', '_token'));
        $this->passwords = new PasswordPolicy($this->config->int('security.password_min_length', 12));
        $this->users = new UserRepository($this->db);
        $this->activity = new ActivityLog($this->db);
        $this->auth = new Auth($this->config, $this->session, $this->users, $this->passwords, $this->activity);
        $this->acl = new AclService($this->db);
        $this->settings = new Settings($this->db);
        $this->modules = new ModuleManager($this->config->path('modules'), $this->config->path('modules_config'), $this->autoloader, $this->config, $this->logger);
        $this->shared = new SharedServices(
            new InfoRegistry($this->db),
            new TagService($this->db),
            new RelationService($this->db),
            new AttachmentService($this->db, $this->config, $this->config->bool('attachments.encryption', true) ? new FileCrypto($this->config->path('attachments_key')) : null),
            new DatasetCatalog($this->db, $this->acl),
            new \Atelier\Shared\AttachmentFolderService($this->db),
        );
        $this->errors = new ErrorHandler($this->logger, $this->template, $this->config->isDebug(), $this->activity, $this->config->string('app.base_url'));
    }

    /**
     * Construit l'application pour le web ou la console.
     */
    public static function boot(string $rootPath, ?Config $config = null, ?Database $db = null): self
    {
        $rootPath = rtrim(str_replace('\\', '/', $rootPath), '/');
        $autoloader = new Autoloader();
        $autoloader->addNamespace('Atelier', $rootPath . '/src');
        $autoloader->register();
        return new self($rootPath, $autoloader, $config, $db);
    }

    public function synchronizer(): ModuleSynchronizer
    {
        return $this->synchronizer ??= new ModuleSynchronizer(
            $this->db,
            $this->modules,
            dirname(__DIR__) . '/Persistence/migrations/core',
            $this->config->path('cache'),
            $this->activity
        );
    }

    public function baseUrl(): string
    {
        return $this->config->string('app.base_url');
    }

    /** Contexte remis aux modules pour la requête courante. */
    public function context(Request $request): ModuleContext
    {
        return new ModuleContext(
            $this->config,
            $this->db,
            $this->auth,
            $this->acl,
            $this->activity,
            $this->settings,
            $this->template,
            $this->logger,
            $this->csrf,
            $this->users,
            $this->shared,
            $this->modules,
            $request
        );
    }

    /** Point d'entrée web. */
    public function run(): void
    {
        $this->errors->register();
        $request = Request::fromGlobals($this->baseUrl());
        $response = $this->handle($request);
        $response->send();
    }

    /**
     * Traite une requête et retourne toujours une réponse (les erreurs sont converties).
     */
    public function handle(Request $request): Response
    {
        $this->activity->setRequestId(\Atelier\Support\Str::random(6));
        $this->activity->setLevel($this->config->string('logging.activity_level', 'standard'));
        $this->activity->setContext(null, null, $request->ip());
        try {
            $this->session->start();
            $this->modules->discover();
            $response = $this->dispatch($request);
        } catch (StorageUnavailableException $e) {
            $response = $this->maintenance($request, $e);
        } catch (Throwable $e) {
            $response = $this->errors->handle($e, $request);
        }
        return $this->secure($response, $request);
    }

    private function dispatch(Request $request): Response
    {
        $segments = $request->segments();
        $first = $segments[0] ?? '';
        $core = new CoreController($this);

        switch ($first) {
            case '':
                return $core->shell($request, null, null);

            case 'login':
                return $request->isPost() ? $core->loginSubmit($request) : $core->loginForm($request);

            case 'logout':
                return $core->logout($request);

            case 'module-assets':
                return $core->moduleAsset($segments[1] ?? '', implode('/', array_slice($segments, 2)));

            case 'files':
                $this->requireUser($request);
                return $core->file($request, $segments[1] ?? '');

            case 'core':
                $this->requireUser($request);
                $started = microtime(true);
                $response = $core->endpoint($request, $segments[1] ?? '', array_slice($segments, 2));
                if (!in_array($segments[1] ?? '', ['ping', 'badges', 'session'], true)) {
                    $this->activity->debug('core', 'debug.endpoint', $request->method() . ' /core/' . ($segments[1] ?? ''), ['status' => $response->status()], null, (int) round((microtime(true) - $started) * 1000));
                }
                return $response;

            case 'm':
            case 'api':
                $moduleId = $segments[1] ?? '';
                $route = implode('/', array_slice($segments, 2));
                if ($moduleId === '') {
                    throw new NotFoundException();
                }
                if ($first === 'm' && !$request->isAtelierRequest() && $request->isGet() && !$this->isRawRoute($moduleId, $route, $request)) {
                    return $core->shell($request, $moduleId, $route);
                }
                return $this->dispatchModule($request, $moduleId, $route);

            default:
                throw new NotFoundException('Page introuvable.');
        }
    }

    /**
     * Route de module : contrôle module utilisable, droit d'ouverture, correspondance de route,
     * permission de la route, CSRF pour les écritures, puis exécution.
     */
    public function dispatchModule(Request $request, string $moduleId, string $routePath): Response
    {
        $user = $this->requireUser($request);
        $userId = (int) $user['id'];
        $this->activity->setContext($userId, (string) $user['username'], $request->ip());
        $this->synchronizer()->syncIfNeeded();

        $descriptor = $this->modules->requireUsable($moduleId);
        $moduleResource = AclService::module($moduleId);
        $this->acl->require($userId, $moduleResource, 'open', 'Vous n’avez pas accès au module « ' . $descriptor->name() . ' ».');

        if ($this->auth->mustChangePassword()) {
            throw new ForbiddenException('Vous devez d’abord modifier votre mot de passe temporaire.', null, null);
        }

        $context = $this->context($request);
        [$module, $routes] = $this->modules->boot($moduleId, $context);

        $routePath = $routePath === '' ? $descriptor->defaultRoute() : $routePath;
        $match = $routes->match($routePath, $request->method());
        $route = $match['route'];

        $resource = $route['resource'] === null ? $moduleResource : $moduleResource . '/' . trim((string) $route['resource'], '/');
        $this->acl->require($userId, $resource, (string) $route['permission']);

        if ($request->isWrite()) {
            $this->csrf->verify($request);
        }

        // Trace de diagnostic : chaque vue/action exécutée, avec sa durée et son issue.
        $started = microtime(true);
        $traceAction = 'debug.' . $route['kind'];
        $traceDetails = ['route' => $routePath, 'pattern' => $route['pattern'], 'method' => $request->method(), 'params' => $match['params'], 'query' => array_keys($request->allQuery()), 'permission' => $route['permission'], 'resource' => $resource];
        try {
            $result = ($route['handler'])($request, $match['params']);
        } catch (Throwable $e) {
            $outcome = $e instanceof \Atelier\Error\AtelierException ? ($e->kind() === 'validation' ? ActivityLog::FAILURE : ($e->kind() === 'forbidden' ? ActivityLog::DENIED : ActivityLog::ERROR)) : ActivityLog::ERROR;
            $this->activity->record($moduleId, $traceAction, $outcome, 'route:' . $routePath, ($e instanceof \Atelier\Error\AtelierException ? $e->kind() : $e::class) . ' — ' . \Atelier\Support\Str::truncate($e->getMessage(), 200), $traceDetails, null, ActivityLog::DEBUG, (int) round((microtime(true) - $started) * 1000));
            throw $e;
        }
        $this->activity->debug($moduleId, $traceAction, sprintf('%s %s/%s', $request->method(), $moduleId, $routePath), $traceDetails, 'route:' . $routePath, (int) round((microtime(true) - $started) * 1000));

        if ($route['kind'] === 'raw') {
            if (!$result instanceof Response) {
                throw new \LogicException('Une route brute doit retourner une Response.');
            }
            return $result;
        }

        if ($route['kind'] === 'view') {
            if (!$result instanceof ModuleView) {
                throw new \LogicException('Une route de vue doit retourner une ModuleView.');
            }
            $data = $result->toArray();
            // Route canonique renvoyée au client : chemin + chaîne de requête (filtres, page, tri) pour que
            // l'URL, l'historique et ctx.refresh() conservent l'état de la vue.
            $query = $request->allQuery();
            $data['route'] ??= $routePath . ($query !== [] ? '?' . http_build_query($query) : '');
            $data['module'] = $this->moduleClientInfo($descriptor, $module);
            return Response::json($data);
        }

        // action
        if ($result instanceof Response) {
            return $result;
        }
        if ($result instanceof ActionResult) {
            $payload = [
                'ok' => true,
                'data' => $result->data(),
                'message' => $result->message(),
                'level' => $result->level(),
                'errorId' => null,
                'error' => null,
                'directives' => $result->directives(),
            ];
            return Response::jsonRaw($payload);
        }
        return Response::json($result);
    }

    /** @return array<string, mixed> */
    private function moduleClientInfo(\Atelier\Modules\ModuleDescriptor $descriptor, \Atelier\Modules\ModuleInterface $module): array
    {
        $manifest = $descriptor->manifest;
        $base = $this->baseUrl() . '/module-assets/' . $descriptor->id . '/';
        $version = '?v=' . rawurlencode($descriptor->version());
        $assets = $manifest?->assets() ?? ['css' => [], 'js' => [], 'vendor' => []];
        $css = [];
        $js = [];
        foreach ($assets['vendor'] as $vendor) {
            if ($vendor['type'] === 'css') {
                $css[] = $base . $vendor['path'] . $version;
            } else {
                $js[] = $base . $vendor['path'] . $version;
            }
        }
        foreach ($assets['css'] as $path) {
            $css[] = $base . $path . $version;
        }
        foreach ($assets['js'] as $path) {
            $js[] = $base . $path . $version;
        }
        return [
            'id' => $descriptor->id,
            'name' => $descriptor->name(),
            'icon' => $descriptor->icon(),
            'version' => $descriptor->version(),
            'defaultRoute' => $descriptor->defaultRoute(),
            'keepAlive' => $manifest?->keepAlive() ?? false,
            'assets' => ['css' => $css, 'js' => $js],
        ];
    }

    /**
     * Une navigation directe vers une route "raw" (téléchargement) doit être servie telle quelle,
     * sans interface générale. On détecte la nature de la route sans l'exécuter.
     */
    private function isRawRoute(string $moduleId, string $routePath, Request $request): bool
    {
        try {
            $descriptor = $this->modules->get($moduleId);
            if ($descriptor === null || !$descriptor->isUsable() || !$this->auth->isAuthenticated()) {
                return false;
            }
            [, $routes] = $this->modules->boot($moduleId, $this->context($request));
            $match = $routes->match($routePath === '' ? $descriptor->defaultRoute() : $routePath, $request->method());
            return $match['route']['kind'] === 'raw';
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    public function requireUser(Request $request): array
    {
        $user = $this->auth->user();
        if ($user === null) {
            throw $this->auth->sessionExpired() ? AuthenticationRequiredException::expired() : new AuthenticationRequiredException();
        }
        $this->activity->setContext((int) $user['id'], (string) $user['username'], $request->ip());
        return $user;
    }

    /**
     * Stockage indisponible : refus par défaut, aucune donnée protégée, état de maintenance.
     */
    private function maintenance(Request $request, StorageUnavailableException $e): Response
    {
        $reference = \Atelier\Support\Str::errorReference();
        $this->logger->exception($e, $reference);
        if ($request->isAtelierRequest() || $request->wantsJson()) {
            return Response::jsonError($e->getMessage(), 'unavailable', 503, $reference, ['maintenance' => true]);
        }
        $tree = $this->modules->navigationTree(null, null, $this->baseUrl());
        $html = $this->template->render('core::maintenance', [
            'appName' => $this->config->string('app.name', 'Atelier'),
            'baseUrl' => $this->baseUrl(),
            'errorId' => $reference,
            'tree' => $tree,
            'debug' => $this->config->isDebug() ? $e->getPrevious()?->getMessage() : null,
        ]);
        return Response::html($html, 503)->withHeader('Retry-After', '60');
    }

    /** En-têtes de sécurité communs. */
    private function secure(Response $response, Request $request): Response
    {
        foreach ($this->config->array('security.headers') as $name => $value) {
            if ($response->header((string) $name) === null) {
                $response->withHeader((string) $name, (string) $value);
            }
        }
        $contentType = $response->header('Content-Type') ?? '';
        if (str_starts_with($contentType, 'text/html')) {
            $csp = $this->config->string('security.csp');
            if ($csp !== '') {
                $response->withHeader('Content-Security-Policy', $csp);
            }
            if ($response->header('Cache-Control') === null) {
                $response->withHeader('Cache-Control', 'no-store');
            }
        }
        if ($request->isSecure() && $this->config->isProduction()) {
            $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        return $response;
    }

    private function detectHttps(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';
        if ($https !== '' && strtolower((string) $https) !== 'off') {
            return true;
        }
        return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }
}
