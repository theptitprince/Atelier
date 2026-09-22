<?php

declare(strict_types=1);

namespace Atelier\Modules\Budget;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Arithmétique des jours et des mois calendaires (chaînes AAAA-MM-JJ et AAAA-MM), sans fuseau.
 */
final class Period
{
    private const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    public static function parseDay(?string $day): ?DateTimeImmutable
    {
        if ($day === null || $day === '') {
            return null;
        }
        $day = trim($day);
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $day, $m) === 1) {
            $day = sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone('UTC'));
        return $parsed !== false && $parsed->format('Y-m-d') === $day ? $parsed : null;
    }

    /** Normalise une saisie de jour (AAAA-MM-JJ ou JJ/MM/AAAA) ; null si invalide. */
    public static function normalizeDay(?string $day): ?string
    {
        return self::parseDay($day)?->format('Y-m-d');
    }

    /** « AAAA-MM » valide ou null. */
    public static function normalizeMonth(?string $month): ?string
    {
        if ($month === null || preg_match('/^(\d{4})-(\d{2})$/', trim($month), $m) !== 1) {
            return null;
        }
        $mm = (int) $m[2];
        return $mm >= 1 && $mm <= 12 ? sprintf('%04d-%02d', (int) $m[1], $mm) : null;
    }

    public static function monthOf(string $day): string
    {
        return substr($day, 0, 7);
    }

    /** @return array{0: string, 1: string} premier et dernier jour du mois */
    public static function monthRange(string $month): array
    {
        $first = new DateTimeImmutable($month . '-01', new DateTimeZone('UTC'));
        return [$first->format('Y-m-d'), $first->modify('last day of this month')->format('Y-m-d')];
    }

    public static function addMonths(string $month, int $count): string
    {
        return (new DateTimeImmutable($month . '-01', new DateTimeZone('UTC')))->modify(($count >= 0 ? '+' : '') . $count . ' months')->format('Y-m');
    }

    /** Nombre de mois de $from à $to (0 si même mois, négatif si $to précède $from). */
    public static function monthsBetween(string $fromMonth, string $toMonth): int
    {
        [$y1, $m1] = array_map('intval', explode('-', $fromMonth));
        [$y2, $m2] = array_map('intval', explode('-', $toMonth));
        return ($y2 - $y1) * 12 + ($m2 - $m1);
    }

    /** « septembre 2026 ». */
    public static function monthLabel(string $month): string
    {
        [$y, $m] = array_map('intval', explode('-', $month));
        return (self::MONTHS[$m - 1] ?? $month) . ' ' . $y;
    }

    /** « sept. 2026 » (abrégé pour les tableaux). */
    public static function monthShort(string $month): string
    {
        [$y, $m] = array_map('intval', explode('-', $month));
        $short = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
        return ($short[$m - 1] ?? $month) . ' ' . $y;
    }

    /** « 22/09/2026 ». */
    public static function dayLabel(?string $day, string $empty = '—'): string
    {
        $parsed = self::parseDay($day);
        return $parsed === null ? $empty : $parsed->format('d/m/Y');
    }

    /** Ajoute une périodicité (unité + nombre) à un jour ; les fins de mois sont bornées au dernier jour. */
    public static function addInterval(string $day, string $unit, int $count): string
    {
        $date = self::parseDay($day) ?? new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $count = max(1, $count);
        return match ($unit) {
            'day' => $date->modify('+' . $count . ' days')->format('Y-m-d'),
            'week' => $date->modify('+' . (7 * $count) . ' days')->format('Y-m-d'),
            'year' => self::addMonthsToDay($date, 12 * $count),
            default => self::addMonthsToDay($date, $count),
        };
    }

    private static function addMonthsToDay(DateTimeImmutable $date, int $months): string
    {
        $dayOfMonth = (int) $date->format('j');
        $target = $date->modify('first day of this month')->modify('+' . $months . ' months');
        $last = (int) $target->format('t');
        return $target->setDate((int) $target->format('Y'), (int) $target->format('n'), min($dayOfMonth, $last))->format('Y-m-d');
    }
}
