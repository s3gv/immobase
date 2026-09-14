<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\UserInterface\Controller;

use App\Module\Party\Contract\PartyBrief;
use App\Module\Party\Contract\PartyDirectory;
use App\Module\Property\Contract\UnitBrief;
use App\Module\Property\Contract\UnitDirectory;
use App\Module\Tenancy\Domain\Tenancy;
use App\Module\Tenancy\Domain\Tenant;
use DateTimeImmutable;

/**
 * Mietverhaeltnisse mit Namen, fertig zum Zeichnen.
 *
 * Einheiten und Mieter werden fuer eine ganze Seite in einem Zug
 * nachgeschlagen — eine Abfrage je Zeile waere genau das Muster, das Listen
 * langsam macht. Nachgeschlagen wird ueber die Contract-Flaechen der anderen
 * Module: dieses hier kennt weder die Unit- noch die Party-Entity.
 */
final readonly class TenancyView
{
    public function __construct(
        private UnitDirectory $units,
        private PartyDirectory $parties,
    ) {
    }

    /**
     * Eine ganze Liste, jede Zeile mit Einheit, Mietern und geltender Miete.
     *
     * @param list<Tenancy> $tenancies
     *
     * @return list<array<string, mixed>>
     */
    public function rows(array $tenancies, DateTimeImmutable $today): array
    {
        $units = $this->units->byIds(array_values(array_unique(
            array_map(static fn (Tenancy $tenancy): string => $tenancy->unitId(), $tenancies),
        )));
        $parties = $this->parties->byIds(self::partyIds($tenancies));

        return array_map(static fn (Tenancy $tenancy): array => [
            'tenancy' => $tenancy,
            'unit' => $units[$tenancy->unitId()] ?? null,
            'tenants' => self::name($tenancy, $parties),
            'rent' => $tenancy->schedule()->rentOn($today),
        ], $tenancies);
    }

    /**
     * Ein einzelnes — mit denselben Angaben und der ganzen Staffel.
     *
     * @return array<string, mixed>
     */
    public function data(Tenancy $tenancy, DateTimeImmutable $today): array
    {
        $row = $this->rows([$tenancy], $today)[0] ?? [];

        return [
            ...$row,
            'schedule' => $tenancy->schedule(),
            'household' => $tenancy->household(),
            'today' => $today,
            'next' => $tenancy->schedule()->nextAfter($today),
        ];
    }

    /** Damit die Vorlage die Einheit auch dann zeigen kann, wenn sie fehlt. */
    public static function unitLine(?UnitBrief $unit, string $unitId): string
    {
        return $unit?->oneLine() ?? $unitId;
    }

    /**
     * Die Mieter mit Namen — unbekannte Kennungen stehen als sie selbst da.
     *
     * @param array<string, PartyBrief> $parties
     *
     * @return list<array{partyId: string, brief: PartyBrief|null}>
     */
    private static function name(Tenancy $tenancy, array $parties): array
    {
        return array_map(static fn (Tenant $tenant): array => [
            'partyId' => $tenant->partyId(),
            'brief' => $parties[$tenant->partyId()] ?? null,
        ], $tenancy->tenants());
    }

    /**
     * @param list<Tenancy> $tenancies
     *
     * @return list<string>
     */
    private static function partyIds(array $tenancies): array
    {
        $ids = [];

        foreach ($tenancies as $tenancy) {
            foreach ($tenancy->tenants() as $tenant) {
                $ids[$tenant->partyId()] = true;
            }
        }

        return array_keys($ids);
    }
}
