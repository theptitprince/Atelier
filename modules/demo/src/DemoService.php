<?php

declare(strict_types=1);

namespace Atelier\Modules\Demo;

use Atelier\Error\ForbiddenException;
use Atelier\Modules\ModuleContext;

/**
 * Service intermodule du jeu de données partagé « demo.item ».
 *
 * Un module consommateur l'obtient par $this->ctx->moduleService('demo') et ne lit jamais la
 * table demo_item directement. Chaque méthode vérifie via le catalogue que le demandeur a le
 * droit de lecture sur le jeu : un jeu privé (demo.secret) n'est jamais exposé ici.
 *
 * Les articles en corbeille sont invisibles pour les autres modules : le service ne lit que
 * par les méthodes « vivantes » du dépôt (all(), find()). Un consommateur n'a donc rien à
 * savoir de la corbeille du module producteur.
 */
final class DemoService
{
    public const DATASET = 'demo.item';

    public function __construct(private readonly ModuleContext $ctx, private readonly ItemRepository $items)
    {
    }

    /**
     * Articles lisibles par l'utilisateur demandeur (champs publics uniquement).
     *
     * @return list<array{id: int, name: string, category: string, quantity: int, price: int, active: bool}>
     */
    public function items(int $viewerUserId): array
    {
        $this->assertReadable($viewerUserId);
        return array_map(static fn (array $row): array => [
            'id' => $row['id'],
            'name' => (string) $row['name'],
            'category' => (string) $row['category'],
            'quantity' => $row['quantity'],
            'price' => $row['price'],
            'active' => $row['active'],
        ], $this->items->all());
    }

    /** Libellé d'un article, ou « Article #id » s'il a disparu ou s'il est en corbeille. */
    public function label(int $viewerUserId, int $id): string
    {
        $this->assertReadable($viewerUserId);
        $item = $this->items->find($id);
        return $item === null ? 'Article #' . $id : (string) $item['name'];
    }

    private function assertReadable(int $viewerUserId): void
    {
        if (!$this->ctx->shared->catalog->canAccess($viewerUserId, self::DATASET, 'read')) {
            throw new ForbiddenException('Accès au jeu de données « ' . self::DATASET . ' » refusé.', 'atelier/demo/data/item', 'read');
        }
    }
}
