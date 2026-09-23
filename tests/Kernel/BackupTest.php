<?php

declare(strict_types=1);

namespace Atelier\Tests\Kernel;

use Atelier\Kernel\Backup;
use Atelier\Support\Clock;
use Atelier\Testing\TestCase;
use DateTimeImmutable;
use DateTimeZone;

final class BackupTest extends TestCase
{
    /**
     * Deux sauvegardes lancées dans la même seconde (double clic sur le bouton, script planifié)
     * doivent produire deux répertoires distincts. Auparavant la seconde échouait sur
     * « output file already exists » et l'utilisateur recevait une erreur serveur.
     */
    public function testTwoBackupsInTheSameSecondDoNotCollide(): void
    {
        $app = $this->application();
        $app->synchronizer()->syncAll();
        Clock::freeze(new DateTimeImmutable('2026-09-23 10:00:00', new DateTimeZone('UTC')));
        try {
            $backup = new Backup($app->db, $app->config);
            $first = $backup->create();
            $second = $backup->create();
            $this->assertSame('20260923-100000', $first);
            $this->assertSame('20260923-100000-2', $second);
            $this->assertNotSame($first, $second);
            $this->assertCount(2, $backup->list());
            foreach ([$first, $second] as $name) {
                $this->assertTrue(is_file($backup->directory() . '/' . $name . '/atelier.sqlite'));
                $this->assertTrue(is_file($backup->directory() . '/' . $name . '/manifest.json'));
            }
        } finally {
            Clock::freeze(null);
        }
    }

    /** Le manifeste recense les tables sauvegardées, base de la vérification à la restauration. */
    public function testBackupManifestCountsTables(): void
    {
        $app = $this->application();
        $app->synchronizer()->syncAll();
        $backup = new Backup($app->db, $app->config);
        $name = $backup->create('essai');
        $manifest = \Atelier\Support\Json::readFile($backup->directory() . '/' . $name . '/manifest.json');
        $this->assertStringContains('essai', $name);
        $this->assertTrue(isset($manifest['tables']['users']));
        $this->assertSame(64, strlen((string) $manifest['database_sha256']));
    }
}
