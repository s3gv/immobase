<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\Application;

use App\Module\Property\Contract\OwnedUnit;
use App\Module\Property\Contract\UnitOwnership;
use App\Shared\Change\RecordKind;
use DateTimeImmutable;

/**
 * Gehoert dieser Datensatz dem Angemeldeten?
 *
 * Das Schloss vor jedem Vorschlag, und es fragt nicht die Adresszeile,
 * sondern die Sitzung: die Partei kommt von {@see PortalIdentity}, die
 * Einheiten von den Objekten, und die Objekte folgen aus den Einheiten — ein
 * Objekt gehoert niemandem, es gehoert eine Einheit darin.
 *
 * **Heute und nicht irgendwann.** Wer im Juli verkauft hat, schlaegt im
 * August nichts mehr an der Einheit vor; sehen darf er sie weiter, denn sie
 * gehoerte ihm, als die Abrechnung entstand.
 *
 * **Ein Mieter schlaegt an einer Einheit nichts vor.** Er bekommt hier keine
 * Liste, in der seine Wohnung stuende — nicht, weil es verboten waere,
 * sondern weil sie ihm nicht gehoert.
 */
final readonly class WhatIsMine
{
    public function __construct(
        private PortalIdentity $who,
        private UnitOwnership $ownership,
    ) {
    }

    public function owns(RecordKind $kind, string $recordId): bool
    {
        return match ($kind) {
            RecordKind::Party => $this->who->owns($recordId),
            RecordKind::Unit => \in_array($recordId, $this->unitIds(), true),
            RecordKind::Property => \in_array($recordId, $this->propertyIds(), true),
        };
    }

    /**
     * @return list<string>
     */
    public function unitIds(): array
    {
        return array_values(array_map(
            static fn (OwnedUnit $owned): string => $owned->unit->id,
            $this->today(),
        ));
    }

    /**
     * @return list<string>
     */
    public function propertyIds(): array
    {
        return array_values(array_unique(array_map(
            static fn (OwnedUnit $owned): string => $owned->unit->propertyId,
            $this->today(),
        )));
    }

    /**
     * @return list<OwnedUnit>
     */
    private function today(): array
    {
        $on = new DateTimeImmutable('today');

        return array_values(array_filter(
            $this->ownership->ownedBy($this->who->partyId()),
            static fn (OwnedUnit $owned): bool => $owned->isCurrent($on),
        ));
    }
}
