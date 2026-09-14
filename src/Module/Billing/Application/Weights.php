<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\MissingFigure;
use App\Module\Billing\Domain\Weight;
use App\Module\Finance\Contract\CostRecord;
use App\Module\Property\Contract\HouseholdWindow;
use App\Module\Property\Contract\UnitBrief;
use App\Module\Tenancy\Contract\TenancySpan;
use DateTimeImmutable;

/**
 * Wie viel jede Einheit von einer Kostenart traegt.
 *
 * Sechs Schluesselsorten, sechs Herkuenfte — und eine gemeinsame Regel: was
 * fehlt, wird **nicht** zu null. Eine Null verteilt still um.
 *
 * Die Gewichte sind ganze Zahlen, weil {@see \App\Shared\Money\Money::allocate()}
 * damit rechnet. Die Umrechnung macht {@see Weight}, ohne Fliesskomma.
 */
final readonly class Weights
{
    public function __construct(private DistributionShares $shares)
    {
    }

    /**
     * @param list<UnitBrief>                      $units
     * @param array<string, list<TenancySpan>>     $spans
     * @param array<string, list<HouseholdWindow>> $households
     *
     * @return array{weights: array<string, int>, shown: array<string, string>, missing: list<MissingFigure>}
     */
    public function of(
        CostRecord $cost,
        array $units,
        array $spans,
        array $households,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        $weights = [];
        $shown = [];
        $missing = [];

        foreach ($units as $unit) {
            $value = $this->valueOf($cost, $unit, $spans[$unit->id] ?? [], $households[$unit->id] ?? [], $from, $to);

            if (null === $value) {
                $missing[] = new MissingFigure($unit->id, $unit->label, $cost->kindLabel, self::whatIsMissing($cost));

                continue;
            }

            if ('' !== $value && Weight::of($value) > 0) {
                $weights[$unit->id] = Weight::of($value);
                $shown[$unit->id] = $value;
            }
        }

        return ['weights' => $weights, 'shown' => $shown, 'missing' => $missing];
    }

    /**
     * Der Wert, nach dem verteilt wird — als Zahl, die auch dasteht.
     *
     * Zurueck kommt die Dezimalzeichenkette und nicht das Gewicht: auf dem
     * Schreiben soll „78,40 von 142,60 m²" stehen und nicht „78400 von
     * 142600". Ein Mieter, der seinen Anteil nachrechnen koennen muss, kann
     * das mit einer internen Skalierung nicht.
     *
     * Eine Zuordnung und kein `match`: sechs Sorten sind sechs Zeilen Daten,
     * und die Liste waechst mit jedem neuen Schluessel.
     *
     * @param list<TenancySpan>     $spans
     * @param list<HouseholdWindow> $own
     */
    private function valueOf(
        CostRecord $cost,
        UnitBrief $unit,
        array $spans,
        array $own,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): ?string {
        $sources = [
            'area' => static fn (): ?string => $unit->area,
            'mea' => static fn (): string => $unit->mea,
            'units' => static fn (): string => '1',
            'persons' => static fn (): ?string => self::personDays($spans, $own, $from, $to),
            'metered' => static fn (): ?string => self::metered($cost, $unit),
            'fixed' => fn (): ?string => $this->shares->of($cost->keyId, $unit->id),
        ];

        return isset($sources[$cost->keyKind]) ? $sources[$cost->keyKind]() : null;
    }

    /**
     * Personen mal Tage, aus beiden Quellen — siehe {@see PersonDays}.
     *
     * @param list<TenancySpan>     $spans
     * @param list<HouseholdWindow> $own
     */
    private static function personDays(
        array $spans,
        array $own,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): ?string {
        $days = PersonDays::of($spans, $own, $from, $to);

        // Keine Angabe ist etwas anderes als „null Personen": eine Null
        // verteilte den Anteil der Wohnung still auf die Nachbarn.
        return null === $days ? null : (string) $days;
    }

    /**
     * Der erfasste Verbrauch dieser Einheit.
     *
     * Hat der Dienstleister bereits verteilt, steht ein Betrag dabei — dann
     * ist er das Gewicht, und wir verteilen seine Zahlen weiter, statt neu zu
     * rechnen. Heizkosten rechnen wir nie selbst.
     */
    private static function metered(CostRecord $cost, UnitBrief $unit): ?string
    {
        $value = $cost->perUnit[$unit->id] ?? null;

        if (null === $value) {
            return null;
        }

        // Hat der Dienstleister bereits verteilt, ist sein Betrag der Wert —
        // und er steht auch so auf dem Schreiben.
        return $value->amount?->toDecimal() ?? $value->consumption;
    }

    private static function whatIsMissing(CostRecord $cost): string
    {
        return match ($cost->keyKind) {
            'area' => 'billing.missing.area',
            'persons' => 'billing.missing.persons',
            'metered' => 'billing.missing.consumption',
            'fixed' => 'billing.missing.share',
            default => 'billing.missing.figure',
        };
    }
}
