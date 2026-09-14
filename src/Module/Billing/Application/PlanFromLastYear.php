<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\Plan;
use App\Module\Billing\Domain\PlanLineKind;
use App\Module\Billing\Domain\PlanPosition;
use App\Module\Finance\Contract\CostCatalogue;
use App\Module\Finance\Contract\CostDirectory;
use App\Module\Finance\Contract\CostKindBrief;
use App\Module\Finance\Contract\CostRecord;
use App\Module\Finance\Contract\DistributionKeyBrief;
use App\Module\Finance\Contract\LoanDirectory;
use App\Module\Finance\Contract\ReserveDirectory;
use App\Shared\Money\Money;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Die Zeilen eines neuen Plans, vorbelegt aus dem Ist des Vorjahres.
 *
 * So plant die Praxis: die tatsaechlichen Kosten des Vorjahres sind der
 * Ausgangspunkt, und was sich absehbar aendert — eine neue Praemie, ein neuer
 * Vertrag — wird ueberschrieben. Ein leeres Formular waere die Aufforderung,
 * fuenfzehn Zahlen aus dem Kopf einzutragen.
 *
 * Vorbelegt heisst **vorgeschlagen**: der Planwert steht auf dem Vorjahreswert
 * und ist jede Zeile einzeln aenderbar. Was nicht wiederkommt, bekommt das
 * Haekchen „einmalig" — die Zeile bleibt sichtbar und plant null.
 *
 * Die Darlehen des Objekts kommen als zwei Zeilen dazu — Zins und Tilgung —,
 * sobald eines gefuehrt wird. Beide sind gewoehnliche Kostenzeilen: der
 * Wirtschaftsplan bekommt keinen zweiten Mechanismus, nur zwei Zahlen mehr,
 * die er nicht abtippen laesst.
 *
 * Die Zufuehrung zur Erhaltungsruecklage kommt als eigene Zeile dazu, immer.
 * Ueber sie wird nach § 28 Abs. 1 WEG getrennt beschlossen, und ein Plan ohne
 * sie waere ein Plan, in dem jemand vergessen hat, sie zu beantragen.
 */
