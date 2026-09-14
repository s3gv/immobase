<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Application;

use App\Module\Property\Contract\OwnedUnit;
use App\Module\Property\Contract\OwnerShare;
use App\Module\Property\Contract\OwnershipSpan;
use App\Module\Property\Contract\UnitDirectory;
use App\Module\Property\Contract\UnitOwnership;
use App\Module\Property\Domain\Unit;
use App\Module\Property\Domain\UnitOwner;
use App\Module\Property\Domain\UnitRepository;
use App\Shared\Time\Segments;
use DateTimeImmutable;

/**
 * Wer die Einheiten haelt — fuer fremde Module, mit Zeitachse.
 *
 * Eine Abfrage fuer viele Einheiten, nicht eine je Einheit: eine Abrechnung
 * fragt nach einem ganzen Objekt, und zwanzig einzelne Abfragen waeren
 * zwanzig Wege in dieselbe Tabelle.
 *
 * Die Arbeit steckt im Schneiden. Ein Eigentumseintrag sagt nur, von wann bis
 * wann er gilt; wer wissen will, wem die Einheit **im Juli** gehoerte, muss
 * den Zeitraum an jedem Wechsel zerlegen. {@see Segments} macht das, hier
 * wird jedem Abschnitt zugeordnet, wer ihn haelt.
 */
final readonly class LookupOwners implements UnitOwnership
{
    public function __construct(
        private UnitRepository $units,
        private UnitDirectory $directory,
    ) {
    }

    /**
     * Was dieser Partei gehoert — die laufenden zuerst.
     *
     * Haelt jemand dieselbe Einheit in zwei Zeitraeumen (gekauft, verkauft,
     * zurueckgekauft), steht sie zweimal da. Das ist keine Doppelung, sondern
     * die Wahrheit: es waren zwei Eigentuemerschaften.
     */
    public function ownedBy(string $partyId): array
    {
        $units = $this->units->ownedBy($partyId);
        $briefs = $this->directory->byIds(array_map(static fn (Unit $unit): string => $unit->id(), $units));
        $owned = [];

        foreach ($units as $unit) {
            foreach ($unit->owners() as $owner) {
                $brief = $briefs[$unit->id()] ?? null;

                if ($owner->partyId() === $partyId && null !== $brief) {
                    $owned[] = new OwnedUnit(
                        $brief,
                        $owner->mea()->numerator(),
                        $owner->holding()->from(),
                        $owner->holding()->to(),
                    );
                }
            }
        }

        return self::currentFirst($owned);
    }

    public function ownersOn(string $unitId, DateTimeImmutable $day): array
    {
        $span = $this->inPeriod([$unitId], $day, $day)[$unitId][0] ?? null;

        return null === $span ? [] : array_values(array_map(
            static fn (OwnerShare $share): string => $share->partyId,
            $span->owners,
        ));
    }

    public function inPeriod(array $unitIds, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $spans = [];

        foreach ($this->units->byIds($unitIds) as $unit) {
            $found = self::spansOf($unit, $from, $to);

            if ([] !== $found) {
                $spans[$unit->id()] = $found;
            }
        }

        return $spans;
    }

    /**
     * @param list<OwnedUnit> $owned
     *
     * @return list<OwnedUnit>
     */
    private static function currentFirst(array $owned): array
    {
        $today = new DateTimeImmutable('today');

        usort($owned, static fn (OwnedUnit $one, OwnedUnit $other): int => [
            $other->isCurrent($today), $one->unit->propertyNumber, $one->unit->number,
        ] <=> [
            $one->isCurrent($today), $other->unit->propertyNumber, $other->unit->number,
        ]);

        return $owned;
    }

    /**
     * @return list<OwnershipSpan>
     */
    private static function spansOf(Unit $unit, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $owners = array_values(array_filter(
            $unit->owners(),
            static fn (UnitOwner $owner): bool => $owner->holding()->touches($from, $to),
        ));

        $spans = [];

        foreach (Segments::cut(self::intervalsOf($owners), $from, $to) as $segment) {
            $holders = self::holdersOn($owners, $segment['from']);

            if ([] !== $holders) {
                $spans[] = new OwnershipSpan($segment['from'], $segment['to'], $holders);
            }
        }

        return self::merged($spans);
    }

    /**
     * @param list<UnitOwner> $owners
     *
     * @return list<array{from: DateTimeImmutable|null, to: DateTimeImmutable|null}>
     */
    private static function intervalsOf(array $owners): array
    {
        return array_map(
            static fn (UnitOwner $owner): array => [
                'from' => $owner->holding()->from(),
                'to' => $owner->holding()->to(),
            ],
            $owners,
        );
    }

    /**
     * @param list<UnitOwner> $owners
     *
     * @return list<OwnerShare>
     */
    private static function holdersOn(array $owners, DateTimeImmutable $day): array
    {
        $holders = [];

        foreach ($owners as $owner) {
            if ($owner->holding()->covers($day)) {
                $holders[] = new OwnerShare($owner->partyId(), $owner->mea()->toString());
            }
        }

        return $holders;
    }

    /**
     * Benachbarte Abschnitte mit derselben Eigentuemerschaft wieder zusammen.
     *
     * Geschnitten wird an jedem Rand, auch an dem einer anderen Einheit
     * desselben Objekts oder an einem, der nichts aendert. Zwei Abschnitte
     * mit denselben Leuten sind aber ein Abschnitt — und zwei Schreiben an
     * denselben Empfaenger fuer zwei Haelften eines Jahres waeren ein Fehler.
     *
     * @param list<OwnershipSpan> $spans
     *
     * @return list<OwnershipSpan>
     */
    private static function merged(array $spans): array
    {
        $merged = [];

        foreach ($spans as $span) {
            $at = array_key_last($merged);
            $last = null === $at ? null : $merged[$at];

            if (null !== $at && null !== $last && self::follows($last, $span)) {
                $merged[$at] = new OwnershipSpan($last->from, $span->to, $last->owners);

                continue;
            }

            $merged[] = $span;
        }

        return $merged;
    }

    private static function follows(OwnershipSpan $one, OwnershipSpan $next): bool
    {
        return $one->fingerprint() === $next->fingerprint()
            && $one->to->modify('+1 day')->format('Y-m-d') === $next->from->format('Y-m-d');
    }
}
