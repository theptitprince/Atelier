<?php

declare(strict_types=1);

namespace Atelier\Kernel;

/**
 * Autoloader PSR-4 minimal, sans Composer.
 *
 * Le noyau enregistre le préfixe Atelier\ vers src/. Chaque module découvert
 * enregistre ensuite son propre préfixe (déclaré dans son manifeste) vers son
 * répertoire src/.
 */
final class Autoloader
{
    /** @var array<string, string> préfixe de namespace (avec \ final) => répertoire (avec / final) */
    private array $prefixes = [];

    private bool $registered = false;

    public function addNamespace(string $prefix, string $directory): void
    {
        $prefix = trim($prefix, '\\') . '\\';
        $directory = rtrim(str_replace('\\', '/', $directory), '/') . '/';
        $this->prefixes[$prefix] = $directory;
        // Les préfixes les plus longs d'abord afin qu'un sous-namespace prime sur son parent.
        uksort($this->prefixes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
    }

    public function register(): void
    {
        if ($this->registered) {
            return;
        }
        spl_autoload_register([$this, 'load']);
        $this->registered = true;
    }

    public function load(string $class): void
    {
        foreach ($this->prefixes as $prefix => $directory) {
            if (str_starts_with($class, $prefix)) {
                $relative = substr($class, strlen($prefix));
                $file = $directory . str_replace('\\', '/', $relative) . '.php';
                if (is_file($file)) {
                    require $file;
                }
                return;
            }
        }
    }

    /** @return array<string, string> */
    public function prefixes(): array
    {
        return $this->prefixes;
    }
}
