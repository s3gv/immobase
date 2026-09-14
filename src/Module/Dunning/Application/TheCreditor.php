<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Application;

use App\Module\Dunning\Domain\Creditor;
use App\Module\Dunning\Domain\CreditorIdentity;
use App\Module\Property\Contract\OwnerShare;
use App\Module\Property\Contract\OwnershipSpan;
use App\Module\Property\Contract\UnitOwnership;
use DateTimeImmutable;

/**
 * Wer fordert — ermittelt aus Rolle, Einheit und Faelligkeit.
 *
 * Fordert die Gemeinschaft, ist es die des Objekts. Fordert der Eigentuemer,
 * sind es die Eigentuemer der Einheit **am Tag der Faelligkeit** — nicht die
 * von heute: wer die Wohnung inzwischen verkauft hat, war damals der
 * Glaeubiger, und die Forderung ist mit dem Verkauf nicht mitgegangen.
 *
 * Ermittelt wird einmal, beim Entstehen der Forderung; danach steht es an ihr
 * ({@see \App\Module\Dunning\Domain\Source}). Ein Nachschlagen bei jedem Blick
 * waere nicht nur eine Abfrage je Zeile, sondern auch eine Wahrheit, die sich
 * unter einem schon geschriebenen Brief noch aendern kann.
 */
final readonly class TheCreditor
{
    public function __construct(private UnitOwnership $ownership)
    {
    }

    /** Ohne Einheit gibt es keinen Eigentuemer — dann bleibt das Objekt. */
    public function of(Creditor $role, string $propertyId, ?string $unitId, DateTimeImmutable $on): CreditorIdentity
    {
        if (Creditor::Community === $role || null === $unitId) {
            return CreditorIdentity::theCommunityOf($propertyId);
        }

        return self::owning($propertyId, $this->ownership->inPeriod([$unitId], $on, $on)[$unitId] ?? [], $on);
    }

    /**
     * Dieselbe Frage fuer viele Zahlungen — in einer Abfrage.
     *
     * Die Tafel der Ueberfaelligen und das Abzeichen gehen ueber alles, was
     * offen ist; je Zeile nachzuschlagen waeren bei zweihundert Wohnungen
     * zweihundert Abfragen fuer eine Zahl im Menue.
     *
     * @param list<array{role: Creditor, propertyId: string, unitId: string|null, on: DateTimeImmutable}> $asked
     *
     * @return list<CreditorIdentity> in derselben Reihenfolge
     */
    public function each(array $asked): array
    {
        $spans = $this->spansFor($asked);

        return array_map(
            static fn (array $one): CreditorIdentity => Creditor::Community === $one['role'] || null === $one['unitId']
                ? CreditorIdentity::theCommunityOf($one['propertyId'])
                : self::owning($one['propertyId'], $spans[$one['unitId']] ?? [], $one['on']),
            $asked,
        );
    }

    /**
     * Die Abschnitte aller gefragten Einheiten ueber den ganzen Zeitraum.
     *
     * @param list<array{role: Creditor, propertyId: string, unitId: string|null, on: DateTimeImmutable}> $asked
     *
     * @return array<string, list<OwnershipSpan>>
     */
    private function spansFor(array $asked): array
    {
        $units = [];
        $days = [];

        foreach ($asked as $one) {
            if (Creditor::Owner === $one['role'] && null !== $one['unitId']) {
                $units[$one['unitId']] = true;
                $days[] = $one['on'];
            }
        }

        return [] === $days
            ? []
            : $this->ownership->inPeriod(array_keys($units), min($days), max($days));
    }

    /**
     * Der Abschnitt, der diesen Tag enthaelt.
     *
     * @param list<OwnershipSpan> $spans
     */
    private static function owning(string $propertyId, array $spans, DateTimeImmutable $on): CreditorIdentity
    {
        foreach ($spans as $span) {
            if ($span->from <= $on && $on <= $span->to) {
                return CreditorIdentity::theOwners(
                    $propertyId,
                    array_map(static fn (OwnerShare $share): string => $share->partyId, $span->owners),
                );
            }
        }

        // Keine Eigentuemer eingetragen: es bleibt beim Objekt. Ohne Anschrift
        // haelt {@see NoticeGaps} die Ausstellung ohnehin auf.
        return CreditorIdentity::theOwners($propertyId, []);
    }
}
