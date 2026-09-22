<?php

declare(strict_types=1);

namespace Atelier\Modules\Home;

use Atelier\Http\Request;
use Atelier\Modules\AbstractModule;
use Atelier\Modules\ModuleView;
use Atelier\Modules\RouteCollection;
use Atelier\Security\Acl\AclService;
use Atelier\Support\Clock;

/**
 * Accueil / tableau de bord : modules accessibles, activité récente de l'utilisateur, état du système.
 */
final class HomeModule extends AbstractModule
{
    public function routes(RouteCollection $r): void
    {
        $r->view('index', [$this, 'index'], permission: 'open');
    }

    public function index(Request $request, array $params): ModuleView
    {
        $user = $this->ctx->user();
        $userId = (int) $user['id'];
        $acl = $this->ctx->acl;

        $modules = [];
        foreach ($this->ctx->modules()->sorted() as $descriptor) {
            if ($descriptor->id === $this->id() || !$descriptor->isUsable()) {
                continue;
            }
            if ($acl->can($userId, AclService::module($descriptor->id), 'open')) {
                $modules[] = $descriptor;
            }
        }

        $recent = $this->ctx->activity->paginate(['user_id' => $userId], 1, 8)['rows'];
        $isAdmin = $acl->can($userId, AclService::ROOT, 'admin');
        $stats = null;
        if ($isAdmin) {
            $stats = [
                'users' => $this->ctx->users->countActive(),
                'modules' => count($this->ctx->modules()->all()),
                'modulesInError' => count(array_filter($this->ctx->modules()->all(), static fn ($d): bool => !$d->isValid())),
                'attachments' => $this->ctx->shared->attachments->usageTotal(),
            ];
        }

        $content = $this->render('index', [
            'user' => $user,
            'modules' => $modules,
            'recent' => $recent,
            'stats' => $stats,
            'lastLogin' => Clock::formatDateTime($user['last_login_at'] ?? null, 'première connexion'),
        ]);
        $banner = $this->renderCore('banner', [
            'icon' => 'home',
            'title' => 'Accueil',
            'subtitle' => 'Bonjour ' . $user['display_name'],
        ]);

        return ModuleView::make('Accueil')->banner($banner)->content($content)->status(count($modules) . ' module(s) accessible(s)');
    }
}
