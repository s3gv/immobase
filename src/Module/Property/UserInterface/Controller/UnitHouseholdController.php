<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\UserInterface\Controller;

use App\Module\Property\Application\ScheduleUnitHousehold;
use App\Module\Property\Domain\PropertyPermissions;
use App\Module\Property\Domain\StepIsTaken;
use App\Module\Property\Domain\Unit;
use App\Module\Property\Domain\UnitHousehold;
use App\Shared\Number\WholeNumber;
use App\Shared\Time\DateInput;
use DateTimeImmutable;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Die Personenzahl einer Einheit: Eintraege anlegen, aendern, entfernen.
 *
 * Eigene Adressen wie bei der Personenstaffel des Mietverhaeltnisses und aus
 * demselben Grund: ein Eintrag kommt einzeln dazu und verschwindet einzeln.
 */
#[IsGranted(PropertyPermissions::EDIT)]
final class UnitHouseholdController extends AbstractController
{
    public function __construct(
        private readonly RequireProperty $properties,
        private readonly ScheduleUnitHousehold $schedule,
    ) {
    }

    #[Route(
        '/objekte/{number}/einheiten/{unit}/personen',
        name: 'app_unit_household_add',
        requirements: ['number' => '\d+', 'unit' => '\d+'],
        methods: ['POST'],
    )]
    public function add(int $number, int $unit, Request $request): Response
    {
        $found = $this->properties->unit(($this->properties)($number), $unit);
        $this->guard($request, $found);

        try {
            $this->schedule->add($found, self::startsOn($request), self::people($request));
            $this->addFlash('success', 'property.unit.household.added');
        } catch (StepIsTaken|InvalidArgumentException $problem) {
            $this->addFlash('error', self::reason($problem));
        }

        return $this->back($number, $unit);
    }

    #[Route(
        '/objekte/{number}/einheiten/{unit}/personen/{step}',
        name: 'app_unit_household_change',
        requirements: ['number' => '\d+', 'unit' => '\d+'],
        methods: ['POST'],
    )]
    public function change(int $number, int $unit, string $step, Request $request): Response
    {
        $found = $this->properties->unit(($this->properties)($number), $unit);
        $entry = self::stepOf($found, $step)
            ?? throw $this->createNotFoundException('Diesen Eintrag gibt es nicht.');
        $this->guard($request, $found);

        if ('' !== $request->request->getString('remove')) {
            $this->schedule->drop($entry);
            $this->addFlash('success', 'property.unit.household.removed');

            return $this->back($number, $unit);
        }

        try {
            $this->schedule->change($entry, self::startsOn($request), self::people($request));
            $this->addFlash('success', 'property.unit.household.changed');
        } catch (StepIsTaken|InvalidArgumentException $problem) {
            $this->addFlash('error', self::reason($problem));
        }

        return $this->back($number, $unit);
    }

    private static function stepOf(Unit $unit, string $id): ?UnitHousehold
    {
        foreach ($unit->household()->steps() as $step) {
            if ($step->id() === $id) {
                return $step;
            }
        }

        return null;
    }

    private function back(int $number, int $unit): Response
    {
        return $this->redirectToRoute('app_unit_show', [
            'number' => $number,
            'unit' => $unit,
            'abschnitt' => UnitFlow::DETAIL,
        ]);
    }

    private function guard(Request $request, Unit $unit): void
    {
        if (!$this->isCsrfTokenValid('unit_household_'.$unit->id(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }

    /** Ohne Datum gilt heute — der Eintrag beginnt dann ab jetzt. */
    private static function startsOn(Request $request): DateTimeImmutable
    {
        return DateInput::orNull($request, 'startsOn') ?? new DateTimeImmutable('today');
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function people(Request $request): int
    {
        return WholeNumber::orNull($request->request->getString('people'))
            ?? throw new InvalidArgumentException('Ohne Personenzahl gibt es keinen Eintrag.');
    }

    private static function reason(StepIsTaken|InvalidArgumentException $problem): string
    {
        return $problem instanceof StepIsTaken
            ? $problem->getMessage()
            : 'property.unit.household.invalid';
    }
}
