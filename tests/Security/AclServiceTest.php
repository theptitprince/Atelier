<?php

declare(strict_types=1);

namespace Atelier\Tests\Security;

use Atelier\Error\ForbiddenException;
use Atelier\Persistence\Database;
use Atelier\Security\Acl\AclService;
use Atelier\Security\UserRepository;
use Atelier\Testing\TestCase;

/**
 * Ordre de résolution du cahier des charges §4.3 :
 * 1. ressource la plus précise ; 2. règle utilisateur avant groupes ; 3. entre groupes, refus prévaut ; 4. sinon refus.
 */
final class AclServiceTest extends TestCase
{
    private Database $db;
    private AclService $acl;
    private UserRepository $users;
    private int $alice;
    private int $groupA;
    private int $groupB;

    public function setUp(): void
    {
        $this->db = $this->database();
        $this->acl = new AclService($this->db);
        $this->users = new UserRepository($this->db);
        $this->alice = $this->users->create(['username' => 'alice', 'password_hash' => 'x', 'must_change_password' => 0]);
        $this->groupA = $this->users->createGroup('a', 'Groupe A');
        $this->groupB = $this->users->createGroup('b', 'Groupe B');
        $this->users->setGroups($this->alice, [$this->groupA, $this->groupB]);
    }

    public function testNoRuleMeansDenied(): void
    {
        $this->assertFalse($this->acl->can($this->alice, 'atelier/notes', 'open'));
        $decision = $this->acl->resolve($this->alice, 'atelier/notes', 'open');
        $this->assertStringContains('refus par défaut', $decision->explanation);
    }

    public function testInheritanceFromParentResource(): void
    {
        $this->acl->setRule('group', $this->groupA, 'atelier', 'open', 'allow');
        $this->assertTrue($this->acl->can($this->alice, 'atelier/notes/screen/list', 'open'));
        $this->assertStringContains('héritée', $this->acl->resolve($this->alice, 'atelier/notes/screen/list', 'open')->explanation);
    }

    public function testMorePreciseResourceWins(): void
    {
        $this->acl->setRule('group', $this->groupA, 'atelier', 'open', 'allow');
        $this->acl->setRule('all', null, 'atelier/notes', 'open', 'deny');
        // La règle générale sur la ressource précise prévaut sur la règle de groupe héritée.
        $this->assertFalse($this->acl->can($this->alice, 'atelier/notes', 'open'));
        $this->assertTrue($this->acl->can($this->alice, 'atelier/demo', 'open'));
    }

    public function testUserRuleBeatsGroupRulesAtSamePrecision(): void
    {
        $this->acl->setRule('group', $this->groupA, 'atelier/notes', 'open', 'deny');
        $this->acl->setRule('group', $this->groupB, 'atelier/notes', 'open', 'deny');
        $this->acl->setRule('user', $this->alice, 'atelier/notes', 'open', 'allow');
        $this->assertTrue($this->acl->can($this->alice, 'atelier/notes', 'open'));
        $this->assertSame('user', $this->acl->resolve($this->alice, 'atelier/notes', 'open')->winner['subject_type']);
    }

    public function testDenyWinsBetweenGroupsAtSamePrecision(): void
    {
        $this->acl->setRule('group', $this->groupA, 'atelier/notes', 'open', 'allow');
        $this->acl->setRule('group', $this->groupB, 'atelier/notes', 'open', 'deny');
        $this->assertFalse($this->acl->can($this->alice, 'atelier/notes', 'open'));
    }

    public function testGroupRuleBeatsGeneralRule(): void
    {
        $this->acl->setRule('all', null, 'atelier/notes', 'open', 'deny');
        $this->acl->setRule('group', $this->groupA, 'atelier/notes', 'open', 'allow');
        $this->assertTrue($this->acl->can($this->alice, 'atelier/notes', 'open'));
    }

    public function testAdminImpliesOtherPermissionsUnlessExplicitlyDenied(): void
    {
        $this->acl->setRule('group', $this->groupA, 'atelier', 'admin', 'allow');
        $this->assertTrue($this->acl->can($this->alice, 'atelier/notes', 'delete'));
        $this->acl->setRule('group', $this->groupA, 'atelier/notes', 'delete', 'deny');
        $this->assertFalse($this->acl->can($this->alice, 'atelier/notes', 'delete'));
        $this->assertTrue($this->acl->can($this->alice, 'atelier/notes', 'update'));
    }

    public function testExplicitDenyBeatsImplicitAdminAtSameRank(): void
    {
        $this->acl->setRule('user', $this->alice, 'atelier/notes', 'admin', 'allow');
        $this->acl->setRule('user', $this->alice, 'atelier/notes', 'delete', 'deny');
        $this->assertFalse($this->acl->can($this->alice, 'atelier/notes', 'delete'));
    }

    public function testRequireThrowsForbidden(): void
    {
        $this->assertThrows(ForbiddenException::class, fn () => $this->acl->require($this->alice, 'atelier/notes', 'open'));
    }

    public function testRootAdminCountAndNormalize(): void
    {
        $this->assertSame(0, $this->acl->countRootAdmins());
        $this->acl->setRule('user', $this->alice, AclService::ROOT, 'admin', 'allow');
        $this->assertSame(1, $this->acl->countRootAdmins());
        $this->assertSame(0, $this->acl->countRootAdmins($this->alice));
        $this->assertSame('atelier/notes', AclService::normalize('/notes/'));
        $this->assertSame('atelier', AclService::normalize(''));
        $this->assertSame(['atelier/a/b', 'atelier/a', 'atelier'], AclService::ancestors('atelier/a/b'));
    }

    public function testDisabledUserIsNotCountedAsAdminAndRulesAreReplaced(): void
    {
        $id = $this->acl->setRule('user', $this->alice, AclService::ROOT, 'admin', 'allow');
        $again = $this->acl->setRule('user', $this->alice, AclService::ROOT, 'admin', 'deny');
        $this->assertSame($id, $again);
        $this->assertFalse($this->acl->can($this->alice, AclService::ROOT, 'admin'));
        $this->acl->setRule('user', $this->alice, AclService::ROOT, 'admin', 'allow');
        $this->users->disable($this->alice);
        $this->assertSame(0, $this->acl->countRootAdmins());
    }
}
