<?php

declare(strict_types=1);

namespace Atelier\Tests\Persistence;

use Atelier\Persistence\Database;
use Atelier\Persistence\Migrator;
use Atelier\Persistence\QueryException;
use Atelier\Testing\TestCase;

final class DatabaseTest extends TestCase
{
    public function testMigrationsAreAppliedOnceAndTracked(): void
    {
        $db = Database::sqliteMemory();
        $migrator = new Migrator($db);
        $dir = dirname(__DIR__, 2) . '/src/Persistence/migrations/core';
        $done = $migrator->migrate('core', $dir);
        $available = count($migrator->available($dir));
        $this->assertTrue($available >= 1);
        $this->assertCount($available, $done);
        $this->assertCount(0, $migrator->migrate('core', $dir));
        $this->assertSame($available, $migrator->currentVersion('core'));
        $this->assertTrue($db->tableExists('users'));
        $this->assertTrue($db->tableExists('acl_rules'));
        $this->assertContains('activity_log', $db->tables());
    }

    public function testCrudHelpersAndTransactions(): void
    {
        $db = $this->database();
        $now = '2026-09-22 00:00:00';
        $id = $db->insert('groups', ['name' => 'g', 'label' => 'G', 'created_at' => $now, 'updated_at' => $now]);
        $this->assertSame(3, $id, 'deux groupes système existent déjà');
        $this->assertSame(1, $db->update('groups', ['label' => 'G2'], 'id = :id', ['id' => $id]));
        $this->assertSame('G2', $db->selectOne('SELECT label FROM groups WHERE id = :id', ['id' => $id])['label']);

        try {
            $db->transaction(function (Database $db) use ($now): void {
                $db->insert('groups', ['name' => 'h', 'label' => 'H', 'created_at' => $now, 'updated_at' => $now]);
                throw new \RuntimeException('annulation');
            });
        } catch (\RuntimeException) {
        }
        $this->assertNull($db->selectOne("SELECT id FROM groups WHERE name = 'h'"));
        $this->assertFalse($db->inTransaction());
    }

    public function testUniqueViolationIsDetected(): void
    {
        $db = $this->database();
        $now = '2026-09-22 00:00:00';
        $e = $this->assertThrows(QueryException::class, fn () => $db->insert('groups', ['name' => 'admins', 'label' => 'Doublon', 'created_at' => $now, 'updated_at' => $now]));
        $this->assertTrue($e->isConstraintViolation());
    }

    public function testForeignKeysAreEnforced(): void
    {
        $db = $this->database();
        $this->assertThrows(QueryException::class, fn () => $db->insert('user_groups', ['user_id' => 999, 'group_id' => 1, 'added_at' => 'x']));
    }

    public function testDialectHelpers(): void
    {
        $db = Database::sqliteMemory();
        $this->assertSame('INTEGER PRIMARY KEY AUTOINCREMENT', $db->primaryKey());
        $this->assertSame('"t"', $db->quoteIdentifier('t'));
        $this->assertSame('a || b', $db->concat('a', 'b'));
        $mysql = new Database('mysql', 'mysql:host=x');
        $this->assertSame('`t`', $mysql->quoteIdentifier('t'));
        $this->assertStringContains('AUTO_INCREMENT', $mysql->primaryKey());
        $this->assertStringContains('InnoDB', $mysql->tableOptions());
    }
}
