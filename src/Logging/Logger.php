<?php

declare(strict_types=1);

namespace Atelier\Logging;

use Atelier\Support\Clock;
use Atelier\Support\Files;
use Atelier\Support\Json;
use Throwable;

/**
 * Journal technique : un fichier par jour dans var/logs, rotation par suppression des fichiers
 * plus anciens que la durée configurée. Distinct du journal d'activité (base de données).
 */
final class Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    private int $minLevel;

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $memory = [];

    public function __construct(
        private readonly string $directory,
        string $minLevel = 'debug',
        private readonly int $retentionDays = 30,
        private readonly bool $keepInMemory = false,
    ) {
        $this->minLevel = self::LEVELS[$minLevel] ?? 0;
    }

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function exception(Throwable $e, string $reference, array $context = []): void
    {
        $this->error(sprintf('[%s] %s: %s', $reference, $e::class, $e->getMessage()), $context + [
            'file' => $e->getFile() . ':' . $e->getLine(),
            'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 25),
            'previous' => $e->getPrevious() ? $e->getPrevious()->getMessage() : null,
        ]);
    }

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        if ((self::LEVELS[$level] ?? 0) < $this->minLevel) {
            return;
        }
        if ($this->keepInMemory) {
            $this->memory[] = ['level' => $level, 'message' => $message, 'context' => $context];
        }

        $line = sprintf(
            "%s [%s] %s%s\n",
            Clock::utc(),
            strtoupper($level),
            $message,
            $context === [] ? '' : ' ' . $this->encodeContext($context)
        );

        try {
            Files::ensureDirectory($this->directory);
            @file_put_contents($this->file(), $line, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // Le journal ne doit jamais faire échouer la requête.
        }
    }

    public function file(): string
    {
        return rtrim($this->directory, '/') . '/atelier-' . Clock::now()->format('Y-m-d') . '.log';
    }

    /** Supprime les journaux plus anciens que la durée de rétention ; à appeler ponctuellement. */
    public function rotate(): int
    {
        return Files::purgeOlderThan($this->directory, $this->retentionDays, 'atelier-*.log');
    }

    /** @return list<array{level: string, message: string, context: array<string, mixed>}> */
    public function memory(): array
    {
        return $this->memory;
    }

    /** @param array<string, mixed> $context */
    private function encodeContext(array $context): string
    {
        try {
            return Json::encode($context);
        } catch (Throwable) {
            return '{"context":"non sérialisable"}';
        }
    }
}
