<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\UserInterface\Controller;

use App\Module\Tenancy\Application\ScheduleRent;
use App\Module\Tenancy\Domain\Rent;
use App\Module\Tenancy\Domain\StepNotAllowed;
use App\Module\Tenancy\Domain\TenancyPermissions;
use App\Shared\Money\Money;
use App\Shared\Money\MoneyInput;
use App\Shared\Money\UnreadableAmount;
use App\Shared\Text\Trimmed;
use App\Shared\Time\DateInput;
use DateTimeImmutable;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Die Mietstaffel: Stufen anlegen, aendern, entfernen.
 *
 * Eigene Adressen und nicht Teil des Schritts: eine Stufe kommt einzeln dazu
 * und verschwindet einzeln. Ein Formular, das die ganze Staffel auf einmal
 * schickt, muesste beim Speichern entscheiden, welche Zeile welche war — und
 * genau daran ist die erste Fassung der Eigentuemerliste gescheitert.
 */
#[IsGranted(TenancyPermissions::EDIT)]
final class RentController extends AbstractController
{
    public function __construct(
        private readonly RequireTenancy $tenancy,
        private readonly ScheduleRent $schedule,
    ) {
    }

    #[Route('/miete/{number}/stufen', name: 'app_tenancy_rent_add', requirements: ['number' => '\d+'], methods: ['POST'])]
    public function add(int $number, Request $request): Response
    {
        $tenancy = ($this->tenancy)($number);
        $this->guard($request, 'tenancy_rent_'.$number);

        try {
            $this->schedule->add($tenancy, self::startsOn($request, $tenancy->term()->startsOn()), self::rent($request));
            $this->addFlash('success', 'tenancy.rent.added');
        } catch (StepNotAllowed|UnreadableAmount|InvalidArgumentException $problem) {
            $this->addFlash('error', self::reason($problem));
        }

        return $this->back($number);
    }

    #[Route(
        '/miete/{number}/stufen/{step}',
        name: 'app_tenancy_rent_change',
        requirements: ['number' => '\d+'],
        methods: ['POST'],
    )]
    public function change(int $number, string $step, Request $request): Response
    {
        $tenancy = ($this->tenancy)($number);
        $found = $this->tenancy->step($tenancy, $step);
        $this->guard($request, 'tenancy_rent_'.$number);

        if ('' !== $request->request->getString('remove')) {
            $this->schedule->drop($found);
            $this->addFlash('success', 'tenancy.rent.removed');

            return $this->back($number);
        }

        try {
            $this->schedule->change($found, self::startsOn($request, $tenancy->term()->startsOn()), self::rent($request));
            $this->addFlash('success', 'tenancy.rent.changed');
        } catch (StepNotAllowed|UnreadableAmount|InvalidArgumentException $problem) {
            $this->addFlash('error', self::reason($problem));
        }

        return $this->back($number);
    }

    private function back(int $number): Response
    {
        return $this->redirectToRoute('app_tenancy_edit', [
            'number' => $number,
            'step' => TenancyFlow::RENT,
        ]);
    }

    private function guard(Request $request, string $token): void
    {
        if (!$this->isCsrfTokenValid($token, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }

    /**
     * Ohne Datum gilt der Mietbeginn — die erste Stufe faengt dort an, und
     * alles andere waere eine Zahl ohne Anfang.
     */
    private static function startsOn(Request $request, ?DateTimeImmutable $fallback): DateTimeImmutable
    {
        return DateInput::orNull($request, 'startsOn')
            ?? $fallback
            ?? new DateTimeImmutable('today');
    }

    private static function rent(Request $request): Rent
    {
        return Rent::of(
            self::amount($request, 'base'),
            self::amount($request, 'operatingCosts'),
            self::amount($request, 'heating'),
            self::amount($request, 'parking'),
        );
    }

    /** Ein leeres Feld ist keine Null, sondern keine Position — hier dasselbe. */
    private static function amount(Request $request, string $field): Money
    {
        $raw = Trimmed::orNull($request->request->getString($field));

        return null === $raw ? Money::zero() : MoneyInput::parse($raw);
    }

    private static function reason(StepNotAllowed|InvalidArgumentException $problem): string
    {
        return $problem instanceof StepNotAllowed
            ? $problem->getMessage()
            : 'tenancy.error.rent_invalid';
    }
}
