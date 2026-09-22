<?php

declare(strict_types=1);

namespace Atelier\Tests\Kernel;

use Atelier\Kernel\Config;
use Atelier\Testing\TestCase;

final class ConfigTest extends TestCase
{
    public function testMergeOverridesScalarsAndKeepsSiblings(): void
    {
        $merged = Config::merge(['a' => ['x' => 1, 'y' => 2], 'b' => [1, 2]], ['a' => ['y' => 3], 'b' => [9]]);
        $this->assertSame(['a' => ['x' => 1, 'y' => 3], 'b' => [9]], $merged);
    }

    public function testPlaceholdersAreResolved(): void
    {
        $config = Config::fromArray(['paths' => ['var' => '%root%/var', 'logs' => '%var%/logs']], 'D:/projet');
        $this->assertSame('D:/projet/var/logs', $config->path('logs'));
    }

    public function testDottedAccessAndTypedGetters(): void
    {
        $config = Config::fromArray(['app' => ['debug' => 'true', 'name' => 'Atelier', 'n' => '12']], '/r');
        $this->assertTrue($config->bool('app.debug'));
        $this->assertSame('Atelier', $config->string('app.name'));
        $this->assertSame(12, $config->int('app.n'));
        $this->assertSame('def', $config->string('app.missing', 'def'));
        $this->assertNull($config->get('nope.nope'));
    }

    public function testEnvironmentVariablesOverrideValues(): void
    {
        putenv('ATELIER_APP_DEBUG=0');
        putenv('ATELIER_SESSION_IDLE_TIMEOUT=120');
        try {
            $config = Config::load(dirname(__DIR__, 2));
            $this->assertFalse($config->bool('app.debug'));
            $this->assertSame(120, $config->int('session.idle_timeout'));
        } finally {
            putenv('ATELIER_APP_DEBUG');
            putenv('ATELIER_SESSION_IDLE_TIMEOUT');
        }
    }
}
