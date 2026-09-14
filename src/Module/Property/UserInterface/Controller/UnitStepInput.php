<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\UserInterface\Controller;

use App\Module\Property\Application\AssignOwners;
use App\Module\Property\Application\SaveUnit;
use App\Module\Property\Domain\Mea;
use App\Module\Property\Domain\Unit;
use App\Module\Property\Domain\UnitUsage;
use App\Module\Property\Domain\UnknownOwner;
use App\Shared\Number\WholeNumber;
use App\Shared\Text\Trimmed;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Was ein Schritt der Einheit entgegennimmt — und was daran nicht stimmt.
 */
final readonly class UnitStepInput
{
    public function __construct(
        private SaveUnit $units,
        private AssignOwners $owners,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function apply(string $step, Request $request, Unit $unit): array
    {
        return match ($step) {
            UnitFlow::OWNERS => $this->assign($request, $unit),
            UnitFlow::DETAIL => $this->detail($request, $unit),
            default => $this->basics($request, $unit),
        };
    }

    /**
     * Die Zaehler aus dem Formular, je Kontakt.
     *
     * Oeffentlich, weil der Controller sie nach einem Fehler ein zweites Mal
     * braucht: dann steht im Formular wieder das Eingegebene.
     *
     * Der Schluessel ist die Kennung der Eigentumszeile und **nicht** die des
     * Kontakts: dieselbe Partei darf zweimal an derselben Einheit stehen, wenn
     * sie verkauft und spaeter zurueckkauft. Wessen Zeile es ist, steht im
     * Feld `party`. Ein unbekannter Schluessel ist eine neue Zeile.
     *
     * @return array<string, array{party: string, mea: string, von: string, bis: string}>
     */
    public static function sharesFrom(Request $request): array
    {
        $shares = [];

        foreach ($request->request->all('owners') as $key => $row) {
            $party = \is_array($row) ? self::textIn($row, 'party') : '';

            if (\is_string($key) && '' !== $party && \is_array($row)) {
                $shares[$key] = [
                    'party' => $party,
                    'mea' => self::textIn($row, 'mea'),
                    'von' => self::textIn($row, 'von'),
                    'bis' => self::textIn($row, 'bis'),
                ];
            }
        }

        return $shares;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private static function textIn(array $row, string $field): string
    {
        $value = $row[$field] ?? '';

        return \is_string($value) ? trim($value) : '';
    }

    /**
     * @return array<string, string>
     */
    private function basics(Request $request, Unit $unit): array
    {
        $label = Trimmed::orNull($request->request->getString('label'));

        if (null === $label) {
            return ['label' => 'property.error.name_required'];
        }

        $this->units->describe(
            $unit,
            $label,
            UnitUsage::tryFrom($request->request->getString('usage')) ?? UnitUsage::Residential,
        );

        return [];
    }

    /**
     * @return array<string, string>
     */
    private function detail(Request $request, Unit $unit): array
    {
        try {
            $this->units->detail(
                $unit,
                $request->request->getString('area'),
                $request->request->getString('rooms'),
                self::intOrNull($request, 'parkingSpaces'),
                $request->request->getString('note'),
            );
        } catch (InvalidArgumentException) {
            return ['detail' => 'property.unit.error.detail_invalid'];
        }

        return [];
    }

    /**
     * Der Anteil der Einheit und die Aufteilung unter ihren Eigentuemern.
     *
     * Beides in einem Schritt, weil das eine ohne das andere nicht zu
     * beurteilen ist: „25/1000" sagt erst etwas, wenn danebensteht, dass die
     * Einheit 50/1000 haelt.
     *
     * @return array<string, string>
     */
    private function assign(Request $request, Unit $unit): array
    {
        $denominator = $unit->property()->shares()->denominator()->value;

        try {
            $this->owners->to(
                $unit,
                self::meaOrNone($request->request->getString('mea'), $denominator),
                self::sharesFrom($request),
            );
        } catch (InvalidArgumentException) {
            return ['mea' => 'property.mea.invalid'];
        } catch (UnknownOwner) {
            // Kann nur ueber ein nachgebautes Formular hereinkommen — oder
            // wenn der Kontakt zwischen Aufrufen und Absenden geloescht wurde.
            return ['owners' => 'property.owner.unknown'];
        }

        return [];
    }

    private static function meaOrNone(string $numerator, int $denominator): Mea
    {
        $entered = trim($numerator);

        return '' === $entered ? Mea::none($denominator) : Mea::of($entered, $denominator);
    }

    private static function intOrNull(Request $request, string $field): ?int
    {
        return WholeNumber::orNull($request->request->getString($field));
    }
}
