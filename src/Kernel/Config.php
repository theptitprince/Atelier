<?php

declare(strict_types=1);

namespace Atelier\Kernel;

/**
 * Configuration fusionnée : config/app.php < config/env.local.php < variables ATELIER_*.
 *
 * Les valeurs peuvent contenir les jetons %root% et %var% qui sont résolus en chemins absolus.
 */
final class Config
{
    /** @param array<string, mixed> $values */
    private function __construct(private array $values, private readonly string $rootPath)
    {
    }

    public static function load(string $rootPath): self
    {
        $rootPath = rtrim(str_replace('\\', '/', $rootPath), '/');
        $defaults = require $rootPath . '/config/app.php';

        $localFile = $rootPath . '/config/env.local.php';
        $local = is_file($localFile) ? require $localFile : [];
        if (!is_array($local)) {
            $local = [];
        }

        $values = self::merge($defaults, $local);
        $values = self::applyEnvironment($values);

        $config = new self($values, $rootPath);
        $config->resolvePlaceholders();

        return $config;
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values, string $rootPath): self
    {
        $config = new self($values, rtrim(str_replace('\\', '/', $rootPath), '/'));
        $config->resolvePlaceholders();
        return $config;
    }

    /**
     * Lit une valeur par chemin pointé, ex. "database.sqlite.path".
     */
    public function get(string $path, mixed $default = null): mixed
    {
        $node = $this->values;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }
        return $node;
    }

    public function string(string $path, string $default = ''): string
    {
        $value = $this->get($path, $default);
        return is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $path, int $default = 0): int
    {
        $value = $this->get($path, $default);
        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $path, bool $default = false): bool
    {
        $value = $this->get($path, $default);
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
        }
        return (bool) $value;
    }

    /** @return array<mixed> */
    public function array(string $path): array
    {
        $value = $this->get($path, []);
        return is_array($value) ? $value : [];
    }

    public function rootPath(): string
    {
        return $this->rootPath;
    }

    public function path(string $name): string
    {
        return $this->string('paths.' . $name);
    }

    public function isDebug(): bool
    {
        return $this->bool('app.debug');
    }

    public function isProduction(): bool
    {
        return $this->string('app.env') === 'prod';
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * Fusion récursive : les tableaux associatifs sont fusionnés clé par clé,
     * les listes et scalaires sont remplacés.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    public static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && !array_is_list($value)) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    /**
     * ATELIER_APP_DEBUG=0 surcharge app.debug ; ATELIER_DATABASE_SQLITE_PATH surcharge database.sqlite.path.
     * Les segments sont recherchés de façon insensible à la casse dans les clés existantes.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function applyEnvironment(array $values): array
    {
        foreach ($_ENV + getenv() as $name => $raw) {
            if (!is_string($name) || !str_starts_with($name, 'ATELIER_')) {
                continue;
            }
            $segments = explode('_', strtolower(substr($name, 8)));
            $values = self::setByEnvSegments($values, $segments, (string) $raw);
        }
        return $values;
    }

    /**
     * @param array<string, mixed> $node
     * @param list<string> $segments
     * @return array<string, mixed>
     */
    private static function setByEnvSegments(array $node, array $segments, string $raw): array
    {
        // Les clés de configuration peuvent contenir des underscores (idle_timeout) :
        // on tente les regroupements de segments du plus long au plus court.
        for ($take = count($segments); $take >= 1; $take--) {
            $candidate = implode('_', array_slice($segments, 0, $take));
            foreach (array_keys($node) as $key) {
                if (strtolower((string) $key) !== $candidate) {
                    continue;
                }
                $rest = array_slice($segments, $take);
                if ($rest === []) {
                    $node[$key] = self::castLike($node[$key], $raw);
                } elseif (is_array($node[$key])) {
                    $node[$key] = self::setByEnvSegments($node[$key], $rest, $raw);
                }
                return $node;
            }
        }
        return $node;
    }

    private static function castLike(mixed $existing, string $raw): mixed
    {
        if (is_bool($existing)) {
            return in_array(strtolower($raw), ['1', 'true', 'on', 'yes'], true);
        }
        if (is_int($existing)) {
            return (int) $raw;
        }
        if (is_float($existing)) {
            return (float) $raw;
        }
        return $raw;
    }

    private function resolvePlaceholders(): void
    {
        $var = $this->string('paths.var', $this->rootPath . '/var');
        $var = str_replace('%root%', $this->rootPath, $var);
        $this->values = $this->walk($this->values, ['%root%' => $this->rootPath, '%var%' => $var]);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, string> $replacements
     * @return array<string, mixed>
     */
    private function walk(array $node, array $replacements): array
    {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->walk($value, $replacements);
            } elseif (is_string($value) && str_contains($value, '%')) {
                $node[$key] = strtr($value, $replacements);
            }
        }
        return $node;
    }
}
