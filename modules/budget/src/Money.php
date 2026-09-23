<?php

declare(strict_types=1);

namespace Atelier\Modules\Budget;

/**
 * Montants en centimes : analyse des saisies (« 1 250,50 », « -12.5 €») et formatage français.
 * Classe pure, sans état.
 */
final class Money
{
    public const MAX = 100000000000; // 1 000 000 000 € en centimes

    /** « 1 250,50 € » ; les négatifs sont préfixés du signe moins. */
    public static function format(?int $cents, string $empty = '—', bool $withSign = false): string
    {
        if ($cents === null) {
            return $empty;
        }
        $sign = $cents < 0 ? '−' : ($withSign && $cents > 0 ? '+' : '');
        return $sign . number_format(abs($cents) / 100, 2, ',', ' ') . ' €';
    }

    /** Valeur brute pour un champ de formulaire : « 1250,50 » (sans signe si demandé). */
    public static function input(?int $cents, bool $absolute = false): string
    {
        if ($cents === null) {
            return '';
        }
        $value = $absolute ? abs($cents) : $cents;
        return number_format($value / 100, 2, ',', '');
    }

    /**
     * « 125,50 » / « 125.5 » / « 1 250 € » / « -12,00 » / « (12,00) » → centimes signés ; null si invalide.
     */
    public static function parse(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $clean = str_replace(['€', ' ', "\u{a0}", "\u{202f}", "\t"], '', trim($value));
        $negative = false;
        if (preg_match('/^\((.+)\)$/', $clean, $m) === 1) {
            $negative = true;
            $clean = $m[1];
        }
        // Le signe peut être ASCII (-, +) ou le moins typographique « − » (U+2212, 3 octets) que
        // format() produit : la comparaison porte sur le préfixe, pas sur le premier octet.
        foreach (['-' => true, '−' => true, '+' => false] as $sign => $isNegative) {
            if (str_starts_with($clean, (string) $sign)) {
                $negative = $negative || $isNegative;
                $clean = substr($clean, strlen((string) $sign));
                break;
            }
        }
        // « 1.250,50 » (séparateur de milliers point) ou « 1,250.50 » : le dernier séparateur est la décimale.
        $lastComma = strrpos($clean, ',');
        $lastDot = strrpos($clean, '.');
        if ($lastComma !== false && $lastDot !== false) {
            $decimalSep = $lastComma > $lastDot ? ',' : '.';
            $clean = str_replace($decimalSep === ',' ? '.' : ',', '', $clean);
            $clean = str_replace($decimalSep, '.', $clean);
        } else {
            $clean = str_replace(',', '.', $clean);
        }
        if ($clean === '' || !preg_match('/^\d+(\.\d{1,2})?$/', $clean)) {
            return null;
        }
        $cents = (int) round((float) $clean * 100);
        if ($cents > self::MAX) {
            return null;
        }
        return $negative ? -$cents : $cents;
    }
}
