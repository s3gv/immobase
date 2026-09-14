<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Contract;

use DateTimeImmutable;

/**
 * Wem eine Einheit gehoert.
 *
 * Eigene Flaeche neben {@see UnitDirectory}, weil das eine andere Frage ist:
 * dort geht es darum, welche Einheit gemeint ist, hier darum, wer fuer sie
 * angeschrieben wird.
 *
 * **Mit Zeitachse.** Eigentum hat einen Zeitraum, und gefragt wird nach
 * einem: wer ein Jahr abrechnet, braucht nicht den heutigen Stand, sondern
 * den von damals. Ein Verkauf mitten im Jahr ergibt zwei Abschnitte, und die
 * Abrechnung macht daraus zwei Schreiben.
 */
interface UnitOwnership
{
    /**
     * Die Eigentuemerabschnitte mehrerer Einheiten in einem Zeitraum.
     *
     * Einheiten ohne eingetragene Eigentuemer fehlen im Ergebnis — das ist
     * kein Fehler, sondern der Normalfall bei einem frisch angelegten Objekt.
     * Dasselbe gilt fuer Abschnitte ohne Eigentuemer: eine Luecke in der
     * Eigentumsgeschichte ist keine Zeile mit niemandem darin.
     *
     * @param list<string> $unitIds
     *
     * @return array<string, list<OwnershipSpan>> Kennung der Einheit auf ihre Abschnitte, zeitlich sortiert
     */
    public function inPeriod(array $unitIds, DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * Wem die Einheit an diesem Tag gehoert — nicht heute, sondern damals.
     *
     * @return list<string> Kennungen der Eigentuemer, leer ohne eingetragene
     */
    public function ownersOn(string $unitId, DateTimeImmutable $day): array;

    /**
     * Was dieser Partei gehoert — oder gehoert hat.
     *
     * Die andere Richtung derselben Frage, und sie hat einen eigenen Aufrufer:
     * das Portal zeigt einem Eigentuemer seine Einheiten. Es fragt
     * **ausdruecklich nicht** nach allen und filtert danach — eine Methode, die
     * alles liefert, ist die Stelle, an der der Filter eines Tages wegfaellt
     * und jemand die Wohnungen der Nachbarn sieht.
     *
     * @return list<OwnedUnit> die aktuellen zuerst, danach die vergangenen
     */
    public function ownedBy(string $partyId): array;
}
