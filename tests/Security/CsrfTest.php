<?php

declare(strict_types=1);

namespace Atelier\Tests\Security;

use Atelier\Error\CsrfException;
use Atelier\Http\Request;
use Atelier\Kernel\Config;
use Atelier\Security\Csrf;
use Atelier\Security\Session;
use Atelier\Testing\TestCase;

final class CsrfTest extends TestCase
{
    private Csrf $csrf;

    public function setUp(): void
    {
        $_SESSION = [];
        $session = new Session(Config::fromArray([], '/r'), false);
        $session->start();
        $this->csrf = new Csrf($session);
    }

    public function testGetRequestsAreNotChecked(): void
    {
        $this->csrf->verify(Request::create('GET', '/m/notes/list'));
        $this->assertTrue(true);
    }

    public function testMissingTokenOnPostIsRejected(): void
    {
        $this->csrf->token();
        $this->assertThrows(CsrfException::class, fn () => $this->csrf->verify(Request::create('POST', '/m/notes/save')));
    }

    public function testHeaderTokenIsAccepted(): void
    {
        $token = $this->csrf->token();
        $this->csrf->verify(Request::create('POST', '/x', [], [], ['X-CSRF-Token' => $token]));
        $this->assertTrue(true);
    }

    public function testFieldTokenIsAcceptedAndWrongTokenRejected(): void
    {
        $token = $this->csrf->token();
        $this->csrf->verify(Request::create('POST', '/x', [], ['_token' => $token]));
        $this->assertThrows(CsrfException::class, fn () => $this->csrf->verify(Request::create('POST', '/x', [], ['_token' => 'faux'])));
    }

    public function testRotateInvalidatesPreviousToken(): void
    {
        $old = $this->csrf->token();
        $this->csrf->rotate();
        $this->assertFalse($this->csrf->isValid($old));
    }
}
