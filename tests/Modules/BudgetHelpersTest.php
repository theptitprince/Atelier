<?php

declare(strict_types=1);

namespace Atelier\Tests\Modules;

use Atelier\Modules\Budget\Forecast;
use Atelier\Modules\Budget\Money;
use Atelier\Modules\Budget\Period;
use Atelier\Modules\Budget\SavingsRepository;
use Atelier\Testing\TestCase;

/** Classes pures du module Budget : montants, périodes, projection, économies enregistrées. */
final class BudgetHelpersTest extends TestCase
{
    public function setUp(): void
    {
        // Enregistre l'autoloader du module (espace de noms Atelier\Modules\Budget).
        $this->application()->modules->discover();
    }

    public function testMoneyParseAndFormat(): void
    {
        $this->assertSame(12550, Money::parse('125,50'));
        $this->assertSame(12550, Money::parse('125.5'));
        $this->assertSame(125000, Money::parse('1 250 €'));
        $this->assertSame(125050, Money::parse('1.250,50'));
        $this->assertSame(125050, Money::parse('1,250.50'));
        $this->assertSame(-1200, Money::parse('-12,00'));
        $this->assertSame(-1200, Money::parse('(12,00)'));
        $this->assertSame(1200, Money::parse('+12'));
        $this->assertNull(Money::parse('abc'));
        $this->assertNull(Money::parse('12,345'));
        $this->assertNull(Money::parse(''));
        // Non-régression : le moins typographique produit par format() doit être relu (le test du
        // premier octet ne pouvait jamais reconnaître « − », codé sur trois octets en UTF-8).
        $this->assertSame(-4520, Money::parse('−45,20'));
        $this->assertSame(-125050, Money::parse('−1 250,50 €'));
        $this->assertSame(-4520, Money::parse(Money::format(-4520)), 'aller-retour format() → parse()');
        $this->assertSame('1 250,50 €', Money::format(125050));
        $this->assertSame('−12,00 €', Money::format(-1200));
        $this->assertSame('+12,00 €', Money::format(1200, '', true));
        $this->assertSame('—', Money::format(null));
        $this->assertSame('12,00', Money::input(-1200, true));
    }

    public function testPeriodArithmetic(): void
    {
        $this->assertSame('2026-09-22', Period::normalizeDay('22/09/2026'));
        $this->assertNull(Period::normalizeDay('31/02/2026'));
        $this->assertSame('2026-09', Period::normalizeMonth('2026-09'));
        $this->assertNull(Period::normalizeMonth('2026-13'));
        $this->assertSame(['2026-02-01', '2026-02-28'], Period::monthRange('2026-02'));
        $this->assertSame('2027-01', Period::addMonths('2026-11', 2));
        $this->assertSame('2025-12', Period::addMonths('2026-01', -1));
        $this->assertSame(14, Period::monthsBetween('2025-11', '2027-01'));
        $this->assertSame('septembre 2026', Period::monthLabel('2026-09'));
        $this->assertSame('2026-02-28', Period::addInterval('2026-01-31', 'month', 1), 'fin de mois bornée');
        $this->assertSame('2026-03-31', Period::addInterval('2026-01-31', 'month', 2));
        $this->assertSame('2027-02-28', Period::addInterval('2026-02-28', 'year', 1));
        $this->assertSame('2026-10-06', Period::addInterval('2026-09-22', 'week', 2));
        $this->assertSame('2026-09-25', Period::addInterval('2026-09-22', 'day', 3));
    }

