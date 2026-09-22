<?php

declare(strict_types=1);

namespace Atelier\Tests\Security;

use Atelier\Security\PasswordPolicy;
use Atelier\Testing\TestCase;

final class PasswordPolicyTest extends TestCase
{
    public function testMinimumLengthIsTwelve(): void
    {
        $policy = new PasswordPolicy(12);
        $this->assertCount(1, $policy->validate('court'));
        $this->assertCount(0, $policy->validate('assez-long-mot-de-passe'));
    }

    public function testPasswordMustNotContainUsername(): void
    {
        $policy = new PasswordPolicy(12);
        $errors = $policy->validate('Admin-atelier-2026', 'admin');
        $this->assertCount(1, $errors);
        $this->assertStringContains('identifiant', $errors[0]);
    }

    public function testRepeatedCharacterIsRejected(): void
    {
        $this->assertCount(1, (new PasswordPolicy(12))->validate('aaaaaaaaaaaaaa'));
    }

    public function testHashAndVerify(): void
    {
        $policy = new PasswordPolicy();
        $hash = $policy->hash('123456789azerty');
        $this->assertTrue($policy->verify('123456789azerty', $hash));
        $this->assertFalse($policy->verify('autre', $hash));
        $this->assertFalse($policy->verify('x', ''));
    }

    public function testTemporaryPasswordIsValid(): void
    {
        $policy = new PasswordPolicy(12);
        $temporary = $policy->generateTemporary();
        $this->assertMatches('/^[a-z0-9]{4}(-[a-z0-9]{4}){3}$/', $temporary);
        $this->assertCount(0, $policy->validate($temporary));
    }
}
