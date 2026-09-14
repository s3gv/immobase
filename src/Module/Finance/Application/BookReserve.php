<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Application;

use App\Module\Finance\Domain\AlreadyReversed;
use App\Module\Finance\Domain\LevyComesFromAResolution;
use App\Module\Finance\Domain\ReserveMovement;
use App\Module\Finance\Domain\ReserveMovementKind;
use App\Module\Finance\Domain\ReserveMovementRepository;
use App\Module\Finance\Domain\ReserveNeedsAUnit;
use App\Module\Finance\Domain\ReserveOnlyForWeg;
use App\Module\Finance\Domain\SecondOpeningBalance;
use App\Module\Finance\Domain\UnitBelongsElsewhere;
use App\Module\Property\Contract\PropertyDirectory;
use App\Module\Property\Contract\UnitDirectory;
use App\Shared\Money\Money;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * Bewegungen auf der Erhaltungsruecklage buchen.
 *
 * Vier Regeln, und jede hat einen Grund: die Ruecklage gehoert der
 * Gemeinschaft, also gibt es sie nur bei WEG. Wer eingezahlt hat, muss
 * nachvollziehbar sein, also brauchen Zufuehrung und Sonderumlage eine
 * Einheit. Zinsen duerfen negativ sein, alles andere nicht. Und einen
 * Anfangsbestand gibt es hoechstens einmal.
 */
final readonly class BookReserve
{
    public function __construct(
        private ReserveMovementRepository $movements,
        private PropertyDirectory $properties,
        private UnitDirectory $units,
    ) {
    }

    /**
     * @throws LevyComesFromAResolution
     * @throws ReserveOnlyForWeg
     * @throws ReserveNeedsAUnit
     * @throws UnitBelongsElsewhere
     * @throws SecondOpeningBalance
     * @throws InvalidArgumentException
     */
    public function book(
        string $propertyId,
        ReserveMovementKind $kind,
        DateTimeImmutable $occurredOn,
        Money $amount,
        ?string $unitId,
        string $note = '',
    ): ReserveMovement {
        self::refuseALevy($kind);
        $this->refuseWithoutWeg($propertyId);
        $this->refuseWrongUnit($propertyId, $kind, $unitId);
        self::refuseWrongAmount($kind, $amount);
        $this->refuseSecondOpening($propertyId, $kind);

        $movement = new ReserveMovement(
            $propertyId,
            $kind,
            $occurredOn,
            $amount,
            $kind->needsAUnit() ? $unitId : null,
        );
        $movement->noteThat($note);
        $this->saved($movement);

        return $movement;
    }

    /**
     * Stornieren statt loeschen.
     *
     * Eine gebuchte Bewegung kann Grundlage einer Abrechnung sein — und
     * abgerechnet wird das vergangene Jahr, manchmal das vorletzte. Sie
     * verschwindet deshalb nicht, sie verliert ihre Wirkung: die Zeile
     * bleibt lesbar, der Bestand stimmt wieder.
     *
     * @throws AlreadyReversed
     */
    public function reverse(ReserveMovement $movement, DateTimeImmutable $on): void
    {
        $movement->reverse($on);
        $this->movements->save($movement);
    }

    /**
     * Speichern — und die Absage der Datenbank in die des Fachs uebersetzen.
     *
     * Zwischen der Pruefung oben und dem Speichern liegt eine Luecke. Zwei
     * gleichzeitig gebuchte Anfangsbestaende lesen darin dasselbe Konto, und
     * der zweite laeuft in den Teilindex. Die Anwendung gibt die lesbare
     * Absage, die Datenbank bleibt die letzte Linie — aber ihre Meldung ist
     * keine Antwort, die jemand versteht.
     *
     * @throws SecondOpeningBalance
     */
    private function saved(ReserveMovement $movement): void
    {
        try {
            $this->movements->save($movement);
        } catch (UniqueConstraintViolationException) {
            throw new SecondOpeningBalance();
        }
    }

    /**
     * @throws ReserveOnlyForWeg
     */
    /**
     * @throws LevyComesFromAResolution
     */
    private static function refuseALevy(ReserveMovementKind $kind): void
    {
        if (!$kind->isBookable()) {
            throw new LevyComesFromAResolution();
        }
    }

    private function refuseWithoutWeg(string $propertyId): void
    {
        $property = $this->properties->byIds([$propertyId])[$propertyId] ?? null;

        if (null === $property || !$property->keepsAReserve) {
            throw new ReserveOnlyForWeg();
        }
    }

    /**
     * Wer eingezahlt hat, muss nachvollziehbar sein — und zur Gemeinschaft
     * gehoeren.
     *
     * Eine Einheit aus einem anderen Haus stuende sonst als Einzahler dieser
     * WEG in der Liste, und eine erfundene Kennung liefe in den
     * Fremdschluessel: ein 500er statt einer Antwort. Die Oberflaeche bietet
     * nur die eigenen an, aber ein abgeschicktes Formular ist Eingabe und
     * keine Zusicherung.
     *
     * @throws ReserveNeedsAUnit
     * @throws UnitBelongsElsewhere
     */
    private function refuseWrongUnit(string $propertyId, ReserveMovementKind $kind, ?string $unitId): void
    {
        if (!$kind->needsAUnit()) {
            return;
        }

        if (null === $unitId || '' === trim($unitId)) {
            throw new ReserveNeedsAUnit();
        }

        $unit = $this->units->byIds([$unitId])[$unitId] ?? null;

        if (null === $unit || $unit->propertyId !== $propertyId) {
            throw new UnitBelongsElsewhere();
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function refuseWrongAmount(ReserveMovementKind $kind, Money $amount): void
    {
        if ($amount->isNegative() && !$kind->mayBeNegative()) {
            throw new InvalidArgumentException('Nur Zinsen können negativ sein — ein Verwahrentgelt.');
        }
    }

    /**
     * @throws SecondOpeningBalance
     */
    private function refuseSecondOpening(string $propertyId, ReserveMovementKind $kind): void
    {
        if (ReserveMovementKind::Opening !== $kind) {
            return;
        }

        $balance = $this->movements->forProperties([$propertyId])[$propertyId] ?? null;

        if (true === $balance?->hasAnOpeningBalance()) {
            throw new SecondOpeningBalance();
        }
    }
}
