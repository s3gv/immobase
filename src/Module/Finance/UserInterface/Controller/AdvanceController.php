<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\UserInterface\Controller;

use App\Module\Finance\Application\SaveHouseMoney;
use App\Module\Finance\Contract\Interval;
use App\Module\Finance\Domain\FinancePermissions;
use App\Module\Finance\Domain\HouseMoney;
use App\Module\Finance\Domain\HouseMoneyRepository;
use App\Module\Finance\Domain\StepAlreadyStartsThatDay;
use App\Module\Finance\Domain\UnknownUnit;
use App\Module\Property\Contract\PropertyDirectory;
use App\Module\Property\Contract\UnitBrief;
use App\Module\Property\Contract\UnitDirectory;
use App\Module\Tenancy\Contract\TenancyDirectory;
use App\Shared\Money\MoneyInput;
use App\Shared\Money\UnreadableAmount;
use App\Shared\Time\DateInput;
use DateTimeImmutable;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Die Vorauszahlungen: eine Zeile je Einheit.
 *
 * Zwei Sorten stehen nebeneinander, und beide gehoeren auf dieselbe Seite:
 * das Hausgeld zahlt der Eigentuemer, die Nebenkosten der Mieter. Das erste
 * gehoert den Finanzen, das zweite steht im Mietvertrag — es wird gelesen
 * und verlinkt, nicht bearbeitet.
 *
 * Eine Einheitenliste und keine Vereinigung zweier Listen: so gibt es nichts
 * zu sortieren, was aus zwei Quellen kommt, und die Frage, die sie
 * beantwortet, ist die richtige — was zahlt diese Einheit gerade, und von wem.
 *
 * Die Liste beantwortet sie kurz, die Einheit selbst ausfuehrlich. Die
 * Staffel gehoert auf die zweite Seite: fuenfzig Einheiten mit je einem
 * offenen Formular sind keine Uebersicht mehr, sondern ein Stapel.
 */
#[IsGranted(FinancePermissions::VIEW)]
final class AdvanceController extends AbstractController
{
    /** Was heute gilt, die Staffel dahinter, und was davon ankam. */
    public const array SECTIONS = ['aktuell', 'staffel', 'zahlungen'];

    private const string UUID = '[0-9a-fA-F-]{36}';

    public function __construct(
        private readonly UnitDirectory $units,
        private readonly PropertyDirectory $properties,
        private readonly TenancyDirectory $tenancies,
        private readonly HouseMoneyRepository $steps,
        private readonly SaveHouseMoney $save,
        private readonly PaymentView $payments,
        private readonly FinancePage $page,
        private readonly FinanceSections $frame,
    ) {
    }

    #[Route('/finanzen/vorauszahlungen', name: 'app_finance_advance', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $property = trim($request->query->getString('objekt'));
        $units = '' === $property ? $this->units->all() : $this->units->ofProperty((int) $property);
        $ids = array_map(static fn (object $unit): string => $unit->id, $units);
        $today = new DateTimeImmutable('today');

