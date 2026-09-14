<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\Plan;
use App\Module\Billing\Domain\PlanDocument;
use App\Module\Billing\Domain\PlanDrift;
use App\Module\Billing\Domain\PlanHasDrifted;
use App\Module\Billing\Domain\PlanIsIncomplete;
use App\Module\Billing\Domain\PlanIsReleased;
use App\Module\Billing\Domain\PlanRepository;
use App\Module\Finance\Contract\AdvanceSchedules;
use App\Module\Finance\Contract\PlannedAdvance;
use DateTimeImmutable;

/**
 * Ein Wirtschaftsplan wird beschlossen — und die Vorschuesse gelten.
 *
 * Zwei Dinge passieren hier, und sie gehoeren zusammen: die Schreiben werden
 * eingefroren, und die Hausgeldstaffel bekommt je Einheit ihre Stufe. Waere
 * das zweierlei, gaebe es zwei Wahrheiten ueber denselben Betrag — der
 * Beschluss sagte das eine, die Buchhaltung das andere, und die
 * Jahresabrechnung rechnete gegen die falsche Zahl.
 *
 * **Und zwar in einer Transaktion.** Scheitert die siebte von zwoelf Stufen,
 * stuende sonst ein beschlossener Plan mit halb geschriebener Staffel da — und
 * ein zweiter Versuch waere gesperrt, weil der Plan ja schon beschlossen ist.
 * Genau der Zustand, aus dem niemand mehr herauskommt.
 *
 * **Beschlossen wird, was vorlag.** Gibt es eine herausgegebene Vorlage, sind
 * ihre Schreiben schon eingefroren — die Freigabe uebernimmt genau sie und
 * rechnet nicht neu. Sonst stuenden in der Staffel Betraege, ueber die niemand
 * abgestimmt hat: zwischen Versammlung und Freigabe kann sich die Grundlage
 * geaendert haben, ein berichtigter Anteil, ein nachgetragener Verbrauch. Wer
 * ohne Vorlage freigibt, bekommt den heutigen Stand — dann hat eben nichts
 * vorgelegen.
 *
 * **Aber nicht versehentlich.** Hat sich die Grundlage seit der Herausgabe
 * geaendert, verweigert sich die Freigabe, bis jemand sagt, dass es ihm
 * bewusst ist. Ein Hinweis auf einer Seite genuegt dafuer nicht: die Schritte
 * sind einzeln erreichbar, und wer gleich auf den Beschluss springt, hat ihn
 * nie gesehen. Die Pruefung gehoert dorthin, wo entschieden wird.
 *
 * Der Freigabetag ist auch das Briefdatum. Er wird hier gesetzt und nie
 * wieder; ein Blatt, das in einem Jahr erneut entsteht, traegt denselben Tag.
 *
 * Die Stufe beginnt nicht heute, sondern am ersten Faelligkeitstag: ein im
 * Oktober beschlossener Plan fuer das naechste Jahr gilt ab dessen erstem Tag.
 * Bis dahin gilt die Stufe davor, und danach gilt diese, bis eine neue
 * beginnt — die Fortgeltung des Plans ergibt sich damit von selbst.
 */
final readonly class ReleasePlan
{
    public function __construct(
        private PlanRepository $plans,
        private ComposePlan $compose,
        private AdvanceSchedules $schedules,
    ) {
    }

    /**
     * @param bool $despiteDrift ob die Abweichung von der Vorlage bewusst in
     *                           Kauf genommen wird
     *
     * @throws PlanIsReleased
     * @throws PlanIsIncomplete
     * @throws PlanHasDrifted
     */
    public function release(Plan $plan, DateTimeImmutable $on, bool $despiteDrift): void
    {
        if (!$plan->stage()->isOpen()) {
            throw PlanIsReleased::already();
        }

        if ($plan->stage()->wasProposed()) {
            $this->refuseDrift($plan, $despiteDrift);
        } else {
            $this->freezeWhatItWouldBe($plan);
        }

        if ([] === $plan->documents()) {
            throw PlanIsIncomplete::thereIsNothingToSend();
        }

        $plan->releaseOn($on);

        $this->plans->atomically(function () use ($plan): void {
            $this->plans->save($plan);
            $this->schedules->decided(self::advancesOf($plan));
        });
    }

    /**
     * Sieht das Blatt heute anders aus als das vorgelegte?
     *
     * Dann haelt die Freigabe an. Nicht, weil das Vorgelegte falsch waere —
     * es ist ja das, worueber abgestimmt wurde —, sondern weil die Betraege
     * ein Jahr lang gelten und niemand sie versehentlich festschreiben soll.
     *
     * @throws PlanHasDrifted
     */
    private function refuseDrift(Plan $plan, bool $despiteDrift): void
    {
        if (!$despiteDrift && PlanDrift::between($plan, $this->compose->of($plan))) {
            throw PlanHasDrifted::sinceItWasProposed();
        }
    }

    /**
     * Ohne Vorlage: rechnen und einfrieren wie sonst auch.
     *
     * @throws PlanIsIncomplete
     */
    private function freezeWhatItWouldBe(Plan $plan): void
    {
        $proposal = $this->compose->of($plan);

        if (!$proposal->isComplete()) {
            throw PlanIsIncomplete::figuresAreMissing();
        }

        FreezePlan::of($plan, $proposal);
    }

    /**
     * Die beschlossenen Vorschuesse, wie die Finanzen sie entgegennehmen.
     *
     * Aus den eingefrorenen Schreiben und nicht aus dem Vorschlag: was in der
     * Staffel steht, muss das sein, was auf dem Blatt steht.
     *
     * @return list<PlannedAdvance>
     */
    private static function advancesOf(Plan $plan): array
    {
        $terms = $plan->terms();

        return array_map(
            static fn (PlanDocument $document): PlannedAdvance => new PlannedAdvance(
                $document->unitId(),
                $terms->firstDueOn(),
                $document->advance(),
                $terms->interval(),
                $document->reference()->toString(),
            ),
            $plan->documents(),
        );
    }
}
