<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Application;

use App\Module\Property\Contract\Event\PropertyClosed;
use App\Module\Property\Contract\Event\UnitClosed;
use App\Module\Tenancy\Domain\Tenancy;
use App\Module\Tenancy\Domain\TenancyRepository;
use DateTimeImmutable;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Wird ein Objekt oder eine Einheit abgewickelt, endet die Miete dazu.
 *
 * Das Objektmodul ruft hier nichts auf — es sagt nur, was passiert ist. Was
 * das fuer die Mietverhaeltnisse heisst, entscheidet dieses Modul selbst.
 * Andersherum waere es ein Zyklus: die Miete kennt die Objekte.
 *
 * Beendet werden nur die laufenden. Ein Entwurf ist keine Vermietung; er
 * bleibt loeschbar, und wer ihn stehen laesst, verliert nichts.
 */
final readonly class EndOnClosure
{
    public function __construct(private TenancyRepository $tenancies)
    {
    }

    #[AsEventListener]
    public function whenAPropertyCloses(PropertyClosed $event): void
    {
        $this->endAll($event->unitIds, $event->effectiveOn);
    }

    #[AsEventListener]
    public function whenAUnitCloses(UnitClosed $event): void
    {
        $this->endAll([$event->unitId], $event->effectiveOn);
    }

    /**
     * @param list<string> $unitIds
     */
    private function endAll(array $unitIds, DateTimeImmutable $on): void
    {
        foreach ($this->tenancies->forUnits($unitIds) as $tenancies) {
            foreach ($tenancies as $tenancy) {
                $this->end($tenancy, $on);
            }
        }
    }

    /**
     * Der Stichtag setzt ein Ende, wo keines steht — er verschiebt keines.
     *
     * Ein Mietverhaeltnis, das schon frueher endet, behaelt seinen Tag. Und
     * eines, das erst nach dem Stichtag beginnt, endet nicht rueckwaerts:
     * dann bleibt es, wie es ist, und faellt beim naechsten Blick auf.
     */
    private function end(Tenancy $tenancy, DateTimeImmutable $on): void
    {
        $start = $tenancy->term()->startsOn();
        $end = $tenancy->term()->endsOn();

        if (!$tenancy->status()->isActive() || (null !== $start && $start > $on)) {
            return;
        }

        $tenancy->endOn(null === $end || $end > $on ? $on : $end);
        $this->tenancies->save($tenancy);
    }
}
