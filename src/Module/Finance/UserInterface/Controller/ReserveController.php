<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\UserInterface\Controller;

use App\Module\Finance\Application\BookReserve;
use App\Module\Finance\Domain\AlreadyReversed;
use App\Module\Finance\Domain\FinancePermissions;
use App\Module\Finance\Domain\ReserveFilter;
use App\Module\Finance\Domain\ReserveMovementKind;
use App\Module\Finance\Domain\ReserveNeedsAUnit;
use App\Module\Finance\Domain\ReserveOnlyForWeg;
use App\Module\Finance\Domain\SecondOpeningBalance;
use App\Module\Finance\Domain\UnitBelongsElsewhere;
use App\Module\Property\Contract\PropertyBrief;
use App\Module\Property\Contract\UnitBrief;
use App\Module\Property\Contract\UnitDirectory;
use App\Shared\Http\FormInput;
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
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Die Erhaltungsruecklage: ein Konto, das die Summe seiner Bewegungen ist.
 *
 * Nur bei WEG-Verwaltung — sie gehoert der Gemeinschaft. Objekte ohne WEG
 * stehen deshalb gar nicht erst in der Liste.
 */
#[IsGranted(FinancePermissions::VIEW)]
final class ReserveController extends AbstractController
{
    /** Die Bewegungen, und das Erfassen einer neuen. */
    public const array SECTIONS = ['bewegungen', 'buchen'];

    public function __construct(
        private readonly BookReserve $book,
        private readonly ReserveAccounts $accounts,
        private readonly UnitDirectory $units,
        private readonly FinancePage $page,
        private readonly FinanceSections $frame,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/finanzen/ruecklage', name: 'app_finance_reserve', methods: ['GET'])]
    public function index(): Response
    {
        $properties = $this->accounts->keeping();

        return $this->render('finance/reserves.html.twig', [
            'properties' => $properties,
            'balances' => $this->accounts->forAll($properties),
            'trail' => $this->page->trail('finance.reserve.heading'),
        ]);
    }

    #[Route(
        '/finanzen/ruecklage/{number}',
        name: 'app_finance_reserve_show',
        requirements: ['number' => '\d+'],
        methods: ['GET'],
    )]
    public function show(int $number, Request $request): Response
    {
        $property = $this->accounts->required($number);
        $frame = $this->frame->frame(
            'app_finance_reserve_show',
            ['number' => $number],
            'finance.reserve',
            self::SECTIONS,
            $request->query->getString('abschnitt'),
        );

        return $this->render('finance/reserve/'.$frame['current'].'.html.twig', [
            ...$frame,
            ...$this->accountOf($property, self::askedFor($request)),
            'heading' => $this->translator->trans('finance.reserve.heading'),
            'subheading' => $property->oneLine(),
            'wide' => true,
            'trail' => $this->page->trail('finance.reserve.heading'),
        ]);
    }

    #[IsGranted(FinancePermissions::EDIT)]
    #[Route(
        '/finanzen/ruecklage/{number}/buchen',
        name: 'app_finance_reserve_book',
        requirements: ['number' => '\d+'],
        methods: ['POST'],
    )]
    public function record(int $number, Request $request): Response
    {
        $property = $this->accounts->required($number);
        $this->guard($request);

        try {
            $this->book->book(
                $property->id,
                ReserveMovementKind::tryFrom($request->request->getString('kind')) ?? ReserveMovementKind::Contribution,
                DateInput::orNull($request, 'occurredOn') ?? new DateTimeImmutable('today'),
                MoneyInput::parse($request->request->getString('amount')),
                Trimmed::orNull($request->request->getString('unitId')),
                $request->request->getString('note'),
            );
            $this->addFlash('success', 'finance.reserve.booked');
        } catch (ReserveOnlyForWeg|ReserveNeedsAUnit|UnitBelongsElsewhere|SecondOpeningBalance|UnreadableAmount|InvalidArgumentException $problem) {
            $this->addFlash('error', self::reason($problem));
        }

        return $this->redirectToRoute('app_finance_reserve_show', ['number' => $number]);
    }

    /**
     * Stornieren statt loeschen.
     *
     * Eine gebuchte Bewegung kann Grundlage einer Abrechnung sein. Sie
     * verschwindet nicht, sie verliert ihre Wirkung.
     */
    #[IsGranted(FinancePermissions::DELETE)]
    #[Route(
        '/finanzen/ruecklage/{number}/buchen/{id}/storno',
        name: 'app_finance_reserve_reverse',
        requirements: ['number' => '\d+'],
        methods: ['POST'],
    )]
    public function reverse(int $number, string $id, Request $request): Response
    {
        $property = $this->accounts->required($number);
        $this->guard($request);
        $movement = $this->accounts->bookedOn($property, $id);

        try {
            $this->book->reverse($movement, new DateTimeImmutable('now'));
            $this->addFlash('success', 'finance.reserve.reversed');
        } catch (AlreadyReversed) {
            $this->addFlash('error', 'finance.error.already_reversed');
        }

        return $this->redirectToRoute('app_finance_reserve_show', ['number' => $number]);
    }

    /**
     * Das Konto und die gewaehlte Auswahl daraus.
     *
     * Der volle Stand bleibt der volle Stand: eine Auswahl grenzt die Liste
     * ein, nicht das Konto.
     *
     * @return array<string, mixed>
     */
    private function accountOf(PropertyBrief $property, ReserveFilter $filter): array
    {
        $balance = $this->accounts->of($property);
        $units = $this->units->ofProperty($property->number);

        return [
            'property' => $property,
            'balance' => $balance,
            'selection' => $balance->only($filter),
            'filter' => $filter,
            'years' => $balance->years(),
            'units' => $units,
            'unitNames' => self::names($units),
            'kinds' => ReserveMovementKind::cases(),
        ];
    }

    /**
     * Wonach die Adresszeile die Bewegungen einschraenken will.
     *
     * Als Zeichenkette gelesen und selbst umgewandelt: „Alle Jahre" schickt
     * ein leeres Feld mit, und `getInt` beantwortet das mit 400 statt mit
     * „keine Einschraenkung". Was aus der Adresszeile kommt, ist Eingabe —
     * ein Formular darf sich damit nicht selbst abschiessen.
     */
    private static function askedFor(Request $request): ReserveFilter
    {
        return ReserveFilter::of(
            $request->query->getString('art'),
            FormInput::queryIntOrNull($request, 'jahr'),
            $request->query->getString('einheit'),
        );
    }

    /**
     * Kennung der Einheit auf ihre Bezeichnung — fuer die Tabelle.
     *
     * @param list<UnitBrief> $units
     *
     * @return array<string, string>
     */
    private static function names(array $units): array
    {
        $names = [];

        foreach ($units as $unit) {
            $names[$unit->id] = $unit->label;
        }

        return $names;
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('finance_reserve', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }

    private static function reason(
        ReserveOnlyForWeg|ReserveNeedsAUnit|UnitBelongsElsewhere|SecondOpeningBalance|UnreadableAmount|InvalidArgumentException $problem,
    ): string {
        return match (true) {
            $problem instanceof ReserveOnlyForWeg => 'finance.error.reserve_only_weg',
            $problem instanceof ReserveNeedsAUnit => 'finance.error.reserve_needs_unit',
            $problem instanceof UnitBelongsElsewhere => 'finance.error.unit_elsewhere',
            $problem instanceof SecondOpeningBalance => 'finance.error.reserve_second_opening',
            $problem instanceof UnreadableAmount => 'finance.error.amount_invalid',
            default => 'finance.error.reserve_amount_invalid',
        };
    }
}
