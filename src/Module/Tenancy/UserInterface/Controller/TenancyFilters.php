<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\UserInterface\Controller;

use App\Module\Party\Contract\PartyBrief;
use App\Module\Party\Contract\PartyDirectory;
use App\Module\Property\Contract\PropertyBrief;
use App\Module\Property\Contract\PropertyDirectory;
use App\Module\Property\Contract\UnitBrief;
use App\Module\Property\Contract\UnitDirectory;
use App\Module\Tenancy\Domain\TenancyFilter;
use App\Shared\Ui\Past;
use Symfony\Component\HttpFoundation\Request;

/**
 * Uebersetzt die Adresszeile in einen Filter.
 *
 * Der Suchtext trifft Einheiten und Kontakte, und beides weiss dieses Modul
 * nicht: welche Einheit „Rosenweg" heisst, weiss das Objektmodul, welcher
 * Kontakt „Muster" heisst, das Stammdatenmodul. Hier wird gefragt und in
 * Kennungen uebersetzt, bevor der Filter entsteht.
 *
 * Getrennt vom Controller, weil es drei Verzeichnisse braucht und der sonst
 * sieben Abhaengigkeiten haette.
 */
final readonly class TenancyFilters
{
    /** Genug, um eine Suche brauchbar zu machen, ohne die Abfrage zu sprengen. */
    private const int MAX_MATCHES = 200;

    public function __construct(
        private UnitDirectory $units,
        private PartyDirectory $parties,
        private PropertyDirectory $properties,
    ) {
    }

    public function from(Request $request): TenancyFilter
    {
        $search = trim($request->query->getString('q'));
        $property = trim($request->query->getString('objekt'));

        return TenancyFilter::of(
            $request->query->getString('status'),
            Past::from($request)->shown,
            '' === $property ? null : $this->unitsOf($property),
            $search,
            '' === $search ? [] : self::ids($this->units->search($search, self::MAX_MATCHES)),
            '' === $search ? [] : self::ids($this->parties->search($search, self::MAX_MATCHES)),
        );
    }

    /**
     * Die Objekte fuer die Auswahlliste.
     *
     * @return list<PropertyBrief>
     */
    public function properties(): array
    {
        return $this->properties->all();
    }

    /**
     * @return list<string>
     */
    private function unitsOf(string $propertyNumber): array
    {
        return ctype_digit($propertyNumber)
            ? self::ids($this->units->ofProperty((int) $propertyNumber))
            : [];
    }

    /**
     * @param list<UnitBrief>|list<PartyBrief> $found
     *
     * @return list<string>
     */
    private static function ids(array $found): array
    {
        return array_map(static fn (UnitBrief|PartyBrief $brief): string => $brief->id, $found);
    }
}
