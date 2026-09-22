<?php

declare(strict_types=1);

namespace Atelier\Modules;

use Atelier\Error\NotFoundException;

/**
 * Routes d'un module. Trois natures :
 *   - view   : produit une ModuleView (bandeau + contenu) chargée dans la zone de travail ;
 *   - action : produit des données JSON (ActionResult ou tableau) ; POST par défaut ;
 *   - raw    : produit une Response complète (téléchargement, CSV...).
 *
 * Motif : segments séparés par "/", paramètres entre accolades ("edit/{id}").
 * Chaque route porte la permission requise et la sous-ressource ACL (relative au module).
 */
final class RouteCollection
{
    /** @var list<array<string, mixed>> */
    private array $routes = [];

    /**
     * @param callable $handler function(Request $request, array $params): ModuleView
     */
    public function view(string $pattern, callable $handler, string $permission = 'open', ?string $resource = null): self
    {
        return $this->add('view', $pattern, $handler, $permission, $resource, ['GET']);
    }

    /**
     * @param callable $handler function(Request $request, array $params): ActionResult|array|null
     * @param list<string> $methods
     */
    public function action(string $pattern, callable $handler, string $permission = 'open', ?string $resource = null, array $methods = ['POST']): self
    {
        return $this->add('action', $pattern, $handler, $permission, $resource, $methods);
    }

    /**
     * @param callable $handler function(Request $request, array $params): Response
     * @param list<string> $methods
     */
    public function raw(string $pattern, callable $handler, string $permission = 'open', ?string $resource = null, array $methods = ['GET']): self
    {
        return $this->add('raw', $pattern, $handler, $permission, $resource, $methods);
    }

    /** @param list<string> $methods */
    private function add(string $kind, string $pattern, callable $handler, string $permission, ?string $resource, array $methods): self
    {
        $normalized = Manifest::normalizeRoute($pattern);
        if ($normalized === null) {
            throw new \InvalidArgumentException('Motif de route invalide : ' . $pattern);
        }
        $this->routes[] = [
            'kind' => $kind,
            'pattern' => $normalized,
            'segments' => explode('/', $normalized),
            'handler' => $handler,
            'permission' => $permission,
            'resource' => $resource,
            'methods' => array_map('strtoupper', $methods),
        ];
        return $this;
    }

    /**
     * Trouve la route correspondant au chemin. Lève NotFoundException si aucune ne correspond,
     * ou MethodNotAllowed (NotFoundException 405) si la méthode ne convient pas.
     *
     * @return array{route: array<string, mixed>, params: array<string, string>}
     */
    public function match(string $path, string $method): array
    {
        $path = Manifest::normalizeRoute($path) ?? 'index';
        $segments = explode('/', $path);
        $methodMismatch = false;

        foreach ($this->routes as $route) {
            $params = $this->matchSegments($route['segments'], $segments);
            if ($params === null) {
                continue;
            }
            if (!in_array(strtoupper($method), $route['methods'], true) && !($method === 'HEAD' && in_array('GET', $route['methods'], true))) {
                $methodMismatch = true;
                continue;
            }
            return ['route' => $route, 'params' => $params];
        }

        if ($methodMismatch) {
            throw new NotFoundException('Méthode non autorisée pour cette route.');
        }
        throw new NotFoundException('Route inconnue dans ce module : ' . $path);
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->routes;
    }

    /**
     * @param list<string> $patternSegments
     * @param list<string> $pathSegments
     * @return array<string, string>|null
     */
    private function matchSegments(array $patternSegments, array $pathSegments): ?array
    {
        $params = [];
        $count = count($patternSegments);
        for ($i = 0; $i < $count; $i++) {
            $pattern = $patternSegments[$i];
            // Paramètre "reste du chemin" : {path*}
            if (str_starts_with($pattern, '{') && str_ends_with($pattern, '*}')) {
                $params[substr($pattern, 1, -2)] = implode('/', array_slice($pathSegments, $i));
                return $params;
            }
            if (!isset($pathSegments[$i])) {
                return null;
            }
            if (str_starts_with($pattern, '{') && str_ends_with($pattern, '}')) {
                $params[substr($pattern, 1, -1)] = $pathSegments[$i];
                continue;
            }
            if ($pattern !== $pathSegments[$i]) {
                return null;
            }
        }
        return count($pathSegments) === $count ? $params : null;
    }
}
