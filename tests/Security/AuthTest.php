<?php

declare(strict_types=1);

namespace Atelier\Tests\Security;

use Atelier\Activity\ActivityLog;
use Atelier\Error\ValidationException;
use Atelier\Kernel\Config;
use Atelier\Security\Auth;
use Atelier\Security\PasswordPolicy;
use Atelier\Security\Session;
use Atelier\Security\UserRepository;
use Atelier\Testing\TestCase;

final class AuthTest extends TestCase
{
    private Auth $auth;
    private UserRepository $users;
    private ActivityLog $activity;
    private \Atelier\Persistence\Database $db;

    public function setUp(): void
    {
        $_SESSION = [];
        $this->db = $this->database();
        $config = Config::fromArray([
            'session' => ['idle_timeout' => 3600, 'absolute_timeout' => 43200, 'name' => 'T'],
            'security' => ['lockout_attempts' => 3, 'lockout_duration' => 900, 'progressive_delay_base' => 0],
        ], '/r');
        $session = new Session($config, false);
        $session->start();
        $this->users = new UserRepository($this->db);
        $this->activity = new ActivityLog($this->db);
        $policy = new PasswordPolicy(12);
        $this->auth = new Auth($config, $session, $this->users, $policy, $this->activity);
        $this->users->create(['username' => 'alice', 'password_hash' => $policy->hash('Mot-de-passe-solide'), 'must_change_password' => 0]);
    }

    public function testLoginSucceedsAndIsLogged(): void
    {
        $user = $this->auth->login('alice', 'Mot-de-passe-solide', '127.0.0.1');
        $this->assertSame('alice', $user['username']);
        $this->assertTrue($this->auth->isAuthenticated());
        $rows = $this->activity->paginate(['action' => 'auth.login', 'result' => 'success'], 1, 10);
        $this->assertSame(1, $rows['total']);
    }

    public function testLoginIsCaseInsensitiveOnUsername(): void
    {
        $user = $this->auth->login('ALICE', 'Mot-de-passe-solide', '127.0.0.1');
        $this->assertSame('alice', $user['username']);
    }

    public function testWrongPasswordGivesGenericMessage(): void
    {
        $e = $this->assertThrows(ValidationException::class, fn () => $this->auth->login('alice', 'mauvais', '127.0.0.1'));
        $this->assertSame('Identifiant ou mot de passe incorrect.', $e->getMessage());
        $this->assertFalse($this->auth->isAuthenticated());
    }

    public function testUnknownUserGivesSameMessage(): void
    {
        $e = $this->assertThrows(ValidationException::class, fn () => $this->auth->login('zoe', 'Mot-de-passe-solide', '127.0.0.1'));
        $this->assertSame('Identifiant ou mot de passe incorrect.', $e->getMessage());
    }

    public function testLockoutAfterConsecutiveFailures(): void
    {
        for ($i = 0; $i < 3; $i++) {
            try {
                $this->auth->login('alice', 'mauvais', '127.0.0.1');
            } catch (ValidationException) {
            }
        }
        $user = $this->users->findByUsername('alice');
        $this->assertTrue(UserRepository::isLocked($user));
        $e = $this->assertThrows(ValidationException::class, fn () => $this->auth->login('alice', 'Mot-de-passe-solide', '127.0.0.1'));
        $this->assertStringContains('bloqué', $e->getMessage());
        $this->assertSame(1, $this->activity->paginate(['action' => 'auth.lockout'], 1, 10)['total']);
    }

    public function testDisabledAccountCannotLogin(): void
    {
        $this->users->disable((int) $this->users->findByUsername('alice')['id']);
        $this->assertThrows(ValidationException::class, fn () => $this->auth->login('alice', 'Mot-de-passe-solide', '127.0.0.1'));
    }

    public function testLogoutClearsUser(): void
    {
        $this->auth->login('alice', 'Mot-de-passe-solide', '127.0.0.1');
        $this->auth->logout();
        $this->assertFalse($this->auth->isAuthenticated());
    }

    public function testChangePasswordValidation(): void
    {
        $this->auth->login('alice', 'Mot-de-passe-solide', '127.0.0.1');
        $e = $this->assertThrows(ValidationException::class, fn () => $this->auth->changePassword('mauvais', 'court', 'court'));
        $fields = $e->fieldErrors();
        $this->assertTrue(isset($fields['current_password']));
        $this->assertTrue(isset($fields['password']));
        $this->auth->changePassword('Mot-de-passe-solide', 'Nouveau-mot-de-passe-1', 'Nouveau-mot-de-passe-1');
        $this->auth->logout();
        $this->auth->login('alice', 'Nouveau-mot-de-passe-1', '127.0.0.1');
        $this->assertTrue($this->auth->isAuthenticated());
    }
}
