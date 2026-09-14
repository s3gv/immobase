<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

use App\Module\Billing\Application\PlanPositions;
use App\Module\Billing\Domain\AdvanceTerms;
use App\Module\Billing\Domain\Plan;
use App\Module\Billing\Domain\PlanContents;
use App\Module\Billing\Domain\PlanRepository;
use App\Module\Billing\Domain\Resolution;
use App\Module\Finance\Contract\Interval;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;

/**
 * Was ein Planschritt entgegennimmt.
 *
 * Je Schritt eine Methode, gemeinsam ist nur die Form: eine Liste von Fehlern
 * je Feld, leer heisst gespeichert.
 *
 * Und eine Regel ueber allen: **wer den Inhalt aendert, nimmt die
 * Beschlussvorlage zurueck.** Der Herausgabetag sagt, was den Eigentuemern an
 * diesem Tag vorlag; nach einer Aenderung stimmt das nicht mehr. Hier ist der
 * eine Trichter, durch den jede Aenderung laeuft, also steht die Pruefung
 * hier — was als Inhalt zaehlt, entscheidet {@see PlanContents}.
 */
final readonly class PlanStepInput
{
    public function __construct(
        private PlanRepository $plans,
        private PlanPositions $positions,
    ) {
    }

    /**
     * @return array<string, string> Feldname auf Uebersetzungsschluessel
     */
    public function apply(string $step, Request $request, Plan $plan): array
    {
        $steps = [
            PlanFlow::BASICS => $this->basics(...),
            PlanFlow::POSITIONS => $this->rows(...),
            PlanFlow::RESERVE => $this->rows(...),
            PlanFlow::ADVANCES => $this->advances(...),
            PlanFlow::DECISION => $this->decision(...),
        ];

        if (!isset($steps[$step])) {
            return [];
        }

        $before = PlanContents::of($plan);
        $errors = $steps[$step]($request, $plan);

        if ([] === $errors) {
            $this->withdrawIfChanged($plan, $before);
        }

        return $errors;
    }

    /**
     * Bleibt der Ablauf auf diesem Schritt stehen?
     *
     * Eine Zeile hinzufuegen oder entfernen ist keine Bewegung nach vorn: wer
     * sie anlegt, will sie ausfuellen, und wer sie entfernt, will die Liste
     * ohne sie sehen.
     */
    public static function staysHere(Request $request): bool
    {
        return $request->request->has('add') || $request->request->has('remove');
    }

    /**
     * Hat der Schritt etwas geaendert, das auf dem Blatt steht?
     *
     * Nur dann — wer sich durch den Ablauf klickt, ohne etwas anzufassen,
     * soll seine herausgegebene Vorlage behalten.
     */
    private function withdrawIfChanged(Plan $plan, string $before): void
    {
        if (PlanContents::of($plan) === $before) {
            return;
        }

        $plan->revise();
        $this->plans->save($plan);
    }

    /** @return array<string, string> */
    private function basics(Request $request, Plan $plan): array
    {
        $plan->describe($request->request->getString('label'));
        $this->plans->save($plan);

        return [];
    }

    /**
     * Die Zeilen — uebernehmen, und erst danach anlegen oder entfernen.
     *
     * In dieser Reihenfolge, weil das Formular mit abgeschickt wird: wer eine
     * Zeile hinzufuegt, hat vielleicht gerade eine Zahl geaendert, und die
     * soll nicht verlorengehen.
     *
     * @return array<string, string>
     */
    private function rows(Request $request, Plan $plan): array
    {
        $read = PlanRows::from($request, 'rows');

        if ([] !== $read['errors']) {
            return $read['errors'];
        }

        $this->positions->keep($plan, $read['rows']);

        if ($request->request->has('add')) {
            $this->positions->add($plan);
        }

        $removed = $request->request->getString('remove');

        if ('' !== $removed) {
            $this->positions->remove($plan, $removed);
        }

        return [];
    }

    /**
     * Die Zahlungsbedingungen: wie oft und ab wann.
     *
     * @return array<string, string>
     */
    private function advances(Request $request, Plan $plan): array
    {
        $firstDue = self::dayOrNull($request->request->getString('firstDueOn'));

        if (null === $firstDue) {
            return ['firstDueOn' => 'billing.plan.error.first_due'];
        }

        $plan->payOn(AdvanceTerms::of(self::intervalOf($request->request->getString('interval')), $firstDue));
        $this->plans->save($plan);

        return [];
    }

    /**
     * Was die Versammlung entschieden hat.
     *
     * Ein eigener Schritt und nicht ein Kasten unter den Vorschuessen: er
     * kommt zeitlich spaeter. Zwischen der Vorlage und dem Beschluss liegt
     * eine Versammlung, und dazwischen wird die Anwendung zugeklappt.
     *
     * Alle drei Angaben sind freiwillig — eine Verwaltung, die ihre
     * Beschlusssammlung anders fuehrt, soll hier nichts erfinden muessen.
     *
     * @return array<string, string>
     */
    private function decision(Request $request, Plan $plan): array
    {
        $plan->decide(Resolution::of(
            self::dayOrNull($request->request->getString('decidedOn')),
            $request->request->getString('outcome'),
            $request->request->getString('decisionNumber'),
        ));
        $this->plans->save($plan);

        return [];
    }

    /**
     * Das Zahlungsintervall — drei kommen in Frage.
     *
     * „Einmalig" ist keines: ein Vorschuss wiederholt sich, das ist sein
     * Wesen. Was nicht passt, wird monatlich, denn so zahlt Hausgeld fast
     * immer — und ein abgeschicktes Formular ist Eingabe, kein Versprechen.
     */
    private static function intervalOf(string $value): Interval
    {
        $interval = Interval::tryFrom($value) ?? Interval::Monthly;

        return Interval::Once === $interval ? Interval::Monthly : $interval;
    }

    private static function dayOrNull(string $value): ?DateTimeImmutable
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        return false === $day ? null : $day;
    }
}
