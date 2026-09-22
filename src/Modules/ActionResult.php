<?php

declare(strict_types=1);

namespace Atelier\Modules;

/**
 * Résultat d'une action : données, message utilisateur et directives pour le client
 * (recharger la vue, naviguer vers une route du module, fermer l'onglet, mettre à jour la barre d'état).
 */
final class ActionResult
{
    /** @var array<string, mixed> */
    private array $directives = [];

    private function __construct(private readonly mixed $data, private readonly ?string $message, private readonly string $level)
    {
    }

    public static function ok(mixed $data = null, ?string $message = null): self
    {
        return new self($data, $message, 'success');
    }

    public static function info(mixed $data = null, ?string $message = null): self
    {
        return new self($data, $message, 'info');
    }

    public static function warning(mixed $data = null, ?string $message = null): self
    {
        return new self($data, $message, 'warning');
    }

    /** Recharge la vue courante après l'action. */
    public function refresh(): self
    {
        $this->directives['refresh'] = true;
        return $this;
    }

    /** Charge une autre route du module. */
    public function navigate(string $route): self
    {
        $this->directives['navigate'] = $route;
        return $this;
    }

    /** Ferme l'onglet du module. */
    public function close(): self
    {
        $this->directives['close'] = true;
        return $this;
    }

    /** Met à jour le texte contextuel de la barre d'état. */
    public function status(string $text): self
    {
        $this->directives['status'] = $text;
        return $this;
    }

    /** Positionne l'indicateur de modifications non enregistrées de l'onglet. */
    public function dirty(bool $dirty): self
    {
        $this->directives['dirty'] = $dirty;
        return $this;
    }

    /** Remplace le HTML du bandeau. */
    public function banner(string $html): self
    {
        $this->directives['banner'] = $html;
        return $this;
    }

    public function data(): mixed
    {
        return $this->data;
    }

    public function message(): ?string
    {
        return $this->message;
    }

    public function level(): string
    {
        return $this->level;
    }

    /** @return array<string, mixed> */
    public function directives(): array
    {
        return $this->directives;
    }
}
