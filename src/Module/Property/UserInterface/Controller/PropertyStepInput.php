<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\UserInterface\Controller;

use App\Module\Property\Application\SaveProperty;
use App\Module\Property\Domain\Address;
use App\Module\Property\Domain\Building;
use App\Module\Property\Domain\FiscalYear;
use App\Module\Property\Domain\Heating;
use App\Module\Property\Domain\HeatingSystem;
use App\Module\Property\Domain\HeatingType;
use App\Module\Property\Domain\HotWater;
use App\Module\Property\Domain\LandRegister;
use App\Module\Property\Domain\ManagementMode;
use App\Module\Property\Domain\ManagementModes;
use App\Module\Property\Domain\MeaAlreadyDistributed;
use App\Module\Property\Domain\MeaDenominator;
use App\Module\Property\Domain\Property;
use App\Shared\Http\FormInput;
use App\Shared\Text\Trimmed;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Was ein Schritt entgegennimmt — und was daran nicht stimmt.
 *
 * Je Schritt eine Methode. Gemeinsam ist ihnen nur die Form der Antwort: eine
 * Liste von Fehlern je Feld, leer heisst gespeichert.
 */
final readonly class PropertyStepInput
{
    public function __construct(
        private SaveProperty $save,
        private BankAccountInput $bank,
    ) {
    }

    /**
     * @return array<string, string> Feldname auf Uebersetzungsschluessel
     */
    public function apply(string $step, Request $request, Property $property): array
    {
        // Eine Zuordnung und kein `match`: neun Schritte sind neun Zeilen
        // Daten, und der Ablauf waechst weiter.
        $steps = [
            PropertyFlow::ADDRESS => $this->address(...),
            PropertyFlow::BUILDING => $this->building(...),
            PropertyFlow::HEATING => $this->heating(...),
            PropertyFlow::REGISTRY => $this->registry(...),
            PropertyFlow::ACCOUNTING => $this->accounting(...),
            PropertyFlow::BANK => $this->bank->apply(...),
            PropertyFlow::MEA => $this->mea(...),
            PropertyFlow::UNITS => static fn (): array => [],
            PropertyFlow::NAME => $this->name(...),
        ];

        return ($steps[$step] ?? $this->name(...))($request, $property);
    }

    /**
     * Der erste Schritt ohne Objekt: er legt eines an.
     *
     * @return array{errors: array<string, string>, property: Property|null}
     */
    public function create(Request $request): array
    {
        $name = Trimmed::orNull($request->request->getString('name'));
        $modes = self::modesOrNull($request);

        $errors = self::nameErrors($name, $modes);

        if ([] !== $errors || null === $name || null === $modes) {
            return ['errors' => $errors, 'property' => null];
        }

        $property = $this->save->create($name, $modes);
        $this->save->noteThat($property, $request->request->getString('note'));

        return ['errors' => [], 'property' => $property];
    }

    /** @return array<string, string> */
    private function name(Request $request, Property $property): array
    {
        $name = Trimmed::orNull($request->request->getString('name'));
        $modes = self::modesOrNull($request);
        $errors = self::nameErrors($name, $modes);

        if ([] !== $errors || null === $name || null === $modes) {
            return $errors;
        }

        $this->save->describe($property, $name, $modes);
        $this->save->noteThat($property, $request->request->getString('note'));

        return [];
    }

    /** @return array<string, string> */
    private function address(Request $request, Property $property): array
    {
        try {
            $this->save->moveTo($property, Address::of(
                $request->request->getString('street'),
                $request->request->getString('postalCode'),
                $request->request->getString('city'),
            ));
        } catch (InvalidArgumentException) {
            return ['address' => 'property.error.address_required'];
        }

        return [];
    }

    /** @return array<string, string> */
    private function building(Request $request, Property $property): array
    {
        try {
            $this->save->describeBuilding($property, Building::of([
                'yearBuilt' => FormInput::intOrNull($request, 'yearBuilt'),
                'livingArea' => $request->request->getString('livingArea'),
                'commercialArea' => $request->request->getString('commercialArea'),
                'plotArea' => $request->request->getString('plotArea'),
                'floors' => FormInput::intOrNull($request, 'floors'),
                'hasLift' => FormInput::yesNoOrNull($request, 'hasLift'),
                'parkingSpaces' => FormInput::intOrNull($request, 'parkingSpaces'),
            ]));
        } catch (InvalidArgumentException) {
            // Die Meldung der Domaene ist deutsch und fuer Entwickler gedacht.
            // Was hier herauskommt, liest jemand anderes — und auf Englisch.
            return ['building' => 'property.error.building_invalid'];
        }

        return [];
    }

    /** @return array<string, string> */
    private function heating(Request $request, Property $property): array
    {
        $this->save->useHeating($property, Heating::of(
            HeatingType::tryFrom($request->request->getString('heatingType')),
            HeatingSystem::tryFrom($request->request->getString('heatingSystem')),
            HotWater::tryFrom($request->request->getString('hotWater')),
        ));

        return [];
    }

    /**
     * Das Wirtschaftsjahr — Tag und Monat, an dem es beginnt.
     *
     * @return array<string, string>
     */
    private function accounting(Request $request, Property $property): array
    {
        try {
            // Ein leeres Feld ist keine Zahl, aber auch kein Serverfehler:
            // die Null laeuft in dieselbe Pruefung wie der 30. Februar.
            $this->save->accountsFrom($property, FiscalYear::beginningOn(
                FormInput::intOrNull($request, 'fiscalYearDay') ?? 0,
                FormInput::intOrNull($request, 'fiscalYearMonth') ?? 0,
            ));
        } catch (InvalidArgumentException) {
            return ['fiscalYear' => 'property.error.fiscal_year_invalid'];
        }

        return [];
    }

    /** @return array<string, string> */
    private function registry(Request $request, Property $property): array
    {
        $this->save->registerAt($property, LandRegister::of(
            $request->request->getString('court'),
            $request->request->getString('sheet'),
            $request->request->getString('district'),
            $request->request->getString('parcel'),
        ));

        return [];
    }

    /** @return array<string, string> */
    private function mea(Request $request, Property $property): array
    {
        $denominator = MeaDenominator::tryFrom($request->request->getInt('meaDenominator'));

        if (null === $denominator) {
            return [];
        }

        try {
            $this->save->scaleMeaTo($property, $denominator);
        } catch (MeaAlreadyDistributed) {
            return ['meaDenominator' => 'property.mea.denominator_locked'];
        }

        return [];
    }

    /** @return array<string, string> */
    private static function nameErrors(?string $name, ?ManagementModes $modes): array
    {
        $errors = [];

        if (null === $name) {
            $errors['name'] = 'property.error.name_required';
        }

        if (null === $modes) {
            $errors['modes'] = 'property.error.modes_required';
        }

        return $errors;
    }

    private static function modesOrNull(Request $request): ?ManagementModes
    {
        $chosen = array_filter(
            array_map(ManagementMode::tryFrom(...), array_filter($request->request->all('modes'), \is_string(...))),
        );

        return [] === $chosen ? null : ManagementModes::of(array_values($chosen));
    }
}
