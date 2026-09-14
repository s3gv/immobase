<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\Plan;
use App\Module\Billing\Domain\PlanLineKind;
use App\Module\Billing\Domain\PlanPosition;
use App\Module\Billing\Domain\PlanRepository;
use App\Module\Billing\Domain\PlanSource;
use App\Module\Finance\Contract\CostCatalogue;
use App\Module\Finance\Contract\CostKindBrief;
use App\Module\Finance\Contract\DistributionKeyBrief;
use App\Shared\Money\Money;

/**
 * Die Zeilen eines Plans pflegen.
 *
 * Jede Zeile wird ueber ihre eigene Kennung angesprochen und nicht ueber ihre
 * Stelle in der Liste. Derselbe Grund wie bei den Eigentuemerzeilen: wer eine
 * Zeile entfernt und dann speichert, verschoebe sonst alle darunter.
 *
 * Beschriftungen werden bei jeder Aenderung mitgeschrieben. Sie stehen an der
 * Zeile, damit die Freigabe sie einfrieren kann — und damit eine zwischendurch
 * umbenannte Kostenart den Entwurf nicht unlesbar macht.
 */
final readonly class PlanPositions
{
    public function __construct(
        private PlanRepository $plans,
        private CostCatalogue $catalogue,
    ) {
    }

    /**
     * Die eingegebenen Werte uebernehmen.
     *
     * Die Betraege kommen schon als {@see Money} herein. Das Lesen einer
     * getippten Zahl ist Sache der Oberflaeche — sie ist es, die eine
     * unlesbare Eingabe zurueckweisen und dabei stehen lassen muss.
     *
     * @param array<string, array{kind: string, key: string, planned: Money, oneOff: bool, reason: string}> $rows Kennung der Zeile auf ihre Felder
     */
    public function keep(Plan $plan, array $rows): void
    {
        $kinds = $this->kindsById();
        $keys = $this->keysById($plan->propertyId());

        foreach ($plan->positions() as $position) {
            $row = $rows[$position->id()] ?? null;

            if (null !== $row) {
                self::apply($position, $row, $kinds, $keys);
            }
        }

        $this->plans->save($plan);
        $this->holdSources($plan);
    }

    /** Eine leere Zeile ans Ende — die erste Kostenart und der erste Schluessel. */
    public function add(Plan $plan): void
    {
        $kind = $this->catalogue->kinds()[0] ?? null;
        $key = $this->catalogue->keysFor($plan->propertyId())[0] ?? null;
        $position = new PlanPosition($plan, self::nextOrdering($plan), PlanLineKind::Cost);
        $position->reclassify($kind?->id, $kind->label ?? '', $kind->apportionable ?? false);
        $position->distributeBy($key?->id, $key->label ?? '', $key->kind ?? '');
        $this->plans->save($plan);
        $this->holdSources($plan);
    }

    /**
     * Eine Zeile entfernen — die Ruecklage nicht.
     *
     * Ueber sie wird nach § 28 Abs. 1 WEG eigens beschlossen; ein Plan ohne sie
     * waere einer, in dem jemand vergessen hat, sie zu beantragen. Wer nichts
     * zufuehren will, plant null.
     */
    public function remove(Plan $plan, string $positionId): void
    {
        foreach ($plan->positions() as $position) {
            if ($position->id() === $positionId && !$position->isReserve()) {
                $plan->drop($position);
            }
        }

        $this->plans->save($plan);
        $this->holdSources($plan);
    }

    /**
     * Festhalten, worauf der Plan steht.
     *
     * Solange er ein Entwurf ist, wandert die Sperre mit jeder Aenderung mit;
     * ein geloeschter Entwurf gibt sie wieder frei.
     */
    public function holdSources(Plan $plan): void
    {
        $sources = [];
        $seen = [];

        foreach ($plan->positions() as $position) {
            foreach ([$position->key()->id(), $position->costKindId()] as $at => $id) {
                if (null === $id || isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;
                $sources[] = 0 === $at
                    ? PlanSource::key($plan->id(), $id)
                    : PlanSource::costKind($plan->id(), $id);
            }
        }

        $this->plans->replaceSources($plan->id(), $sources);
    }

    /**
     * @param array{kind: string, key: string, planned: Money, oneOff: bool, reason: string} $row
     * @param array<string, CostKindBrief>                                                   $kinds
     * @param array<string, DistributionKeyBrief>                                            $keys
     */
    private static function apply(PlanPosition $position, array $row, array $kinds, array $keys): void
    {
        $kind = $kinds[$row['kind']] ?? null;

        // Die Ruecklage behaelt ihre Art: sie ist keine Kostenart, und ein
        // untergeschobenes Feld soll sie nicht zu einer machen.
        if (null !== $kind && !$position->isReserve()) {
            $position->reclassify($kind->id, $kind->label, $kind->apportionable);
        }

        $key = $keys[$row['key']] ?? null;

        if (null !== $key) {
            $position->distributeBy($key->id, $key->label, $key->kind);
        }

        $position->plan($row['planned'], $row['oneOff'], $row['reason']);
    }

    private static function nextOrdering(Plan $plan): int
    {
        $last = 0;

        foreach ($plan->positions() as $position) {
            $last = max($last, $position->ordering());
        }

        return $last + 1;
    }

    /** @return array<string, CostKindBrief> */
    private function kindsById(): array
    {
        $found = [];

        foreach ($this->catalogue->kinds() as $kind) {
            $found[$kind->id] = $kind;
        }

        return $found;
    }

    /** @return array<string, DistributionKeyBrief> */
    private function keysById(string $propertyId): array
    {
        $found = [];

        foreach ($this->catalogue->keysFor($propertyId) as $key) {
            $found[$key->id] = $key;
        }

        return $found;
    }
}
