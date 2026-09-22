<?php

declare(strict_types=1);

namespace Atelier\Tests\Activity;

use Atelier\Activity\ActivityLog;
use Atelier\Support\Clock;
use Atelier\Support\Json;
use Atelier\Testing\TestCase;

final class ActivityLogTest extends TestCase
{
    public function testSensitiveKeysAreMasked(): void
    {
        $db = $this->database();
        $log = new ActivityLog($db);
        $log->setContext(1, 'alice', '127.0.0.1');
        $log->success('users', 'user.create', 'user:2', 'Créé', ['username' => 'bob', 'password' => 'secret', 'nested' => ['token' => 'abc']]);
        $row = $db->selectOne('SELECT * FROM activity_log');
        $details = Json::decode((string) $row['details']);
        $this->assertSame('[masqué]', $details['password']);
        $this->assertSame('[masqué]', $details['nested']['token']);
        $this->assertSame('bob', $details['username']);
        $this->assertSame('alice', $row['username']);
    }

    public function testFiltersSortAndPagination(): void
    {
        $db = $this->database();
        $log = new ActivityLog($db);
        for ($i = 0; $i < 30; $i++) {
            $log->record($i % 2 ? 'notes' : 'users', 'action.' . ($i % 3), $i % 5 === 0 ? ActivityLog::DENIED : ActivityLog::SUCCESS, 'ref:' . $i);
        }
        $page = $log->paginate([], 2, 10);
        $this->assertSame(30, $page['total']);
        $this->assertCount(10, $page['rows']);
        $this->assertSame(15, $log->paginate(['module_id' => 'notes'], 1, 100)['total']);
        $this->assertSame(6, $log->paginate(['result' => 'denied'], 1, 100)['total']);
        $this->assertSame(10, $log->paginate(['action' => 'action.1'], 1, 100)['total']);
        $this->assertSame(1, $log->paginate(['search' => 'ref:12'], 1, 100)['total']);
        $this->assertCount(3, $log->distinctActions());
        $this->assertSame(['notes', 'users'], $log->distinctModules());
    }

    public function testPurgeRemovesOldEntries(): void
    {
        $db = $this->database();
        $log = new ActivityLog($db);
        Clock::freeze(new \DateTimeImmutable('2024-01-01 00:00:00', new \DateTimeZone('UTC')));
        $log->success('core', 'old');
        Clock::freeze(null);
        $log->success('core', 'recent');
        $this->assertSame(1, $log->purge(12));
        $this->assertSame(1, $log->paginate([], 1, 10)['total']);
    }
}