    public function testForecastProjection(): void
    {
        $recurrings = [
            ['label' => 'Salaire', 'amount' => 250000, 'interval_unit' => 'month', 'interval_count' => 1, 'next_at' => '2026-10-28', 'ends_at' => null, 'active' => true],
            ['label' => 'Loyer', 'amount' => -85000, 'interval_unit' => 'month', 'interval_count' => 1, 'next_at' => '2026-10-05', 'ends_at' => '2026-11-30', 'active' => true],
            ['label' => 'Assurance', 'amount' => -42000, 'interval_unit' => 'year', 'interval_count' => 1, 'next_at' => '2026-12-15', 'ends_at' => null, 'active' => true],
            ['label' => 'Inactive', 'amount' => -99999, 'interval_unit' => 'month', 'interval_count' => 1, 'next_at' => '2026-10-01', 'ends_at' => null, 'active' => false],
        ];
        $externals = [['day' => '2026-11-10', 'label' => 'Entretien : chaudière', 'amount' => -15000, 'source' => 'maintenance']];
        $rows = Forecast::project(100000, '2026-10', 3, $recurrings, $externals);
        $this->assertCount(3, $rows);
        $this->assertSame('2026-10', $rows[0]['month']);
        $this->assertSame(250000, $rows[0]['income']);
        $this->assertSame(-85000, $rows[0]['expense']);
        $this->assertSame(265000, $rows[0]['closing']);
        // Novembre : salaire, dernier loyer (fin le 30/11), entretien.
        $this->assertSame(265000 + 250000 - 85000 - 15000, $rows[1]['closing']);
        $this->assertCount(3, $rows[1]['items']);
        // Décembre : salaire et assurance annuelle, plus de loyer.
        $this->assertSame($rows[1]['closing'] + 250000 - 42000, $rows[2]['closing']);
        $this->assertFalse(in_array('Loyer', array_column($rows[2]['items'], 'label'), true));
        $this->assertFalse(in_array('Inactive', array_column($rows[0]['items'], 'label'), true));
        $this->assertSame(['2026-10-05', '2026-11-05'], Forecast::occurrences($recurrings[1], '2026-10-01', '2027-03-31'));
    }

    /**
     * Non-régression : le plafond de sécurité des occurrences tronquait la projection à 24 mois
     * d'une récurrence quotidienne (400 occurrences au lieu de 731), faussant le solde projeté.
     */
    public function testDailyRecurringIsNotTruncatedOverTwoYears(): void
    {
        $daily = ['label' => 'Café', 'amount' => -100, 'interval_unit' => 'day', 'interval_count' => 1, 'next_at' => '2026-09-01', 'ends_at' => null, 'active' => true];
        $this->assertCount(731, Forecast::occurrences($daily, '2026-09-01', '2028-08-31'), 'du 01/09/2026 au 31/08/2028 inclus');

        $rows = Forecast::project(0, '2026-09', 24, [$daily]);
        $this->assertCount(24, $rows);
        $this->assertSame(-73100, $rows[23]['closing'], '731 jours à 1,00 €');
        $this->assertSame(-3000, $rows[0]['closing'], 'septembre 2026 : 30 jours');

        // Une récurrence dont l'échéance traîne depuis des années reste rattrapée sans être tronquée.
        $late = $daily;
        $late['next_at'] = '2020-01-01';
        $this->assertCount(731, Forecast::occurrences($late, '2026-09-01', '2028-08-31'));

        // ends_at et le nombre maximal d'occurrences restent respectés.
        $bounded = $daily;
        $bounded['ends_at'] = '2026-09-10';
        $this->assertCount(10, Forecast::occurrences($bounded, '2026-09-01', '2028-08-31'));
        $this->assertCount(5, Forecast::occurrences($daily, '2026-09-01', '2028-08-31', 5));
    }

    public function testRealizedSavings(): void
    {
        $monthly = ['kind' => 'monthly', 'amount' => 1800, 'effective_from' => '2026-05-01', 'effective_to' => null];
        $this->assertSame(1800 * 5, SavingsRepository::realized($monthly, '2026-01-01', '2026-09-22'), 'mai à septembre inclus');
        $this->assertSame(1800 * 12, SavingsRepository::realized($monthly, '2027-01-01', '2027-12-31'));
        $this->assertSame(0, SavingsRepository::realized($monthly, '2025-01-01', '2025-12-31'));
        $ended = $monthly + [];
        $ended['effective_to'] = '2026-06-30';
        $this->assertSame(1800 * 2, SavingsRepository::realized($ended, '2026-01-01', '2026-12-31'));
        $yearly = ['kind' => 'yearly', 'amount' => 12000, 'effective_from' => '2026-07-01', 'effective_to' => null];
        $this->assertSame(3000, SavingsRepository::realized($yearly, '2026-01-01', '2026-09-30'), 'trois douzièmes');
        $oneOff = ['kind' => 'one_off', 'amount' => 5000, 'effective_from' => '2026-03-10', 'effective_to' => null];
        $this->assertSame(5000, SavingsRepository::realized($oneOff, '2026-01-01', '2026-12-31'));
        $this->assertSame(0, SavingsRepository::realized($oneOff, '2027-01-01', '2027-12-31'));
    }
}
