<?php

declare(strict_types=1);

namespace Atelier\Testing;

use Atelier\Kernel\Application;
use Atelier\Kernel\Config;
use Atelier\Persistence\Database;
use Atelier\Persistence\Migrator;

/**
 * Base des tests du noyau : assertions minimales et fabrique d'application sur base SQLite en mémoire.
 * Aucune dépendance externe (pas de PHPUnit) : voir tests/run.php.
 */
abstract class TestCase
{
    private int $assertions = 0;

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    public function assertionCount(): int
    {
        return $this->assertions;
    }

    // ----- Assertions -----

    protected function assertTrue(mixed $value, string $message = ''): void
    {
        $this->check($value === true, $message ?: 'Vrai attendu, obtenu ' . var_export($value, true));
    }

    protected function assertFalse(mixed $value, string $message = ''): void
    {
        $this->check($value === false, $message ?: 'Faux attendu, obtenu ' . var_export($value, true));
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->check($expected === $actual, $message ?: sprintf('Attendu %s, obtenu %s', var_export($expected, true), var_export($actual, true)));
    }

    protected function assertNotSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->check($expected !== $actual, $message ?: 'Les valeurs ne devraient pas être identiques : ' . var_export($actual, true));
    }

    protected function assertNull(mixed $value, string $message = ''): void
    {
        $this->check($value === null, $message ?: 'Null attendu, obtenu ' . var_export($value, true));
    }

    protected function assertNotNull(mixed $value, string $message = ''): void
    {
        $this->check($value !== null, $message ?: 'Valeur non nulle attendue');
    }

    protected function assertCount(int $expected, array|\Countable $value, string $message = ''): void
    {
        $this->check(count($value) === $expected, $message ?: sprintf('%d éléments attendus, %d obtenus', $expected, count($value)));
    }

    protected function assertContains(mixed $needle, array $haystack, string $message = ''): void
    {
        $this->check(in_array($needle, $haystack, true), $message ?: var_export($needle, true) . ' absent du tableau');
    }

    protected function assertStringContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->check(str_contains($haystack, $needle), $message ?: sprintf('"%s" introuvable dans "%s"', $needle, mb_substr($haystack, 0, 200)));
    }

    protected function assertMatches(string $pattern, string $value, string $message = ''): void
    {
        $this->check(preg_match($pattern, $value) === 1, $message ?: sprintf('"%s" ne correspond pas à %s', $value, $pattern));
    }

    /**
     * @param class-string<\Throwable> $class
     */
    protected function assertThrows(string $class, callable $callback, ?string $messageContains = null): \Throwable
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            $this->check($e instanceof $class, sprintf('Exception %s attendue, %s levée : %s', $class, $e::class, $e->getMessage()));
            if ($messageContains !== null) {
                $this->assertStringContains($messageContains, $e->getMessage());
            }
            return $e;
        }
        $this->check(false, 'Exception ' . $class . ' attendue, aucune levée');
        throw new \LogicException('unreachable');
    }

    private function check(bool $condition, string $message): void
    {
        $this->assertions++;
        if (!$condition) {
            throw new AssertionFailed($message);
        }
    }

    // ----- Fabriques -----

    /** Base SQLite en mémoire avec le schéma du noyau appliqué. */
    protected function database(): Database
    {
        $db = Database::sqliteMemory();
        (new Migrator($db))->migrate('core', dirname(__DIR__) . '/Persistence/migrations/core');
        return $db;
    }

    /**
     * Application complète sur base mémoire et répertoire var temporaire.
     */
    protected function application(?string $modulesDir = null): Application
    {
        $root = dirname(__DIR__, 2);
        $var = sys_get_temp_dir() . '/atelier-tests-' . bin2hex(random_bytes(4));
        @mkdir($var, 0777, true);
        $values = require $root . '/config/app.php';
        $values['app']['debug'] = false;
        $values['paths']['var'] = $var;
        $values['paths']['modules'] = $modulesDir ?? $root . '/modules';
        $values['database'] = ['driver' => 'sqlite', 'sqlite' => ['path' => ':memory:', 'wal' => false]];
        $config = Config::fromArray($values, $root);
        $db = $this->database();
        return Application::boot($root, $config, $db);
    }
}