final readonly class PlanFromLastYear
{
    public function __construct(
        private CostDirectory $costs,
        private CostCatalogue $catalogue,
        private ReserveDirectory $reserve,
        private LoanDirectory $loans,
        private SavedByBudgets $budgets,
        private TranslatorInterface $translator,
        private PlanPositions $positions,
    ) {
    }

    /**
     * Die Zeilen anlegen — nur, solange es keine gibt.
     *
     * Ein zweiter Durchgang ueberschriebe, was jemand von Hand geaendert hat.
     */
    public function fill(Plan $plan): void
    {
        if ([] !== $plan->positions()) {
            return;
        }

        $before = $plan->period()->year() - 1;
        $at = 0;

        foreach ($this->costs->forYear($plan->propertyId(), $before) as $cost) {
            self::costPosition($plan, ++$at, $cost);
        }

        $at = $this->loanPositions($plan, $at, $before);
        $this->reservePosition($plan, ++$at, $before);

        // Was der Plan benutzt, haelt er ab jetzt fest. Nicht erst beim
        // ersten Bearbeiten: ein frischer Entwurf steht schon auf seinen
        // Kostenarten und Schluesseln, und die duerfen nicht unter ihm
        // verschwinden.
        $this->positions->holdSources($plan);
    }

    private static function costPosition(Plan $plan, int $at, CostRecord $cost): void
    {
        $position = new PlanPosition($plan, $at, PlanLineKind::Cost);
        $position->reclassify($cost->costKindId, $cost->kindLabel, $cost->apportionable);
        $position->distributeBy($cost->keyId, $cost->keyLabel, $cost->keyKind);
        $position->had($cost->total, $cost->costYearId);
        $position->plan($cost->total, false, '');
    }

    /**
     * Zwei Zeilen fuer die Darlehen — Zins und Tilgung, getrennt.
     *
     * **Kein eigener Mechanismus.** Es sind gewoehnliche Kostenzeilen mit
     * zwei mitgelieferten Kostenarten, vorbelegt wie alles andere aus dem,
     * was da ist: der Vorjahreswert aus dem Tilgungsplan des Vorjahres, der
     * Planwert aus dem des Planjahres. Beides ist gerechnet und aenderbar.
     *
     * Ohne gefuehrtes Darlehen stehen sie gar nicht erst da — zwei Nullen
     * waeren zwei Zeilen, ueber die jemand abstimmen muesste.
     *
     * @return int die Stelle, an der es weitergeht
     */
    private function loanPositions(Plan $plan, int $at, int $before): int
    {
        $planned = $this->loans->burdenIn($plan->propertyId(), $plan->period()->year());
        $had = $this->loans->burdenIn($plan->propertyId(), $before);

        if ($planned->isZero() && $had->isZero()) {
            return $at;
        }

        $kinds = $this->catalogue->loanKinds();
        $key = $this->meaKey($plan->propertyId());
        $this->loanPosition($plan, ++$at, $kinds->interest, $key, $had->interest, $planned->interest);
        $this->loanPosition($plan, ++$at, $kinds->principal, $key, $had->principal, $planned->principal);

        return $at;
    }

    /**
     * Eine der beiden Darlehenszeilen.
     *
     * Verteilt wird nach Miteigentumsanteilen: die Schuld traegt die
     * Gemeinschaft, und wem wie viel davon gehoert, sagt der Anteil. Ein
     * anderer Schluessel ist beschliessbar und darum aenderbar.
     */
    private function loanPosition(
        Plan $plan,
        int $at,
        ?CostKindBrief $kind,
        ?DistributionKeyBrief $key,
        Money $had,
        Money $planned,
    ): void {
        $position = new PlanPosition($plan, $at, PlanLineKind::Cost);
        $position->reclassify($kind?->id, $kind->label ?? '', false);
        $position->distributeBy($key?->id, $key->label ?? '', $key->kind ?? '');
        $position->had($had);
        $position->plan($planned, false, $this->translator->trans('billing.plan.loan.from_loan'));
    }

    /**
     * Die Ruecklagenzeile, vorbelegt mit der Zufuehrung des Vorjahres.
     *
     * Verteilt wird nach Miteigentumsanteilen: die Ruecklage gehoert der
     * Gemeinschaft, und wem wie viel davon gehoert, sagt der Anteil. Ein
     * anderer Schluessel ist beschliessbar und darum aenderbar.
     *
     * **Ein beschlossener Budgetplan hebt sie.** Wer ansparen beschlossen hat,
     * soll die Zahl hier wiederfinden und nicht aus einem anderen Beschluss
     * abtippen. Der Vorjahreswert bleibt daneben stehen — er ist der Massstab,
     * an dem man die Erhoehung sieht, und die Begruendung sagt, woher sie
     * kommt.
     */
    private function reservePosition(Plan $plan, int $at, int $before): void
    {
        $key = $this->meaKey($plan->propertyId());
        $position = new PlanPosition($plan, $at, PlanLineKind::Reserve);
        $position->distributeBy($key?->id, $key->label ?? '', $key->kind ?? '');
        $contributed = $this->reserve->contributionsIn($plan->propertyId(), $before);
        $decided = $this->budgets->inYear($plan->propertyId(), $plan->period()->year());
        $position->had($contributed);
        $position->plan(
            $contributed->plus($decided),
            false,
            // Die Begruendung ist vorbelegt und bleibt aenderbar: sie steht
            // spaeter auf dem Blatt, und dort gehoert hin, warum die
            // Zufuehrung steigt.
            $decided->isZero() ? '' : $this->translator->trans('billing.plan.reserve.from_budget'),
        );
    }

    /**
     * Der Schluessel nach Miteigentumsanteilen.
     *
     * Es gibt ihn als Systemschluessel, also findet er sich. Faende er sich
     * doch nicht, bleibt die Zeile ohne Schluessel stehen und die Berechnung
     * meldet die Luecke — besser als ein Schluessel, den niemand gewaehlt hat.
     */
    private function meaKey(string $propertyId): ?DistributionKeyBrief
    {
        foreach ($this->catalogue->keysFor($propertyId) as $key) {
            if ('mea' === $key->kind) {
                return $key;
            }
        }

        return null;
    }
}
