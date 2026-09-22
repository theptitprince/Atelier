<?php

declare(strict_types=1);

namespace Atelier\Tests\Modules;

use Atelier\Modules\Maintenance\Scheduler;
use Atelier\Testing\TestCase;
use DateTimeImmutable;

/**
 * Règles d'échéance du module Entretien : état d'une tâche et replanification après intervention.
 */
final class MaintenanceSchedulerTest extends TestCase
{
    private DateTimeImmutable $today;

    public function setUp(): void
    {
        $this->application()->modules->discover(); // autoloader du module
        $this->today = new DateTimeImmutable('2026-09-22');
    }

    public function testDateStates(): void
    {
        $job = ['status' => 'open', 'lead_days' => 14];
        $this->assertSame(Scheduler::OK, Scheduler::state($job + ['next_due_at' => '2026-12-01'], null, $this->today)['code']);
        $soon = Scheduler::state($job + ['next_due_at' => '2026-10-01'], null, $this->today);
        $this->assertSame(Scheduler::SOON, $soon['code']);
        $this->assertSame(9, $soon['days_left']);
        $this->assertSame('date', $soon['by']);
        $this->assertSame(Scheduler::DUE, Scheduler::state($job + ['next_due_at' => '2026-09-22'], null, $this->today)['code']);
        $overdue = Scheduler::state($job + ['next_due_at' => '2026-09-10'], null, $this->today);
        $this->assertSame(Scheduler::OVERDUE, $overdue['code']);
        $this->assertSame(-12, $overdue['days_left']);
        $this->assertSame(Scheduler::NONE, Scheduler::state($job + ['next_due_at' => null], null, $this->today)['code']);
        $this->assertSame(Scheduler::CLOSED, Scheduler::state(['status' => 'closed', 'next_due_at' => '2026-09-01'], null, $this->today)['code']);
    }

    public function testMeterStatesAndMostPressingWins(): void
    {
        $job = ['status' => 'open', 'lead_days' => 14, 'lead_meter' => 500, 'next_due_at' => '2027-03-01', 'next_due_meter' => 76200];
        $this->assertSame(Scheduler::OK, Scheduler::state($job, 70000, $this->today)['code']);
        $soon = Scheduler::state($job, 75800, $this->today);
        $this->assertSame(Scheduler::SOON, $soon['code']);
        $this->assertSame('meter', $soon['by']);
        $this->assertSame(400, $soon['meter_left']);
        $this->assertSame(Scheduler::DUE, Scheduler::state($job, 76200, $this->today)['code']);
        $this->assertSame(Scheduler::OVERDUE, Scheduler::state($job, 76500, $this->today)['code']);
        // Sans relevé, seule la date compte.
        $this->assertSame(Scheduler::OK, Scheduler::state($job, null, $this->today)['code']);
        // Date dépassée mais compteur lointain : la date l'emporte.
        $this->assertSame('date', Scheduler::state(['next_due_at' => '2026-09-01'] + $job, 70000, $this->today)['by']);
    }

    public function testRescheduleAfterIntervention(): void
    {
        $done = new DateTimeImmutable('2026-09-22');
        $next = Scheduler::reschedule(['kind' => 'preventive', 'interval_days' => 365, 'interval_meter' => 15000], $done, 61200);
        $this->assertSame('open', $next['status']);
        $this->assertSame('2027-09-22', $next['next_due_at']);
        $this->assertSame(76200, $next['next_due_meter']);

        // Compteur non saisi : on repart de la précédente échéance au compteur.
        $next = Scheduler::reschedule(['kind' => 'preventive', 'interval_days' => null, 'interval_meter' => 15000, 'next_due_meter' => 60000], $done, null);
        $this->assertNull($next['next_due_at']);
        $this->assertSame(75000, $next['next_due_meter']);

        // Panne ou tâche ponctuelle : clôturée.
        $this->assertSame('closed', Scheduler::reschedule(['kind' => 'corrective', 'interval_days' => 30], $done, null)['status']);
        $this->assertSame('closed', Scheduler::reschedule(['kind' => 'preventive', 'interval_days' => null, 'interval_meter' => null], $done, 100)['status']);
    }

    public function testParseDayAndDaysBetween(): void
    {
        $this->assertNotNull(Scheduler::parseDay('2026-02-28'));
        $this->assertNull(Scheduler::parseDay('2026-02-30'));
        $this->assertNull(Scheduler::parseDay('22/09/2026'));
        $this->assertSame(-1, Scheduler::daysBetween(new DateTimeImmutable('2026-03-01'), new DateTimeImmutable('2026-02-28')));
        $this->assertSame(366, Scheduler::daysBetween(new DateTimeImmutable('2027-01-01'), new DateTimeImmutable('2028-01-02')));
    }
}
