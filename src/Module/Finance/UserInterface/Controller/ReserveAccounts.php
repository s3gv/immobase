<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\UserInterface\Controller;

use App\Module\Finance\Domain\ReserveBalance;
use App\Module\Finance\Domain\ReserveMovement;
use App\Module\Finance\Domain\ReserveMovementRepository;
use App\Module\Property\Contract\PropertyBrief;
use App\Module\Property\Contract\PropertyDirectory;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Welche Objekte eine Erhaltungsruecklage fuehren — und wie sie steht.
 *
 * Als eigener Dienst, damit der Controller entscheidet, was passiert, und
 * nicht auch noch nachschlaegt, worauf es passiert. Beides in einer Klasse
 * hiesse, dass jede neue Ansicht sie weiter wachsen laesst.
 */
final readonly class ReserveAccounts
{
    public function __construct(
        private ReserveMovementRepository $movements,
        private PropertyDirectory $properties,
    ) {
    }

    /**
     * Nur Objekte mit WEG-Verwaltung.
     *
     * Die Ruecklage gehoert der Gemeinschaft: bei reiner Mietverwaltung gibt
     * es keine, bei Sondereigentumsverwaltung fuehrt sie jemand anders.
     *
     * @return list<PropertyBrief>
     */
    public function keeping(): array
    {
        return array_values(array_filter(
            $this->properties->all(),
            static fn (PropertyBrief $property): bool => $property->keepsAReserve,
        ));
    }

    public function required(int $number): PropertyBrief
    {
        foreach ($this->keeping() as $property) {
            if ($property->number === $number) {
                return $property;
            }
        }

        throw new NotFoundHttpException('Dieses Objekt führt keine Erhaltungsrücklage.');
    }

    /**
     * Die Buchung — und zwar eine von diesem Konto.
     *
     * Die Kennung steht in der Adresszeile und ist damit Eingabe: ohne
     * diesen Abgleich liesse sich mit einem gueltigen Token fuer das eine
     * Objekt eine Bewegung des anderen stornieren.
     */
    public function bookedOn(PropertyBrief $property, string $id): ReserveMovement
    {
        $movement = $this->movements->byId($id);

        if (null === $movement || $movement->propertyId() !== $property->id) {
            throw new NotFoundHttpException('Diese Buchung gehört nicht zu diesem Objekt.');
        }

        return $movement;
    }

    public function of(PropertyBrief $property): ReserveBalance
    {
        return $this->movements->forProperties([$property->id])[$property->id] ?? ReserveBalance::of([]);
    }

    /**
     * Je Objekt ein Stand — auch wenn es noch keine Bewegung gibt.
     *
     * Ein leeres Konto ist kein fehlendes: es steht auf null, und die Vorlage
     * soll dafuer keine Ausnahme kennen.
     *
     * @param list<PropertyBrief> $properties
     *
     * @return array<string, ReserveBalance>
     */
    public function forAll(array $properties): array
    {
        $found = $this->movements->forProperties(
            array_map(static fn (PropertyBrief $property): string => $property->id, $properties),
        );
        $balances = [];

        foreach ($properties as $property) {
            $balances[$property->id] = $found[$property->id] ?? ReserveBalance::of([]);
        }

        return $balances;
    }
}
