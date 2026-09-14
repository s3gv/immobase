<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Application;

use App\Module\Property\Contract\UnitDirectory;
use App\Module\Tenancy\Contract\RentPeriod;
use App\Module\Tenancy\Contract\TenancyBrief;
use App\Module\Tenancy\Contract\TenancyDirectory;
use App\Module\Tenancy\Contract\UnitAdvance;
use App\Module\Tenancy\Domain\RentStep;
use App\Module\Tenancy\Domain\Tenancy;
use App\Module\Tenancy\Domain\TenancyFilter;
use App\Module\Tenancy\Domain\TenancyRepository;
use App\Module\Tenancy\Domain\Tenant;
use App\Shared\Money\Money;
use App\Shared\Ui\Page;
use App\Shared\Ui\Sort;
use DateTimeImmutable;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Was fremde Module ueber Mietverhaeltnisse erfahren.
 *
 * Die Umsetzung von TenancyDirectory. Sie uebersetzt die Entity in das
 * schmale Wertobjekt des Contracts — mehr sieht draussen niemand.
 */
final readonly class LookupTenancies implements TenancyDirectory
{
    public function __construct(
        private TenancyRepository $tenancies,
        private UnitDirectory $units,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function advancesFor(array $unitIds, DateTimeImmutable $on): array
    {
        $found = [];

        foreach ($this->tenancies->forUnits($unitIds) as $unitId => $tenancies) {
            $running = self::runningOn($tenancies, $on);

            if (null !== $running) {
                $found[$unitId] = $this->advance($unitId, $running, $on);
            }
        }

        return $found;
    }

    public function brief(string $tenancyId): ?TenancyBrief
    {
        $tenancy = $this->tenancies->byId($tenancyId);

        return null === $tenancy || $tenancy->status()->isDraft() ? null : $this->briefOf($tenancy);
    }

    public function lettable(): array
    {
        $briefs = [];

        // Mit den beendeten: die Dauermietrechnung eines abgelaufenen
        // Vertrags bleibt ein Beleg, und wer sie nachtraeglich braucht,
        // faende den Vertrag sonst nicht mehr. `none()` blendet Vergangenes
        // aus — das ist der Schalter der Mietliste, nicht unsere Frage.
        $filter = TenancyFilter::of(null, withPast: true);
        $all = $this->tenancies->countMatching($filter);

        foreach ($this->tenancies->matching($filter, Page::of(1, max(1, $all)), Sort::by('nummer')) as $tenancy) {
            if (!$tenancy->status()->isDraft()) {
                $briefs[] = $this->briefOf($tenancy);
            }
        }

        return $briefs;
    }

    /**
     * Was diese Partei gemietet hat — das Laufende zuerst.
     *
     * Gefragt wird beim Speicher nach der Partei und nicht nach allem: das
     * Portal soll gar nicht erst in der Lage sein, fremde Vertraege zu laden.
     */
    public function rentedBy(string $partyId): array
    {
        $briefs = [];

        foreach ($this->tenancies->rentedBy($partyId) as $tenancy) {
            $briefs[] = $this->briefOf($tenancy);
        }

        usort($briefs, static fn (TenancyBrief $one, TenancyBrief $other): int => [
            null !== $one->to, -$one->number,
        ] <=> [
            null !== $other->to, -$other->number,
        ]);

        return $briefs;
    }

    /**
     * Die Entity in die Kurzform uebersetzen — samt Einheit und Objekt.
     *
     * Die Einheit wird je Mietverhaeltnis geholt und nicht fuer alle auf
     * einmal: die Liste der Mietverhaeltnisse ist kurz, und ein Vorrat, den
     * zwei Aufrufer teilen muessten, waere hier mehr Umstand als Ersparnis.
     */
    private function briefOf(Tenancy $tenancy): TenancyBrief
    {
        $unit = $this->units->byIds([$tenancy->unitId()])[$tenancy->unitId()] ?? null;

        return new TenancyBrief(
            tenancyId: $tenancy->id(),
            number: $tenancy->number(),
            unitId: $tenancy->unitId(),
            unitNumber: $unit->number ?? 0,
            unitLabel: $unit?->oneLine() ?? $tenancy->unitId(),
            propertyId: $unit->propertyId ?? '',
            propertyNumber: $unit->propertyNumber ?? 0,
            propertyName: $unit->propertyName ?? '',
            address: $unit->address ?? '',
            from: $tenancy->term()->startsOn(),
            to: $tenancy->term()->endsOn(),
            tenantPartyIds: array_map(static fn (Tenant $tenant): string => $tenant->partyId(), $tenancy->tenants()),
            vatCharged: $tenancy->taxation()->isCharged(),
            vatRateBps: $tenancy->taxation()->rateBps(),
            steps: array_map(self::period(...), $tenancy->schedule()->steps()),
            paymentMethod: $tenancy->payment()->method()->value,
            paymentDue: $tenancy->payment()->due()->value,
            buyerReference: $tenancy->payment()->eInvoice()->buyerReference(),
            buyerEAddress: $tenancy->payment()->eInvoice()->buyerEAddress(),
            sepaMandate: $tenancy->payment()->eInvoice()->sepaMandate(),
            debtorIban: $tenancy->payment()->eInvoice()->debtorIban(),
        );
    }

    private static function period(RentStep $step): RentPeriod
    {
        $rent = $step->rent();

        return new RentPeriod(
            from: $step->startsOn(),
            base: $rent->base,
            operatingCosts: $rent->operatingCosts,
            heating: $rent->heating,
            parking: $rent->parking,
        );
    }

    /**
     * Welches Mietverhaeltnis an diesem Tag lief.
     *
     * Nicht „welches ist aktiv": abgerechnet wird ein Jahr, das vorbei ist,
     * und dann ist das gesuchte Mietverhaeltnis laengst beendet. Entwuerfe
     * zaehlen nicht — sie sind keine Vermietung.
     *
     * @param list<Tenancy> $tenancies
     */
    private static function runningOn(array $tenancies, DateTimeImmutable $on): ?Tenancy
    {
        foreach ($tenancies as $tenancy) {
            $start = $tenancy->term()->startsOn();
            $end = $tenancy->term()->endsOn();

            if ($tenancy->status()->isDraft() || null === $start || $start > $on) {
                continue;
            }

            if (null === $end || $end >= $on) {
                return $tenancy;
            }
        }

        return null;
    }

    private function advance(string $unitId, Tenancy $tenancy, DateTimeImmutable $on): UnitAdvance
    {
        // Ohne erfasste Miete gibt es auch keine Vorauszahlung — null
        // Euro ist die richtige Antwort und keine fehlende.
        $rent = $tenancy->schedule()->rentOn($on);

        return new UnitAdvance(
            unitId: $unitId,
            tenancyNumber: $tenancy->number(),
            operatingCosts: null === $rent ? Money::zero() : $rent->operatingCosts,
            heating: null === $rent ? Money::zero() : $rent->heating,
            url: $this->urls->generate('app_tenancy_show', ['number' => $tenancy->number()]),
            vatRateBps: $tenancy->taxation()->rateBps(),
        );
    }
}
