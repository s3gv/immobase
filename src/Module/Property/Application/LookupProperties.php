<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Application;

use App\Module\Property\Contract\PropertyBrief;
use App\Module\Property\Contract\PropertyDirectory;
use App\Module\Property\Domain\ManagementMode;
use App\Module\Property\Domain\Property;
use App\Module\Property\Domain\PropertyFilter;
use App\Module\Property\Domain\PropertyRepository;
use App\Shared\Ui\Page;

/**
 * Was fremde Module ueber Objekte erfahren.
 *
 * Die Umsetzung von PropertyDirectory. Sie uebersetzt die Entity in das
 * schmale Wertobjekt des Contracts — mehr sieht draussen niemand.
 */
final readonly class LookupProperties implements PropertyDirectory
{
    /** Genug fuer eine Auswahlliste. Waechst es darueber, wird es ein Picker. */
    private const int MOST = 500;

    public function __construct(private PropertyRepository $properties)
    {
    }

    public function all(): array
    {
        return array_map(
            self::brief(...),
            $this->properties->matching(PropertyFilter::none(), Page::of(1, self::MOST)),
        );
    }

    public function byIds(array $ids): array
    {
        $found = [];

        foreach ($ids as $id) {
            $property = $this->properties->byId($id);

            if (null !== $property) {
                $found[$id] = self::brief($property);
            }
        }

        return $found;
    }

    private static function brief(Property $property): PropertyBrief
    {
        return new PropertyBrief(
            id: $property->id(),
            number: $property->number(),
            name: $property->name(),
            keepsAReserve: $property->modes()->keepAReserve(),
            address: $property->address()->oneLine(),
            fiscalYearDay: $property->accounting()->fiscalYear()->day(),
            fiscalYearMonth: $property->accounting()->fiscalYear()->month(),
            managesWeg: $property->modes()->has(ManagementMode::Weg),
            payeeName: $property->accounting()->account()->holder(),
            // In Vierergruppen: die Dauermietrechnung wird abgetippt, und
            // so steht die IBAN auf jedem Kontoauszug — und im Objekt nebenan.
            payeeIban: $property->accounting()->account()->ibanInGroups(),
            creditorId: $property->accounting()->account()->creditorId(),
            postalLines: [
                $property->address()->street(),
                $property->address()->postalCode().' '.$property->address()->city(),
            ],
        );
    }
}
