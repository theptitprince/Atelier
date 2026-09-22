<?php

declare(strict_types=1);

namespace Atelier\Tests\Support;

use Atelier\Http\Request;
use Atelier\Http\Response;
use Atelier\Support\Clock;
use Atelier\Support\Files;
use Atelier\Support\Str;
use Atelier\Testing\TestCase;

final class SupportTest extends TestCase
{
    public function testEscapeAndSlugs(): void
    {
        $this->assertSame('&lt;b&gt;&quot;x&quot;&apos;', Str::e('<b>"x"\''));
        $this->assertTrue(Str::isSlug('notes-v2'));
        $this->assertFalse(Str::isSlug('Notes'));
        $this->assertFalse(Str::isSlug('-a'));
        $this->assertSame('MonModule', Str::studly('mon-module'));
        $this->assertMatches('/^ERR-[0-9A-F]{6}$/', Str::errorReference());
        $this->assertSame('1,5 Ko', Str::humanSize(1536));
    }

    public function testClockStoresUtcAndDisplaysParis(): void
    {
        Clock::setDisplayTimezone('Europe/Paris');
        $this->assertSame('22/09/2026 14:05', Clock::formatDateTime('2026-09-22 12:05:00'));
        $this->assertSame('23/09/2026', Clock::formatDate('2026-09-22 23:30:00'), 'minuit passé à Paris');
        $this->assertSame('', Clock::formatDateTime(null));
        $this->assertSame('—', Clock::formatDateTime('', '—'));
        $this->assertMatches('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', Clock::utc());
    }

    public function testAtomicWrite(): void
    {
        $dir = sys_get_temp_dir() . '/atelier-files-' . bin2hex(random_bytes(3));
        $file = $dir . '/sub/test.json';
        Files::writeAtomic($file, 'a');
        Files::writeAtomic($file, 'b');
        $this->assertSame('b', file_get_contents($file));
        $this->assertCount(1, glob($dir . '/sub/*') ?: []);
        $this->assertSame('fichier', Files::sanitizeFilename('../'));
        $this->assertSame('a_b.txt', Files::sanitizeFilename('a/b.txt'));
    }

    public function testRequestInputPrecedenceAndJsonBody(): void
    {
        $request = Request::create('POST', '/x?a=1', ['a' => '1'], ['a' => '2', 'b' => 'form'], ['Content-Type' => 'application/json'], '{"a":"3","n":"7","flag":"on"}');
        $this->assertSame('3', $request->input('a'));
        $this->assertSame('form', $request->input('b'));
        $this->assertSame(7, $request->int('n'));
        $this->assertTrue($request->bool('flag'));
        $this->assertTrue($request->isWrite());
        $this->assertSame(['x'], Request::create('GET', '/x/')->segments());
        $this->assertTrue(Request::create('GET', '/', [], [], ['X-Atelier-Request' => 'json'])->wantsJson());
    }

    public function testJsonEnvelopes(): void
    {
        $ok = Response::json(['a' => 1], 'Fait');
        $this->assertSame(200, $ok->status());
        $this->assertSame(['ok' => true, 'data' => ['a' => 1], 'message' => 'Fait', 'errorId' => null, 'error' => null], $ok->decodedJson());
        $error = Response::jsonError('Non', 'forbidden', 403, 'ERR-1', ['resource' => 'r']);
        $this->assertSame(403, $error->status());
        $this->assertSame('forbidden', $error->decodedJson()['error']['type']);
        $this->assertSame('r', $error->decodedJson()['error']['resource']);
        $this->assertSame('no-store', $error->header('Cache-Control'));
    }
}
