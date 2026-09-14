<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Application;

use App\Module\Tenancy\Domain\Tenancy;
use App\Module\Tenancy\Domain\TenancyRepository;
use App\Module\Tenancy\Domain\UnitAlreadyLet;
use App\Module\Tenancy\Domain\UnitLetInThatPeriod;

/**
 * Die beiden Regeln, die eine Einheit freihalten.
 *
 * Hoechstens ein aktives Mietverhaeltnis, und keine zwei mit ueberlappender
 * Laufzeit. Beide stehen auch in der Datenbank — der partielle eindeutige
 * Index und die Ausschlussbedingung. Hier stehen sie, damit die Absage
 * verstaendlich ist und nicht erst beim Speichern kommt; dort stehen sie,
 * weil zwischen Pruefung und Speichern eine andere Anfrage passt.
 *
 * Als eigener Dienst, weil sie an vier Stellen gebraucht werden und
 * zusammengehoeren: wer eine aendert, muss die andere sehen.
 */
final readonly class KeepUnitsFree
{
    public function __construct(private TenancyRepository $tenancies)
    {
    }

    /**
     * Vor dem Aktivieren: beides pruefen.
     *
     * @throws UnitAlreadyLet
     * @throws UnitLetInThatPeriod
     */
    public function beforeLetting(Tenancy $tenancy): void
    {
        $this->refuseIfLet($tenancy->unitId(), $tenancy->id());
        $this->refuseIfOverlapping($tenancy);
    }

    /**
     * @throws UnitAlreadyLet
     */
    public function refuseIfLet(string $unitId, ?string $exceptTenancyId): void
    {
        $let = $this->tenancies->activeFor($unitId, $exceptTenancyId);

        if (null !== $let) {
            throw UnitAlreadyLet::of($let->number());
        }
    }

    /**
     * Ohne Mietbeginn gibt es keinen Zeitraum, den man vergleichen koennte —
     * dann bleibt es bei der Pruefung auf das aktive.
     *
     * @throws UnitLetInThatPeriod
     */
    private function refuseIfOverlapping(Tenancy $tenancy): void
    {
        $from = $tenancy->term()->startsOn();

        if (null === $from) {
            return;
        }

        $found = $this->tenancies->overlapping(
            $tenancy->unitId(),
            $from,
            $tenancy->term()->endsOn(),
            $tenancy->id(),
        );

        if (null !== $found) {
            throw UnitLetInThatPeriod::of($found->number());
        }
    }
}
