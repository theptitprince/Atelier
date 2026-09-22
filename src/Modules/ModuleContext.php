<?php

declare(strict_types=1);

namespace Atelier\Modules;

use Atelier\Activity\ActivityLog;
use Atelier\Error\ForbiddenException;
use Atelier\Error\ModuleUnavailableException;
use Atelier\Http\Request;
use Atelier\Kernel\Config;
use Atelier\Kernel\Settings;
use Atelier\Logging\Logger;
use Atelier\Persistence\Database;
use Atelier\Security\Acl\AclService;
use Atelier\Security\Auth;
use Atelier\Security\Csrf;
use Atelier\Security\UserRepository;
use Atelier\Shared\SharedServices;
use Atelier\View\Template;

/**
 * Services du noyau mis à disposition des modules. Un module ne doit accéder aux données
 * d'un autre module qu'au travers de son service (voir moduleService()).
 */
final class ModuleContext
{
    public function __construct(
        public readonly Config $config,
        public readonly Database $db,
        public readonly Auth $auth,
        public readonly AclService $acl,
        public readonly ActivityLog $activity,
        public readonly Settings $settings,
        public readonly Template $template,
        public readonly Logger $logger,
        public readonly Csrf $csrf,
        public readonly UserRepository $users,
        public readonly SharedServices $shared,
        private readonly ModuleManager $modules,
        private ?Request $request = null,
    ) {
    }

    public function request(): Request
    {
        return $this->request ?? Request::create('GET', '/');
    }

    public function withRequest(Request $request): self
    {
        $clone = clone $this;
        $clone->request = $request;
        return $clone;
    }

    /** @return array<string, mixed> */
    public function user(): array
    {
        return $this->auth->requireUser();
    }

    public function userId(): int
    {
        return (int) $this->user()['id'];
    }

    public function modules(): ModuleManager
    {
        return $this->modules;
    }

    /**
     * Service exposé par un autre module (accès aux jeux de données partagés).
     * Lève ModuleUnavailableException si le module est absent, inactif ou n'expose rien.
     */
    public function moduleService(string $moduleId): object
    {
        $descriptor = $this->modules->get($moduleId);
        if ($descriptor === null || !$descriptor->isUsable()) {
            throw new ModuleUnavailableException('Le module « ' . $moduleId . ' » est indisponible.', $moduleId, $descriptor?->state());
        }
        [$module] = $this->modules->boot($moduleId, $this);
        $service = $module->service();
        if ($service === null) {
            throw new ModuleUnavailableException('Le module « ' . $moduleId . ' » n’expose aucun service.', $moduleId);
        }
        return $service;
    }

    public function can(string $resource, string $permission): bool
    {
        $userId = $this->auth->userId();
        return $userId !== null && $this->acl->can($userId, $resource, $permission);
    }

    public function require(string $resource, string $permission, string $message = ''): void
    {
        $userId = $this->auth->userId();
        if ($userId === null || !$this->acl->can($userId, $resource, $permission)) {
            throw new ForbiddenException($message, $resource, $permission);
        }
    }

    public function baseUrl(): string
    {
        return $this->config->string('app.base_url');
    }

    /**
     * Trace de diagnostic dans le journal d'activité (catégorie debug, ignorée hors niveau debug).
     * Corrélée à la requête courante par son identifiant.
     *
     * @param array<string, mixed> $details
     */
    public function debug(string $moduleId, string $message, array $details = []): void
    {
        $this->activity->debug($moduleId, 'debug.' . $moduleId, $message, $details);
    }
}
