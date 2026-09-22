<?php

declare(strict_types=1);

namespace Atelier\Modules;

/**
 * Contrat minimal d'un module. En pratique, on étend AbstractModule.
 */
interface ModuleInterface
{
    public function manifest(): Manifest;

    /** Appelé une fois par requête avant tout traitement du module. */
    public function boot(ModuleContext $context): void;

    /** Déclaration des routes (vues, actions, réponses brutes). */
    public function routes(RouteCollection $routes): void;

    /**
     * Service exposé aux autres modules pour l'accès aux jeux de données partagés.
     * Retourne null si le module n'expose rien.
     */
    public function service(): ?object;

    /** Hook d'installation (après migrations) ; peut créer des données initiales. */
    public function install(ModuleContext $context): void;
}
