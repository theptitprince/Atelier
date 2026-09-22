<?php

declare(strict_types=1);

namespace Atelier\Tests\Shared;

use Atelier\Error\ValidationException;
use Atelier\Persistence\Database;
use Atelier\Security\Acl\AclService;
use Atelier\Shared\DatasetCatalog;
use Atelier\Shared\InfoRegistry;
use Atelier\Shared\RelationService;
use Atelier\Shared\TagService;
use Atelier\Support\Clock;
use Atelier\Support\Json;
use Atelier\Support\Str;
use Atelier\Testing\TestCase;

final class SharedServicesTest extends TestCase
{
    private Database $db;

    public function setUp(): void
    {
        $this->db = $this->database();
    }

    public function testRegistryGivesStableGlobalIds(): void
    {
        $registry = new InfoRegistry($this->db);
        $a = $registry->register('notes.note', '1', 'Première');
        $again = $registry->register('notes.note', '1', 'Renommée');
        $this->assertSame($a, $again);
        $this->assertSame('Renommée', $registry->get($a)['label']);
        $this->assertSame('notes', $registry->get($a)['module_id']);
        $this->assertNotSame($a, $registry->register('notes.note', '2'));
    }

    public function testTagsAreNormalizedAndUnique(): void
    {
        $registry = new InfoRegistry($this->db);
        $tags = new TagService($this->db);
        $info = $registry->register('notes.note', '1', 'N');
        $tags->attach($info, '#Urgent ');
        $tags->attach($info, 'urgent');
        $tags->attach($info, '  URGENT  ');
        $this->assertCount(1, $tags->tagsOf($info));
        $this->assertSame('urgent', $tags->tagsOf($info)[0]['normalized']);
        $this->assertSame('Urgent', $tags->tagsOf($info)[0]['name']);
        $this->assertSame('a b', Str::normalizeTag('#A   B'));
        $this->assertThrows(ValidationException::class, fn () => $tags->attach($info, '#'));
    }

    public function testPrivateTagsAreScopedPerModule(): void
    {
        $registry = new InfoRegistry($this->db);
        $tags = new TagService($this->db);
        $info = $registry->register('notes.note', '1', 'N');
        $tags->attach($info, 'interne', 'notes');
        $this->assertCount(0, $tags->tagsOf($info, TagService::SHARED));
        $this->assertCount(1, $tags->tagsOf($info, 'notes'));
        $this->assertCount(0, $tags->all(TagService::SHARED));
    }

    public function testMergeAndRenameTags(): void
    {
        $registry = new InfoRegistry($this->db);
        $tags = new TagService($this->db);
        $a = $registry->register('notes.note', '1');
        $b = $registry->register('notes.note', '2');
        $t1 = $tags->attach($a, 'projet');
        $t2 = $tags->attach($b, 'projets');
        $tags->merge((int) $t2['id'], (int) $t1['id']);
        $this->assertNull($tags->find((int) $t2['id']));
        $this->assertCount(2, $tags->infosWithTag((int) $t1['id']));
        $tags->rename((int) $t1['id'], 'Projet client');
        $this->assertSame('projet client', $tags->find((int) $t1['id'])['normalized']);
    }

    public function testRelationsAreTypedAndBidirectional(): void
    {
        $registry = new InfoRegistry($this->db);
        $relations = new RelationService($this->db);
        $a = $registry->register('notes.note', '1', 'A');
        $b = $registry->register('demo.item', '7', 'B');
        $relations->relate('references', $a, $b);
        $this->assertCount(1, $relations->relationsOf($a));
        $this->assertSame('in', $relations->relationsOf($b)[0]['direction']);
        $this->assertSame('A', $relations->relationsOf($b)[0]['other_label']);
        $this->assertThrows(ValidationException::class, fn () => $relations->relate('related', $a, $a));
    }

    public function testCatalogHidesPrivateDatasetsAndChecksAcl(): void
    {
        $acl = new AclService($this->db);
        $catalog = new DatasetCatalog($this->db, $acl);
        $now = Clock::utc();
        $this->db->insert('datasets', ['code' => 'demo.item', 'module_id' => 'demo', 'name' => 'Items', 'visibility' => 'shared', 'tables' => Json::encode(['demo_item']), 'fields' => '{}', 'operations' => Json::encode(['read', 'update']), 'structure_version' => 1, 'is_present' => 1, 'updated_at' => $now]);
        $this->db->insert('datasets', ['code' => 'demo.secret', 'module_id' => 'demo', 'name' => 'Secret', 'visibility' => 'private', 'tables' => '[]', 'fields' => '{}', 'operations' => Json::encode(['read']), 'structure_version' => 1, 'is_present' => 1, 'updated_at' => $now]);
        $this->assertCount(1, $catalog->shared());
        $this->assertNull($catalog->findShared('demo.secret'));
        $this->assertCount(2, $catalog->all());

        $userId = $this->db->insert('users', ['username' => 'u', 'display_name' => 'U', 'password_hash' => 'x', 'created_at' => $now, 'updated_at' => $now]);
        $this->assertFalse($catalog->canAccess($userId, 'demo.item', 'read'));
        $this->assertFalse($catalog->canAccess($userId, 'demo.secret', 'read'));
        $acl->setRule('user', $userId, 'atelier/demo/data/item', 'read', 'allow');
        $this->assertTrue($catalog->canAccess($userId, 'demo.item', 'read'));
        $this->assertFalse($catalog->canAccess($userId, 'demo.item', 'delete'), 'opération non déclarée');
        $this->assertFalse($catalog->canAccess($userId, 'demo.secret', 'read'), 'un jeu privé n’est jamais exposé');
        $this->assertSame(['demo.item'], $catalog->readableCodes($userId));
    }
}
