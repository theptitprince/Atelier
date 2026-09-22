<?php

declare(strict_types=1);

namespace Atelier\Modules;

/**
 * Vue produite par une route "view" : bandeau complet du module, contenu de la zone de travail,
 * informations de barre d'état et état initial transmis au JavaScript du module.
 */
final class ModuleView
{
    private string $title = '';
    private string $banner = '';
    private string $content = '';
    private ?string $route = null;

    /** @var array<string, mixed> */
    private array $status = [];

    /** @var array<string, mixed> */
    private array $state = [];

    private bool $dirty = false;

    public static function make(string $title): self
    {
        $view = new self();
        $view->title = $title;
        return $view;
    }

    public function title(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    /** HTML complet du bandeau supérieur (aucune structure imposée par le noyau). */
    public function banner(string $html): self
    {
        $this->banner = $html;
        return $this;
    }

    /** HTML de la zone de travail. */
    public function content(string $html): self
    {
        $this->content = $html;
        return $this;
    }

    /** Route canonique à refléter dans l'URL (par défaut celle demandée). */
    public function route(string $route): self
    {
        $this->route = $route;
        return $this;
    }

    /** Texte contextuel de la barre d'état (ex. "12 éléments affichés") et compléments. */
    public function status(string $text, array $extra = []): self
    {
        $this->status = ['text' => $text] + $extra;
        return $this;
    }

    /** Données initiales pour le JavaScript du module (sérialisées en JSON). */
    public function state(array $state): self
    {
        $this->state = $state;
        return $this;
    }

    public function dirty(bool $dirty = true): self
    {
        $this->dirty = $dirty;
        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'banner' => $this->banner,
            'content' => $this->content,
            'route' => $this->route,
            'status' => $this->status,
            'state' => $this->state,
            'dirty' => $this->dirty,
        ];
    }
}
