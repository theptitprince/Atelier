<?php

declare(strict_types=1);

namespace Atelier\Modules\Budget;

/**
 * Projection du solde sur plusieurs mois à partir des opérations récurrentes et d'échéances externes
 * (coûts estimés des entretiens à venir). Classe pure : pas d'accès à la base.
 */
final class Forecast
{
    /**
     * Occurrences d'une récurrence entre deux jours inclus (bornée par ends_at et un plafond de sécurité).
     *
     * @param array<string, mixed> $recurring next_at, interval_unit, interval_count, ends_at, active
     * @return list<string> jours AAAA-MM-JJ
     */
    public static function occurrences(array $recurring, string $from, string $to, int $max = 400): array
    {
        if (empty($recurring['active']) && isset($recurring['active'])) {
            return [];
        }
        $days = [];
        $day = (string) $recurring['next_at'];
        $end = $recurring['ends_at'] ?? null;
        $guard = 0;
        while ($day <= $to && $guard++ < $max) {
            if ($end !== null && $day > $end) {
                break;
            }
            if ($day >= $from) {
                $days[] = $day;
            }
            $day = Period::addInterval($day, (string) $recurring['interval_unit'], (int) $recurring['interval_count']);
        }
        return $days;
    }

    /**
     * Projection mois par mois.
     *
     * @param int $openingBalance solde de départ (centimes)
     * @param string $fromMonth AAAA-MM du premier mois projeté
     * @param list<array<string, mixed>> $recurrings
     * @param list<array{day: string, label: string, amount: int, source: string}> $externals échéances datées (montant signé)
     * @return list<array{month: string, label: string, income: int, expense: int, net: int, closing: int, items: list<array{day: string, label: string, amount: int, source: string}>}>
     */
    public static function project(int $openingBalance, string $fromMonth, int $months, array $recurrings, array $externals = []): array
    {
        $rows = [];
        $balance = $openingBalance;
        $lastMonth = Period::addMonths($fromMonth, max(1, $months) - 1);
        [$from] = Period::monthRange($fromMonth);
        [, $to] = Period::monthRange($lastMonth);

        $itemsByMonth = [];
        foreach ($recurrings as $recurring) {
            foreach (self::occurrences($recurring, $from, $to) as $day) {
                $itemsByMonth[Period::monthOf($day)][] = ['day' => $day, 'label' => (string) $recurring['label'], 'amount' => (int) $recurring['amount'], 'source' => 'recurring'];
            }
        }
        foreach ($externals as $item) {
            if ($item['day'] >= $from && $item['day'] <= $to) {
                $itemsByMonth[Period::monthOf($item['day'])][] = $item;
            }
        }

        for ($i = 0; $i < max(1, $months); $i++) {
            $month = Period::addMonths($fromMonth, $i);
            $items = $itemsByMonth[$month] ?? [];
            usort($items, static fn (array $a, array $b): int => strcmp($a['day'], $b['day']) ?: strcmp($a['label'], $b['label']));
            $income = 0;
            $expense = 0;
            foreach ($items as $item) {
                if ($item['amount'] >= 0) {
                    $income += $item['amount'];
                } else {
                    $expense += $item['amount'];
                }
            }
            $balance += $income + $expense;
            $rows[] = ['month' => $month, 'label' => Period::monthLabel($month), 'income' => $income, 'expense' => $expense, 'net' => $income + $expense, 'closing' => $balance, 'items' => $items];
        }
        return $rows;
    }
}
