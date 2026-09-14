<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

use App\Module\Billing\Application\BudgetPositions;
use App\Module\Billing\Domain\Budget;
use App\Module\Billing\Domain\BudgetContents;
use App\Module\Billing\Domain\BudgetRepository;
use App\Module\Billing\Domain\Measure;
use App\Module\Billing\Domain\MeasureKind;
use App\Module\Billing\Domain\Resolution;
use App\Module\Finance\Contract\CostCatalogue;
use App\Shared\Http\FormInput;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;

/**
 * Was ein Budgetschritt entgegennimmt.
 *
 * Je Schritt eine Methode, gemeinsam ist nur die Form: eine Liste von Fehlern
 * je Feld, leer heisst gespeichert.
 *
 * Und eine Regel ueber allen: **wer den Inhalt aendert, nimmt die
 * Beschlussvorlage zurueck.** Was als Inhalt zaehlt, entscheidet
 * {@see BudgetContents} — das Abstimmungsergebnis gehoert nicht dazu, es kommt
 * ja erst nach der Vorlage.
 */
final readonly class BudgetStepInput
{
    public function __construct(
        private BudgetRepository $budgets,
        private BudgetPositions $positions,
        private BudgetFunding $funding,
        private CostCatalogue $catalogue,
    ) {
    }

    /**
     * @return array<string, string> Feldname auf Uebersetzungsschluessel
     */
    public function apply(string $step, Request $request, Budget $budget): array
    {
        $before = BudgetContents::of($budget);
        $errors = match ($step) {
            BudgetFlow::MEASURE => $this->measure($request, $budget),
            BudgetFlow::COSTS => $this->rows($request, $budget),
            BudgetFlow::FUNDING => $this->funding->apply($request, $budget),
            BudgetFlow::SHARING => $this->sharing($request, $budget),
            BudgetFlow::DECISION => $this->decision($request, $budget),
            default => [],
        };

        if ([] === $errors) {
            $this->withdrawIfChanged($budget, $before);
        }

        return $errors;
    }

    /**
     * Bleibt der Ablauf auf diesem Schritt stehen?
     *
     * Eine Zeile hinzufuegen oder entfernen ist keine Bewegung nach vorn.
     */
    public static function staysHere(Request $request): bool
    {
        return $request->request->has('add') || $request->request->has('remove');
    }

    private function withdrawIfChanged(Budget $budget, string $before): void
    {
        if (BudgetContents::of($budget) === $before) {
            return;
        }

        $budget->revise();
        $this->budgets->save($budget);
    }

    /** @return array<string, string> */
    private function measure(Request $request, Budget $budget): array
    {
        $kind = MeasureKind::tryFrom($request->request->getString('kind')) ?? $budget->measure()->kind();
        $budget->plan(Measure::of(
            $request->request->getString('label'),
            $kind,
            $budget->measure()->firstYear(),
            FormInput::intOrNull($request, 'amortisesIn'),
        ));
        $this->budgets->save($budget);

        return [];
    }

    /**
     * Die Positionen — uebernehmen, und erst danach anlegen oder entfernen.
     *
     * @return array<string, string>
     */
    private function rows(Request $request, Budget $budget): array
    {
        $read = BudgetRows::from($request, 'rows', $budget->measure()->firstYear());

        if ([] !== $read['errors']) {
            return $read['errors'];
        }

        $this->positions->keep($budget, $read['rows']);

        if ($request->request->has('add')) {
            $this->positions->add($budget);
        }

        $removed = $request->request->getString('remove');

        if ('' !== $removed) {
            $this->positions->remove($budget, $removed);
        }

        return [];
    }

    /**
     * Der Verteilerschluessel.
     *
     * Was nicht im Katalog steht, wird nicht uebernommen: ein abgeschicktes
     * Formular ist Eingabe und keine Zusicherung.
     *
     * @return array<string, string>
     */
    private function sharing(Request $request, Budget $budget): array
    {
        $chosen = $request->request->getString('key');

        foreach ($this->catalogue->keysFor($budget->propertyId()) as $key) {
            if ($key->id === $chosen) {
                $budget->distributeBy($key->id, $key->label, $key->kind);
                $this->budgets->save($budget);

                return [];
            }
        }

        return [] === $this->catalogue->keysFor($budget->propertyId()) ? [] : ['key' => 'billing.budget.error.no_key'];
    }

    /**
     * Was die Versammlung entschieden hat — und wie sie abgestimmt hat.
     *
     * Die Stimmen werden getippt, die zustimmenden Einheiten angehakt. Aus den
     * Haekchen rechnet die Anwendung die Miteigentumsanteile; aus den Zahlen
     * kann sie es nicht, weil das Stimmprinzip in der Gemeinschaftsordnung
     * steht.
     *
     * @return array<string, string>
     */
    private function decision(Request $request, Budget $budget): array
    {
        $budget->decide(
            Resolution::of(
                self::dayOrNull($request->request->getString('decidedOn')),
                $request->request->getString('outcome'),
                $request->request->getString('decisionNumber'),
            ),
            FormInput::intOrNull($request, 'votesCast') ?? 0,
            FormInput::intOrNull($request, 'votesFor') ?? 0,
        );
        $this->budgets->save($budget);
        $this->budgets->replaceApprovals($budget->id(), self::approvalsIn($request));

        return [];
    }

    /**
     * @return list<string>
     */
    private static function approvalsIn(Request $request): array
    {
        $ticked = [];

        foreach ($request->request->all('agreed') as $unitId) {
            if (\is_string($unitId) && '' !== $unitId) {
                $ticked[] = $unitId;
            }
        }

        return $ticked;
    }

    private static function dayOrNull(string $value): ?DateTimeImmutable
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        return false === $day ? null : $day;
    }
}