        return $this->render('finance/advances.html.twig', [
            'units' => $units,
            'schedules' => $this->steps->forUnits($ids),
            'advances' => $this->tenancies->advancesFor($ids, $today),
            'properties' => $this->properties->all(),
            'chosenProperty' => $property,
            'today' => $today,
            'trail' => $this->page->trail('finance.advance.heading'),
        ]);
    }

    /**
     * Eine Einheit mit ihrer Staffel.
     *
     * Die Kennung ist eine UUID, und die Anforderung sagt das auch: sonst
     * schluckte diese Route auch „stufe" und naehme der darunter die Post
     * weg.
     */
    #[Route(
        '/finanzen/vorauszahlungen/{unitId}',
        name: 'app_finance_advance_show',
        requirements: ['unitId' => self::UUID],
        methods: ['GET'],
    )]
    public function show(string $unitId, Request $request): Response
    {
        $unit = $this->units->byIds([$unitId])[$unitId]
            ?? throw new NotFoundHttpException('Diese Einheit gibt es nicht.');
        $frame = $this->frame->frame(
            'app_finance_advance_show',
            ['unitId' => $unitId],
            'finance.advance',
            self::SECTIONS,
            $request->query->getString('abschnitt'),
        );

        return $this->render('finance/advance/'.$frame['current'].'.html.twig', [
            ...$frame,
            ...$this->paidBy($unit),
            ...('zahlungen' === $frame['current'] ? $this->payments->of($unit, $request) : []),
            'heading' => $unit->label,
            'subheading' => $unit->propertyNumber.' · '.$unit->propertyName.' · '.$unit->address,
            'trail' => $this->page->trail('finance.advance.heading'),
        ]);
    }

    #[IsGranted(FinancePermissions::EDIT)]
    #[Route(
        '/finanzen/vorauszahlungen/{unitId}',
        name: 'app_finance_advance_add',
        requirements: ['unitId' => self::UUID],
        methods: ['POST'],
    )]
    public function add(string $unitId, Request $request): Response
    {
        $this->guard($request);

        try {
            $this->save->add(
                $unitId,
                DateInput::orNull($request, 'startsOn') ?? new DateTimeImmutable('today'),
                MoneyInput::parse($request->request->getString('amount')),
                Interval::tryFrom($request->request->getString('interval')) ?? Interval::Monthly,
                $request->request->getString('note'),
            );
            $this->addFlash('success', 'finance.advance.added');
        } catch (UnknownUnit) {
            $this->addFlash('error', 'finance.error.unit_unknown');
        } catch (StepAlreadyStartsThatDay|UnreadableAmount|InvalidArgumentException $problem) {
            $this->addFlash('error', self::reason($problem));
        }

        return $this->back($unitId);
    }

    #[IsGranted(FinancePermissions::EDIT)]
    #[Route('/finanzen/vorauszahlungen/stufe/{id}', name: 'app_finance_advance_change', methods: ['POST'])]
    public function change(string $id, Request $request): Response
    {
        $step = $this->steps->byId($id) ?? throw new NotFoundHttpException('Diese Stufe gibt es nicht.');
        $this->guard($request);

        if ('' !== $request->request->getString('remove')) {
            $unitId = $step->unitId();
            $this->save->drop($step);
            $this->addFlash('success', 'finance.advance.removed');

            return $this->back($unitId);
        }

        $this->changed($step, $request);

        return $this->back($step->unitId());
    }

    /**
     * Was diese Einheit zahlt: die eigene Staffel und, was aus der Miete kommt.
     *
     * @return array<string, mixed>
     */
    private function paidBy(UnitBrief $unit): array
    {
        $today = new DateTimeImmutable('today');

        return [
            'unit' => $unit,
            'schedule' => $this->steps->forUnits([$unit->id])[$unit->id] ?? null,
            'advance' => $this->tenancies->advancesFor([$unit->id], $today)[$unit->id] ?? null,
            'today' => $today,
            'intervals' => Interval::cases(),
        ];
    }

    private function changed(HouseMoney $step, Request $request): void
    {
        try {
            $this->save->change(
                $step,
                DateInput::orNull($request, 'startsOn') ?? $step->startsOn(),
                MoneyInput::parse($request->request->getString('amount')),
                Interval::tryFrom($request->request->getString('interval')) ?? Interval::Monthly,
                $request->request->getString('note'),
            );
            $this->addFlash('success', 'finance.advance.changed');
        } catch (StepAlreadyStartsThatDay|UnreadableAmount|InvalidArgumentException $problem) {
            $this->addFlash('error', self::reason($problem));
        }
    }

    /** Zurueck auf die Einheit, an der gerade gearbeitet wurde. */
    private function back(string $unitId): Response
    {
        return $this->redirectToRoute('app_finance_advance_show', ['unitId' => $unitId]);
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('finance_advance', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }

    private static function reason(
        StepAlreadyStartsThatDay|UnreadableAmount|InvalidArgumentException $problem,
    ): string {
        return $problem instanceof StepAlreadyStartsThatDay
            ? 'finance.error.advance_twice'
            : 'finance.error.amount_invalid';
    }
}
