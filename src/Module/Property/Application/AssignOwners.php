<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Application;

use App\Module\Party\Contract\PartyDirectory;
use App\Module\Property\Domain\Holding;
use App\Module\Property\Domain\Mea;
use App\Module\Property\Domain\Unit;
use App\Module\Property\Domain\UnitOwner;
use App\Module\Property\Domain\UnitRepository;
use App\Module\Property\Domain\UnknownOwner;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Wem eine Einheit gehoert — und zu welchem Anteil.
 *
 * Das Formular schickt die Liste, wie sie danach aussehen soll. Abgeglichen
 * wird trotzdem Zeile fuer Zeile: alles wegzuwerfen und neu anzulegen waere
 * kuerzer und liefe in den Ausschluss ueber Einheit, Partei und Zeitraum —
 * beim Speichern laege die neue Zeile vor der geloeschten alten, und die
 * Datenbank sagt nein.
 *
 * **Zugeordnet wird ueber die Kennung der Zeile und nicht ueber die des
 * Kontakts.** Eine Zeile ist ein Eigentumszeitraum: dieselbe Partei darf
 * zweimal an derselben Einheit stehen, wenn sie verkauft und spaeter
 * zurueckkauft. Ueber den Kontakt zugeordnet waeren beide Zeitraeume derselbe
 * Eintrag, und der zweite ueberschriebe den ersten.
 *
 * Also drei Faelle: wer bleibt, bekommt seinen neuen Anteil und Zeitraum; wer
 * dazukommt, eine Zeile; wer fehlt, verschwindet.
 *
 * Vorher wird jede Kennung nachgeschlagen. Die letzte Grenze zieht die
 * Datenbank — property_unit_owner.party_id verweist auf party —, aber sie
 * zieht sie erst beim Speichern und mit einem Fehler, den niemand lesen will.
 * Hier faellt es am Feld auf, und zwar bevor etwas uebernommen ist.
 */
final readonly class AssignOwners
{
    public function __construct(
        private UnitRepository $units,
        private PartyDirectory $parties,
    ) {
    }

    /**
     * @param array<string, array{party: string, mea: string, von: string, bis: string}> $shares Kennung der Zeile auf Kontakt, Anteil und Zeitraum
     *
     * @throws InvalidArgumentException
     * @throws UnknownOwner
     */
    public function to(Unit $unit, Mea $unitShare, array $shares): void
    {
        // Vor der ersten Aenderung: eine halb uebernommene Liste waere
        // schlimmer als eine abgelehnte.
        $this->refuseUnknown(array_values(array_map(
            static fn (array $row): string => $row['party'],
            $shares,
        )));

        $unit->holdShare($unitShare);
        $before = self::byRow($unit);

        foreach ($shares as $key => $row) {
            self::write($unit, $row, $unitShare, \count($shares), $before[$key] ?? null);
            unset($before[$key]);
        }

        // Wer jetzt noch uebrig ist, stand nicht mehr im Formular.
        foreach ($before as $gone) {
            $unit->removeOwner($gone);
        }

        $this->units->save($unit);
    }

    /**
     * @param list<string> $ids
     *
     * @throws UnknownOwner
     */
    private function refuseUnknown(array $ids): void
    {
        $missing = array_values(array_diff($ids, array_keys($this->parties->byIds($ids))));

        if ([] !== $missing) {
            throw UnknownOwner::of($missing[0]);
        }
    }

    /**
     * Eine Zeile uebernehmen — die vorhandene aendern oder eine anlegen.
     *
     * @param array{party: string, mea: string, von: string, bis: string} $row
     *
     * @throws InvalidArgumentException
     */
    private static function write(
        Unit $unit,
        array $row,
        Mea $unitShare,
        int $owners,
        ?UnitOwner $known,
    ): void {
        $share = self::shareFor($unitShare, $row['mea'], $owners);
        $holding = self::holdingFrom($row);

        if (null === $known) {
            new UnitOwner($unit, $row['party'], $share, $holding);

            return;
        }

        $known->holdShare($share);
        $known->holdsFrom($holding);
    }

    /**
     * Der Zeitraum aus dem Formular — leer heisst offen.
     *
     * Ein leeres Von ist kein fehlender Wert, sondern eine Aussage: diese
     * Partei hielt die Einheit schon, als die Verwaltung sie uebernahm. Ein
     * leeres Bis heisst, dass sie ihr noch gehoert. Deshalb gibt es dafuer
     * keine Pflichtangabe und keine Fehlermeldung.
     *
     * @param array{mea: string, von: string, bis: string} $row
     *
     * @throws InvalidArgumentException
     */
    private static function holdingFrom(array $row): Holding
    {
        return Holding::of(self::dayOrNull($row['von']), self::dayOrNull($row['bis']));
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function dayOrNull(string $day): ?DateTimeImmutable
    {
        if ('' === trim($day)) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', trim($day));

        return false === $parsed
            ? throw new InvalidArgumentException('„'.$day.'" ist kein Datum.') : $parsed;
    }

    /**
     * Die vorhandenen Zeilen unter ihrer eigenen Kennung.
     *
     * @return array<string, UnitOwner>
     */
    private static function byRow(Unit $unit): array
    {
        $found = [];

        foreach ($unit->owners() as $owner) {
            $found[$owner->id()] = $owner;
        }

        return $found;
    }

    /**
     * Bei einem einzigen Eigentuemer gehoert ihm die ganze Einheit.
     *
     * Ihn den Anteil abtippen zu lassen, den die Einheit ohnehin traegt, ist
     * eine Gelegenheit fuer einen Zahlendreher und sonst nichts. Bei mehreren
     * steht die Aufteilung dagegen nirgends — die muss jemand eingeben.
     */
    private static function shareFor(Mea $unitShare, string $numerator, int $owners): Mea
    {
        $entered = trim($numerator);

        if ('' === $entered && 1 === $owners) {
            return $unitShare;
        }

        return '' === $entered
            ? Mea::none($unitShare->denominator)
            : Mea::of($entered, $unitShare->denominator);
    }
}
