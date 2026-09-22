<?php

declare(strict_types=1);

namespace Atelier\Modules;

use Atelier\Error\ForbiddenException;
use Atelier\Security\Acl\AclService;
use Atelier\Support\Str;

/**
 * Base des modules : accès au contexte, rendu des gabarits du module, contrôle des droits
 * relatif au module et helpers d'URL.
 */
abstract class AbstractModule implements ModuleInterface
{
    protected ModuleContext $ctx;

    public function __construct(protected readonly Manifest $manifest)
    {
    }

    public function manifest(): Manifest
    {
        return $this->manifest;
    }

    public function id(): string
    {
        return $this->manifest->id();
    }

    public function boot(ModuleContext $context): void
    {
        $this->ctx = $context;
        $this->ctx->template->addRoot($this->id(), $this->manifest->templatesDirectory());
    }

    public function service(): ?object
    {
        return null;
    }

    public function install(ModuleContext $context): void
    {
    }

    // ----- Rendu -----

    /**
     * Rend un gabarit du répertoire templates/ du module.
     *
     * @param array<string, mixed> $vars
     */
    protected function render(string $template, array $vars = []): string
    {
        return $this->ctx->template->render($this->id() . '::' . $template, $vars + [
            'module' => $this,
            'moduleId' => $this->id(),
            'csrfToken' => $this->ctx->csrf->token(),
            'baseUrl' => $this->ctx->baseUrl(),
        ]);
    }

    /** Rend un gabarit partagé du noyau (composants communs : tableau, pagination, etc.). */
    public function renderCore(string $template, array $vars = []): string
    {
        return $this->ctx->template->render('core::' . $template, $vars + ['baseUrl' => $this->ctx->baseUrl()]);
    }

    /** Vue standard : titre, bandeau et contenu. */
    protected function view(string $title, string $banner, string $content): ModuleView
    {
        return ModuleView::make($title)->banner($banner)->content($content);
    }

    // ----- Droits -----

    /** Chemin ACL d'une sous-ressource du module : atelier/{id}[/sub]. */
    public function resource(?string $sub = null): string
    {
        $base = AclService::module($this->id());
        return $sub === null || $sub === '' ? $base : $base . '/' . trim($sub, '/');
    }

    protected function can(string $permission, ?string $sub = null): bool
    {
        return $this->ctx->can($this->resource($sub), $permission);
    }

    protected function require(string $permission, ?string $sub = null, string $message = ''): void
    {
        if (!$this->can($permission, $sub)) {
            throw new ForbiddenException($message, $this->resource($sub), $permission);
        }
    }

    /** Droits effectifs sur une sous-ressource pour un ensemble de permissions. */
    protected function rights(array $permissions, ?string $sub = null): array
    {
        $userId = $this->ctx->auth->userId();
        if ($userId === null) {
            return array_fill_keys($permissions, false);
        }
        return $this->ctx->acl->effective($userId, $this->resource($sub), $permissions);
    }

    // ----- URL -----

    /** URL d'une route du module (navigation) : /m/{id}/{route}. */
    public function url(string $route = ''): string
    {
        $route = trim($route, '/');
        return $this->ctx->baseUrl() . '/m/' . $this->id() . ($route === '' ? '' : '/' . $route);
    }

    /** URL d'une action JSON du module : /api/{id}/{action}. */
    public function actionUrl(string $action): string
    {
        return $this->ctx->baseUrl() . '/api/' . $this->id() . '/' . trim($action, '/');
    }

    /** URL d'une ressource statique du module. */
    public function assetUrl(string $path): string
    {
        return $this->ctx->baseUrl() . '/module-assets/' . $this->id() . '/' . ltrim($path, '/') . '?v=' . rawurlencode($this->manifest->version());
    }

    protected function e(mixed $value): string
    {
        return Str::e($value);
    }

    /**
     * Journalise une action du module dans le journal d'activité.
     * La catégorie (security, data, admin, technical) est déduite du préfixe de l'action si omise.
     */
    protected function log(string $action, string $result = 'success', ?string $resourceRef = null, ?string $message = null, array $details = [], ?string $category = null): void
    {
        $this->ctx->activity->record($this->id(), $action, $result, $resourceRef, $message, $details, null, $category);
    }

    /** Trace de débogage du module (visible dans le journal d'activité en niveau debug). */
    protected function debug(string $message, array $details = []): void
    {
        $this->ctx->debug($this->id(), $message, $details);
    }
}
