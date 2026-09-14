<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\UserInterface\Controller;

use App\Module\Party\Contract\PartyBrief;
use App\Module\Party\Contract\PartyDirectory;
use App\Module\Property\Domain\Holding;
use App\Module\Property\Domain\Mea;
use App\Module\Property\Domain\OwnerShares;
use App\Module\Property\Domain\Unit;
use App\Module\Property\Domain\UnitOwner;
use DateTimeImmutable;

/**
 * Die Eigentuemerzeilen, wie das Formular sie braucht.
 *
 * Eine Zeile ist ein **Eigentumszeitraum** und nicht eine Partei: wer
 * verkauft und spaeter zurueckkauft, steht zweimal da. Sie traegt deshalb
 * ihre eigene Kennung, und wessen Zeile es ist, steht in einem Feld daneben.
 *
 * Steht neben {@see UnitPage} und nicht darin: dort geht es um den Rahmen
 * einer Seite — Abschnitte, Ueberschriften, Brotkrumen —, hier um eine
 * einzige Liste und ihre Bilanz.
 */
final readonly class OwnerRows
{
    public function __construct(private PartyDirectory $parties)
    {
    }

    /**
     * Die Eigentuemer mit Namen — in einem Zug nachgeschlagen.
     *
     * `$also` sind Kontakte, die noch nicht zugeordnet sind und trotzdem
     * dastehen sollen — ein gerade angelegter Stammdatensatz. Er waere sonst
     * weg, und man muesste ihn ein zweites Mal suchen.
     *
     * `$typed` ist, was im Formular stand, unter der Kennung **der Zeile**.
     * Nach einem Fehler steht das wieder da und nicht der gespeicherte Stand
     * — einschliesslich der Zeilen, die es noch gar nicht gibt.
     *
     * @param list<string>                                                               $also
     * @param array<string, array{party: string, mea: string, von: string, bis: string}> $typed
     *
     * @return array<string, mixed>
     */
    public function of(Unit $unit, array $also = [], array $typed = []): array
    {
        $briefs = $this->parties->byIds([
            ...array_map(static fn (UnitOwner $owner): string => $owner->partyId(), $unit->owners()),
            ...array_values(array_map(static fn (array $row): string => $row['party'], $typed)),
            ...$also,
        ]);
        $rows = [];

        foreach ($unit->owners() as $owner) {
            $rows[] = self::savedRow($owner, $typed[$owner->id()] ?? null, $briefs);
        }

        // Zeilen, die es noch nicht gibt: eben getippte, die an einem Fehler
        // haengen geblieben sind, und der gerade angelegte Kontakt aus `?neu=`.
        foreach (self::pendingRows($unit, $also, $typed) as $key => $row) {
            $rows[] = self::freshRow($key, $row, $briefs, $unit);
        }

        return ['owners' => $rows, ...self::balanceOf($unit)];
    }

    /**
     * Der Stand der Verteilung — zum Stichtag und ueber die Zeit.
     *
     * Zum Stichtag und nicht ueber alle Zeilen: nach einem Verkauf stehen
     * zwei volle Anteile da, und an keinem Tag sind es zwei. Wo es Zeitraeume
     * gibt, steht der Stichtag auch dabei — sonst liest sich „noch keine
     * Anteile verteilt" wie „nie erfasst", obwohl die Wohnung nur heute
     * gerade zwischen zwei Eigentuemern steht.
     *
     * @return array<string, mixed>
     */
    private static function balanceOf(Unit $unit): array
    {
        $today = new DateTimeImmutable('today');

        return [
            'balance' => OwnerShares::balanceOn($unit, $today),
            'balanceDay' => self::isTimed($unit) ? $today : null,
            'openPeriods' => OwnerShares::periodsThatDoNotAddUp($unit),
        ];
    }

    /** Traegt ueberhaupt eine Zeile einen Zeitraum? */
    private static function isTimed(Unit $unit): bool
    {
        foreach ($unit->owners() as $owner) {
            if (!$owner->holding()->isOpenEnded() || null !== $owner->holding()->from()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ein Schluessel, den es noch nicht gibt.
     *
     * Gezaehlt wird gegen die vorhandenen Zeilen: nach einem Fehler steht
     * eine noch nicht gespeicherte Zeile schon als `neu-1` da, und ein
     * zweites `neu-1` ueberschriebe sie beim naechsten Absenden.
     *
     * @param array<string, array{party: string, mea: string, von: string, bis: string}> $rows
     */
    private static function freshKey(array $rows): string
    {
        $at = 1;

        while (isset($rows['neu-'.$at])) {
            ++$at;
        }

        return 'neu-'.$at;
    }

    /**
     * Eine gespeicherte Zeile — mit dem Eingetippten, falls es eines gibt.
     *
     * @param array{party: string, mea: string, von: string, bis: string}|null $typed
     * @param array<string, PartyBrief>                                        $briefs
     *
     * @return array<string, mixed>
     */
    private static function savedRow(UnitOwner $owner, ?array $typed, array $briefs): array
    {
        return [
            'key' => $owner->id(),
            'brief' => $briefs[$owner->partyId()] ?? null,
            'partyId' => $owner->partyId(),
            'mea' => $owner->mea(),
            'typed' => $typed['mea'] ?? null,
            'holding' => $owner->holding(),
            'heldFrom' => self::day($typed['von'] ?? null, $owner->holding()->from()),
            'heldTo' => self::day($typed['bis'] ?? null, $owner->holding()->to()),
        ];
    }

    /**
     * Eine Zeile, die es noch nicht gibt.
     *
     * @param array{party: string, mea: string, von: string, bis: string} $row
     * @param array<string, PartyBrief>                                   $briefs
     *
     * @return array<string, mixed>
     */
    private static function freshRow(string $key, array $row, array $briefs, Unit $unit): array
    {
        return [
            'key' => $key,
            'brief' => $briefs[$row['party']] ?? null,
            'partyId' => $row['party'],
            'mea' => Mea::none($unit->property()->shares()->denominator()->value),
            'typed' => '' === $row['mea'] ? null : $row['mea'],
            'holding' => Holding::always(),
            'heldFrom' => self::day($row['von'], null),
            'heldTo' => self::day($row['bis'], null),
        ];
    }

    /**
     * Was ausser den gespeicherten Zeilen dastehen soll.
     *
     * @param list<string>                                                               $also
     * @param array<string, array{party: string, mea: string, von: string, bis: string}> $typed
     *
     * @return array<string, array{party: string, mea: string, von: string, bis: string}>
     */
    private static function pendingRows(Unit $unit, array $also, array $typed): array
    {
        $saved = [];

        foreach ($unit->owners() as $owner) {
            $saved[$owner->id()] = true;
        }

        $rows = array_diff_key($typed, $saved);

        foreach ($also as $partyId) {
            if (!\in_array($partyId, array_column($rows, 'party'), true)) {
                $rows[self::freshKey($rows)] = ['party' => $partyId, 'mea' => '', 'von' => '', 'bis' => ''];
            }
        }

        return $rows;
    }

    /**
     * Ein Datum fuer das Formular — das Eingetippte schlaegt das Gespeicherte.
     *
     * Nach einem Fehler soll dastehen, was jemand geschrieben hat, und nicht
     * der Stand aus der Datenbank: sonst verschwindet die Aenderung, die den
     * Fehler ausgeloest hat, und niemand sieht, was zu berichtigen ist.
     */
    private static function day(?string $typed, ?DateTimeImmutable $stored): string
    {
        return $typed ?? $stored?->format('Y-m-d') ?? '';
    }
}
