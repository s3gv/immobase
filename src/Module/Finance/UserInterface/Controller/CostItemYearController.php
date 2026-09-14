<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\UserInterface\Controller;

use App\Module\Finance\Application\RecordAmounts;
use App\Module\Finance\Application\RecordQuantities;
use App\Module\Finance\Domain\CostItem;
use App\Module\Finance\Domain\CostItemYear;
use App\Module\Finance\Domain\EntryMode;
use App\Module\Finance\Domain\FinancePermissions;
use App\Module\Finance\Domain\NothingToMeasure;
use App\Module\Finance\Domain\QuantitiesAreRecorded;
use App\Module\Finance\Domain\QuantityCannotBeCleared;
use App\Module\Finance\Domain\RecordedInTheMeantime;
use App\Module\Finance\Domain\TaxExceedsAmount;
use App\Module\Finance\Domain\UnitBelongsElsewhere;
use App\Module\Finance\Domain\UsedByAStatement;
use App\Module\Finance\Domain\YearAlreadyRecorded;
use App\Shared\Http\FormInput;
use App\Shared\Money\Money;
use App\Shared\Money\MoneyInput;
use App\Shared\Money\UnreadableAmount;
use App\Shared\Text\Trimmed;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;

/**
 * Die Jahreswerte: anlegen, aendern, entfernen.
 *
 * Eigene Adressen und nicht Teil des Schritts — dieselbe Ueberlegung wie bei
 * der Mietstaffel: ein Jahr kommt einzeln dazu und verschwindet einzeln, und
 * ein Formular, das alle Jahre auf einmal schickt, muesste beim Speichern
 * raten, welche Zeile welche war.
 */
#[IsGranted(FinancePermissions::EDIT)]
final class CostItemYearController extends AbstractController
{
    public function __construct(
        private readonly RequireCostItem $item,
        private readonly RecordAmounts $record,
        private readonly RecordQuantities $quantities,
    ) {
    }

