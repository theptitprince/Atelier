<?php

declare(strict_types=1);

namespace Atelier\View;

use Atelier\Support\Clock;
use Atelier\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Rendu de gabarits PHP. Chaque gabarit reçoit ses variables extraites, plus les helpers
 * $e (échappement), $date/$datetime (formatage) et $this (pour inclure des partiels via $this->render()).
 */
final class Template
{
    /** @var array<string, string> alias de répertoire => chemin absolu */
    private array $roots = [];

    public function addRoot(string $alias, string $directory): void
    {
        $this->roots[$alias] = rtrim(str_replace('\\', '/', $directory), '/');
    }

    /**
     * Rend "alias::chemin/du/gabarit" ou un chemin absolu.
     *
     * @param array<string, mixed> $vars
     */
    public function render(string $name, array $vars = []): string
    {
        $file = $this->resolve($name);
        if (!is_file($file)) {
            throw new RuntimeException('Gabarit introuvable : ' . $name);
        }

        $e = static fn (mixed $value): string => Str::e($value);
        $date = static fn (?string $stored): string => Clock::formatDate($stored);
        $datetime = static fn (?string $stored): string => Clock::formatDateTime($stored);

        $level = ob_get_level();
        ob_start();
        try {
            (function () use ($file, $vars, $e, $date, $datetime): void {
                extract($vars, EXTR_SKIP);
                require $file;
            })();
            return (string) ob_get_clean();
        } catch (Throwable $exception) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $exception;
        }
    }

    public function exists(string $name): bool
    {
        try {
            return is_file($this->resolve($name));
        } catch (RuntimeException) {
            return false;
        }
    }

    private function resolve(string $name): string
    {
        if (str_contains($name, '::')) {
            [$alias, $path] = explode('::', $name, 2);
            if (!isset($this->roots[$alias])) {
                throw new RuntimeException('Espace de gabarits inconnu : ' . $alias);
            }
            $path = str_replace('\\', '/', $path);
            if (str_contains($path, '..')) {
                throw new RuntimeException('Chemin de gabarit invalide : ' . $name);
            }
            return $this->roots[$alias] . '/' . ltrim($path, '/') . (str_ends_with($path, '.php') ? '' : '.php');
        }
        return $name;
    }
}
