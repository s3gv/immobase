<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\UserInterface\Controller;

use App\Module\Tenancy\Application\ScheduleHousehold;
use App\Module\Tenancy\Domain\StepNotAllowed;
use App\Module\Tenancy\Domain\TenancyPermissions;
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
 * Die Personenzahl ueber die Zeit: Eintraege anlegen, aendern, entfernen.
 *
 * Eigene Adressen wie bei der Mietstaffel und aus demselben Grund: ein
 * Eintrag kommt einzeln dazu und verschwindet einzeln.
 */
#[IsGranted(TenancyPermissions::EDIT)]
final class HouseholdController extends AbstractController
{
    public function __construct(
        private readonly RequireTenancy $tenancy,
        private readonly ScheduleHousehold $schedule,
    ) {
    }

    #[Route('/miete/{number}/haushalt', name: 'app_tenancy_household_add', requirements: ['number' => '\d+'], methods: ['POST'])]
    public function add(int $number, Request $request): Response
    {
        $tenancy = ($this->tenancy)($number);
        $this->guard($request, 'tenancy_household_'.$number);

        try {
            $this->schedule->add($tenancy, self::startsOn($request, $tenancy->term()->startsOn()), self::people($request));
            $this->addFlash('success', 'tenancy.household.added');
        } catch (StepNotAllowed|InvalidArgumentException $problem) {
            $this->addFlash('error', self::reason($problem));
        }

        return $this->back($number);
    }

    #[Route(
        '/miete/{number}/haushalt/{step}',
        name: 'app_tenancy_household_change',
        requirements: ['number' => '\d+'],
        methods: ['POST'],
    )]
    public function change(int $number, string $step, Request $request): Response
    {
        $tenancy = ($this->tenancy)($number);
        $found = $this->tenancy->householdStep($tenancy, $step);
        $this->guard($request, 'tenancy_household_'.$number);

        if ('' !== $request->request->getString('remove')) {
            $this->schedule->drop($found);
            $this->addFlash('success', 'tenancy.household.removed');

            return $this->back($number);
        }

        try {
            $this->schedule->change($found, self::startsOn($request, $tenancy->term()->startsOn()), self::people($request));
            $this->addFlash('success', 'tenancy.household.changed');
        } catch (StepNotAllowed|InvalidArgumentException $problem) {
            $this->addFlash('error', self::reason($problem));
        }

        return $this->back($number);
    }

    private function back(int $number): Response
    {
        return $this->redirectToRoute('app_tenancy_edit', [
            'number' => $number,
            'step' => TenancyFlow::TENANTS,
        ]);
    }

    private function guard(Request $request, string $token): void
    {
        if (!$this->isCsrfTokenValid($token, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }

    /** Ohne Datum gilt der Mietbeginn — dort faengt der erste Haushalt an. */
    private static function startsOn(Request $request, ?DateTimeImmutable $fallback): DateTimeImmutable
    {
        return DateInput::orNull($request, 'startsOn')
            ?? $fallback
            ?? new DateTimeImmutable('today');
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function people(Request $request): int
    {
        return WholeNumber::orNull($request->request->getString('people'))
            ?? throw new InvalidArgumentException('Ohne Personenzahl gibt es keinen Eintrag.');
    }

    private static function reason(StepNotAllowed|InvalidArgumentException $problem): string
    {
        return $problem instanceof StepNotAllowed
            ? $problem->getMessage()
            : 'tenancy.error.household_size';
    }
}