    #[Route(
        '/finanzen/kosten/{number}/jahre',
        name: 'app_finance_year_add',
        requirements: ['number' => '\d+'],
        methods: ['POST'],
    )]
    public function add(int $number, Request $request): Response
    {
        $item = ($this->item)($number);
        $this->guard($request, $number);

        try {
            $this->record->add(
                $item,
                FormInput::intOrNull($request, 'fiscalYear') ?? 0,
                MoneyInput::parse($request->request->getString('amount')),
                self::modeFrom($request),
                MoneyInput::orNull($request->request->getString('inputTax')),
            );
            $this->addFlash('success', 'finance.year.added');
        } catch (YearAlreadyRecorded|UnreadableAmount|InvalidArgumentException $problem) {
            $this->addFlash('error', self::reason($problem));
        }

        return $this->back($number);
    }

    #[Route(
        '/finanzen/kosten/{number}/jahre/{id}',
        name: 'app_finance_year_change',
        requirements: ['number' => '\d+'],
        methods: ['POST'],
    )]
    public function change(int $number, string $id, Request $request): Response
    {
        $item = ($this->item)($number);
        $year = self::yearOf($item, $id);
        $this->guard($request, $number);

        if ('' !== $request->request->getString('remove')) {
            return $this->dropped($year, $number);
        }

        $this->changed($year, $request);

        return $this->back($number);
    }

    /**
     * Die Mengen je Einheit fuer ein Wirtschaftsjahr.
     *
     * Sie stehen unter ihrem Jahr im Schritt „Betraege" — dort, wo der
     * Schluessel gewaehlt wird, der sie ueberhaupt verlangt. Eine eigene
     * Seite dafuer hiess, dass man erst dorthin finden muss.
     */
    #[Route(
        '/finanzen/kosten/{number}/jahre/{id}/mengen',
        name: 'app_finance_year_metering',
        requirements: ['number' => '\d+'],
        methods: ['POST'],
    )]
    public function metering(int $number, string $id, Request $request): Response
    {
        $item = ($this->item)($number);
        $year = self::yearOf($item, $id);
        $this->guard($request, $number);

        try {
            $this->quantities->meter($year, self::valuesFrom($request));
            $this->addFlash('success', 'finance.year.metered');
        } catch (NothingToMeasure|UnitBelongsElsewhere|QuantityCannotBeCleared|RecordedInTheMeantime|UnreadableAmount|InvalidArgumentException $refused) {
            $this->addFlash('error', self::meteringRefusal($refused));
        }

        return $this->back($number);
    }

    /** Eine Absage je Grund — „ungueltig" allein schickt jemanden suchen. */
    private static function meteringRefusal(Throwable $refused): string
    {
        return match (true) {
            $refused instanceof NothingToMeasure => 'finance.error.nothing_to_measure',
            $refused instanceof UnitBelongsElsewhere => 'finance.error.unit_elsewhere',
            $refused instanceof QuantityCannotBeCleared => 'finance.error.quantity_cleared',
            $refused instanceof RecordedInTheMeantime => 'finance.error.saved_in_the_meantime',
            $refused instanceof TaxExceedsAmount => 'finance.error.input_tax_exceeds',
            default => 'finance.error.consumption_invalid',
        };
    }

    private function dropped(CostItemYear $year, int $number): Response
    {
        try {
            $this->record->drop($year);
            $this->addFlash('success', 'finance.year.removed');
        } catch (QuantitiesAreRecorded) {
            $this->addFlash('error', 'finance.error.year_has_quantities');
        } catch (UsedByAStatement $held) {
            // Die Absage nennt die Abrechnung: „irgendwo benutzt" laesst den
            // Menschen davor suchen, und er wuerde nicht fuendig.
            $this->addFlash('error', $held->getMessage());
        }

        return $this->back($number);
    }

    private function changed(CostItemYear $year, Request $request): void
    {
        try {
            $this->record->change(
                $year,
                FormInput::intOrNull($request, 'fiscalYear') ?? 0,
                MoneyInput::parse($request->request->getString('amount')),
                self::modeFrom($request),
                MoneyInput::orNull($request->request->getString('inputTax')),
            );
            $this->addFlash('success', 'finance.year.changed');
        } catch (YearAlreadyRecorded|UnreadableAmount|InvalidArgumentException $problem) {
            $this->addFlash('error', self::reason($problem));
        }
    }

    /**
     * Was im Formular steht: je Einheit ein Verbrauch, bei „fertig verteilt"
     * auch ein Betrag.
     *
     * @return array<string, array{consumption: string, amount: ?Money}>
     */
    private static function valuesFrom(Request $request): array
    {
        /** @var array<string, string> $consumptions */
        $consumptions = array_filter($request->request->all('consumption'), \is_string(...));
        /** @var array<string, string> $amounts */
        $amounts = array_filter($request->request->all('amount'), \is_string(...));
        $values = [];

        foreach ($consumptions as $unitId => $consumption) {
            $amount = Trimmed::orNull($amounts[$unitId] ?? '');

            $values[$unitId] = [
                'consumption' => $consumption,
                'amount' => null === $amount ? null : MoneyInput::parse($amount),
            ];
        }

        return $values;
    }

    private function back(int $number): Response
    {
        return $this->redirectToRoute('app_finance_item_edit', [
            'number' => $number,
            'step' => CostItemFlow::AMOUNTS,
        ]);
    }

    private function guard(Request $request, int $number): void
    {
        if (!$this->isCsrfTokenValid('finance_year_'.$number, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }

    /** Ein Jahr — und zwar eines von dieser Position. */
    private static function yearOf(CostItem $item, string $id): CostItemYear
    {
        foreach ($item->years()->all() as $year) {
            if ($year->id() === $id) {
                return $year;
            }
        }

        throw new NotFoundHttpException(\sprintf('Die Kostenposition %d hat dieses Jahr nicht.', $item->number()));
    }

    private static function modeFrom(Request $request): EntryMode
    {
        return EntryMode::tryFrom($request->request->getString('mode')) ?? EntryMode::Total;
    }

    private static function reason(YearAlreadyRecorded|UnreadableAmount|InvalidArgumentException $problem): string
    {
        return match (true) {
            $problem instanceof YearAlreadyRecorded => $problem->getMessage(),
            $problem instanceof TaxExceedsAmount => 'finance.error.input_tax_exceeds',
            default => 'finance.error.amount_invalid',
        };
    }
}
