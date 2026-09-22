<?php

declare(strict_types=1);

namespace Atelier\Modules\Maintenance;

use DateTimeImmutable;

/**
 * Règles d'échéance des tâches d'entretien : calcul de l'état d'une tâche (à jour, rappel, à faire,
 * en retard) à partir de sa prochaine échéance par date et/ou par compteur, et calcul de la
 * prochaine échéance après une intervention. Classe pure, sans accès à la base : testable isolément.
 *
 * Une tâche peut avoir deux échéances (« tous les 15 000 km ou tous les 12 mois ») : l'état retenu
 * est le plus pressant des deux. Le rappel anticipé (lead_days, lead_meter) est propre à chaque tâche.
 */
final class Scheduler
{
    public const CLOSED = 'closed';     // tâche clôturée
    public const NONE = 'none';         // aucune échéance définie
    public const OK = 'ok';             // échéance lointaine
    public const SOON = 'soon';         // dans la fenêtre de rappel anticipé
    public const DUE = 'due';           // échéance atteinte aujourd'hui / au compteur exact
    public const OVERDUE = 'overdue';   // échéance dépassée

    /** Ordre de gravité croissante des états (pour tri et comparaison). */
    public const SEVERITY = [self::CLOSED => 0, self::NONE => 1, self::OK => 2, self::SOON => 3, self::DUE => 4, self::OVERDUE => 5];

    /** États qui déclenchent un rappel. */
    public const ALERTING = [self::SOON, self::DUE, self::OVERDUE];

    /**
     * État d'une tâche à une date donnée.
     *
     * @param array<string, mixed> $job status, next_due_at (AAAA-MM-JJ|null), next_due_meter, lead_days, lead_meter
     * @param int|null $assetMeter relevé courant du compteur de l'équipement
     * @return array{code: string, days_left: ?int, meter_left: ?int, by: ?string}
     *         by = 'date' | 'meter' : critère qui détermine l'état
     */
    public static function state(array $job, ?int $assetMeter, DateTimeImmutable $today): array
    {
        if (($job['status'] ?? 'open') === 'closed') {
            return ['code' => self::CLOSED, 'days_left' => null, 'meter_left' => null, 'by' => null];
        }

        $daysLeft = null;
        $dateCode = null;
        $dueAt = self::parseDay($job['next_due_at'] ?? null);
        if ($dueAt !== null) {
            $daysLeft = self::daysBetween($today, $dueAt);
            $dateCode = self::codeFor($daysLeft, max(0, (int) ($job['lead_days'] ?? 0)));
        }

        $meterLeft = null;
        $meterCode = null;
        $dueMeter = isset($job['next_due_meter']) && $job['next_due_meter'] !== null && $job['next_due_meter'] !== '' ? (int) $job['next_due_meter'] : null;
        if ($dueMeter !== null && $assetMeter !== null) {
            $meterLeft = $dueMeter - $assetMeter;
            $meterCode = self::codeFor($meterLeft, max(0, (int) ($job['lead_meter'] ?? 0)));
        }

        if ($dateCode === null && $meterCode === null) {
            return ['code' => self::NONE, 'days_left' => null, 'meter_left' => $meterLeft, 'by' => null];
        }
        if ($meterCode === null || ($dateCode !== null && self::SEVERITY[$dateCode] >= self::SEVERITY[$meterCode])) {
            return ['code' => (string) $dateCode, 'days_left' => $daysLeft, 'meter_left' => $meterLeft, 'by' => 'date'];
        }
        return ['code' => $meterCode, 'days_left' => $daysLeft, 'meter_left' => $meterLeft, 'by' => 'meter'];
    }

    /**
     * Prochaine échéance et état d'une tâche après une intervention réalisée.
     *
     * @param array<string, mixed> $job kind, interval_days, interval_meter, next_due_meter
     * @return array{status: string, next_due_at: ?string, next_due_meter: ?int}
     */
    public static function reschedule(array $job, DateTimeImmutable $doneAt, ?int $doneMeter): array
    {
        $intervalDays = self::positiveInt($job['interval_days'] ?? null);
        $intervalMeter = self::positiveInt($job['interval_meter'] ?? null);
        if (($job['kind'] ?? 'preventive') === 'corrective' || ($intervalDays === null && $intervalMeter === null)) {
            return ['status' => 'closed', 'next_due_at' => null, 'next_due_meter' => null];
        }
        $nextDate = $intervalDays === null ? null : $doneAt->modify('+' . $intervalDays . ' days')->format('Y-m-d');
        $nextMeter = null;
        if ($intervalMeter !== null) {
            $base = $doneMeter ?? self::positiveInt($job['next_due_meter'] ?? null) ?? self::positiveInt($job['last_done_meter'] ?? null);
            $nextMeter = $base === null ? null : $base + $intervalMeter;
        }
        return ['status' => 'open', 'next_due_at' => $nextDate, 'next_due_meter' => $nextMeter];
    }

    /** Nombre de jours signé entre deux dates calendaires (positif si $to est après $from). */
    public static function daysBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        // Comparaison de jours calendaires : les deux dates sont ramenées à minuit dans un même fuseau.
        $utc = new \DateTimeZone('UTC');
        $a = new DateTimeImmutable($from->format('Y-m-d'), $utc);
        $b = new DateTimeImmutable($to->format('Y-m-d'), $utc);
        $diff = $a->diff($b);
        return (int) $diff->days * ($diff->invert === 1 ? -1 : 1);
    }

    public static function parseDay(?string $day): ?DateTimeImmutable
    {
        if ($day === null || $day === '') {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
        return $parsed !== false && $parsed->format('Y-m-d') === $day ? $parsed : null;
    }

    private static function codeFor(int $left, int $lead): string
    {
        if ($left < 0) {
            return self::OVERDUE;
        }
        if ($left === 0) {
            return self::DUE;
        }
        return $left <= $lead ? self::SOON : self::OK;
    }

    private static function positiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $int = (int) $value;
        return $int > 0 ? $int : null;
    }
}
