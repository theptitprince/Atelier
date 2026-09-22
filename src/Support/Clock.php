<?php

declare(strict_types=1);

namespace Atelier\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Convention unique de dates : stockage en UTC au format "Y-m-d H:i:s",
 * affichage dans le fuseau configuré (Europe/Paris par défaut) au format français.
 */
final class Clock
{
    private static string $displayTimezone = 'Europe/Paris';

    /** Horloge figée pour les tests ; null = heure réelle. */
    private static ?DateTimeImmutable $frozen = null;

    public static function setDisplayTimezone(string $timezone): void
    {
        self::$displayTimezone = $timezone;
    }

    public static function freeze(?DateTimeImmutable $at): void
    {
        self::$frozen = $at;
    }

    public static function now(): DateTimeImmutable
    {
        return self::$frozen ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** Horodatage UTC pour la base : "2026-09-22 14:05:00". */
    public static function utc(?DateTimeInterface $at = null): string
    {
        $at ??= self::now();
        return DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function utcFromTimestamp(int $timestamp): string
    {
        return (new DateTimeImmutable('@' . $timestamp))->format('Y-m-d H:i:s');
    }

    /** Convertit une valeur stockée (UTC) en objet DateTimeImmutable, ou null si vide/invalide. */
    public static function parseUtc(?string $stored): ?DateTimeImmutable
    {
        if ($stored === null || $stored === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $stored, new DateTimeZone('UTC'));
        if ($date === false) {
            $date = DateTimeImmutable::createFromFormat('Y-m-d', $stored, new DateTimeZone('UTC'));
        }
        return $date === false ? null : $date;
    }

    /** "22/09/2026 16:05" dans le fuseau d'affichage. */
    public static function formatDateTime(?string $stored, string $empty = ''): string
    {
        $date = self::parseUtc($stored);
        return $date === null ? $empty : $date->setTimezone(new DateTimeZone(self::$displayTimezone))->format('d/m/Y H:i');
    }

    /** "22/09/2026 16:05:32". */
    public static function formatDateTimeSeconds(?string $stored, string $empty = ''): string
    {
        $date = self::parseUtc($stored);
        return $date === null ? $empty : $date->setTimezone(new DateTimeZone(self::$displayTimezone))->format('d/m/Y H:i:s');
    }

    /** "22/09/2026". */
    public static function formatDate(?string $stored, string $empty = ''): string
    {
        $date = self::parseUtc($stored);
        return $date === null ? $empty : $date->setTimezone(new DateTimeZone(self::$displayTimezone))->format('d/m/Y');
    }

    /** Valeur ISO 8601 avec fuseau, utile pour les attributs datetime et le JavaScript. */
    public static function iso(?string $stored): ?string
    {
        $date = self::parseUtc($stored);
        return $date?->format(DATE_ATOM);
    }
}
