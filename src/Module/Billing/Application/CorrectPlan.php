<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\Plan;
use App\Module\Billing\Domain\PlanIsReleased;
use App\Module\Billing\Domain\PlanIterationIsTaken;
use App\Module\Billing\Domain\PlanPosition;
use App\Module\Billing\Domain\PlanRepository;

/**
 * Eine Korrektur des Wirtschaftsplans ist ein Klick.
 *
 * Die Zeilen sind bekannt — der Knopf legt den Plan an, uebernimmt sie samt
 * Zahlungsbedingungen und Beschluss und fuehrt in die Vorschau. Was sich
 * geaendert hat, rechnet sich beim Zeichnen heraus.
 *
 * **Anders als bei der Abrechnung weist eine Korrektur keine Differenz aus.**
 * Ein Vorschuss ist kein Saldo, sondern ein Betrag, der ab einem Tag gilt. Die
 * Korrektur sagt darum nicht „drei Euro mehr", sondern nennt den neuen
 * Vorschuss — und schreibt ihn als Stufe fort. Wer zu wenig gezahlt hat, holt
 * das nicht hier auf, sondern in der Jahresabrechnung.
 */
final readonly class CorrectPlan
{
    public function __construct(
        private PlanRepository $plans,
        private PlanPositions $positions,
    ) {
    }

    /**
     * Die Korrektur der juengsten Iteration dieser Plannummer.
     *
     * Nicht die des uebergebenen Plans: der Knopf kann von einer laengst
     * ueberholten Iteration kommen — aus einem offenen Tab, aus dem
     * Zurueckknopf des Browsers. Korrigiert wird immer der aktuelle Stand,
     * sonst entstuende dieselbe Iteration zweimal.
     *
     * @throws PlanIsReleased       wenn es nichts zu korrigieren gibt
     * @throws PlanIterationIsTaken wenn jemand schneller war
     */
    public function of(Plan $original): Plan
    {
        $latest = $this->latestOf($original->edition()->number()) ?? $original;

        if ($latest->stage()->isOpen()) {
            throw PlanIsReleased::already();
        }

        // Das Planjahr kommt vom Original und nicht aus dem Objekt: eine
        // Korrektur, die ein anderes Jahr plant, korrigiert nichts.
        $correction = new Plan(
            $latest->edition()->number(),
            $latest->propertyId(),
            $latest->propertyNumber(),
            $latest->period(),
        );
        $correction->corrects($latest);
        $correction->describe($latest->label());
        $correction->payOn($latest->terms());
        $correction->decide($latest->resolution());

        foreach ($latest->positions() as $position) {
            self::copy($position, $correction);
        }

        $this->plans->save($correction);
        $this->positions->holdSources($correction);

        return $correction;
    }

    /**
     * Der offene Korrekturentwurf dieser Nummer — wenn es einen gibt.
     *
     * Je Plannummer hoechstens einer. Zwei gleichzeitig offene Korrekturen
     * waeren zwei Antworten auf dieselbe Frage, und die zweite liefe beim
     * Anlegen in den eindeutigen Index.
     */
    public function openFor(int $number): ?Plan
    {
        $latest = $this->latestOf($number);

        return null !== $latest && $latest->stage()->isOpen() ? $latest : null;
    }

    /** Dieselbe Zeile am neuen Plan — einschliesslich ihrer Vorgeschichte. */
    private static function copy(PlanPosition $position, Plan $to): void
    {
        $copy = new PlanPosition($to, $position->ordering(), $position->lineKind());
        $copy->reclassify($position->costKindId(), $position->costKindLabel(), $position->isApportionable());
        $key = $position->key();
        $copy->distributeBy($key->id(), $key->label(), $key->kind());
        $copy->had($position->previous(), $position->previousSourceId());
        $copy->plan($position->entered(), $position->isOneOff(), $position->reason());
    }

    /** Die juengste Iteration einer Plannummer, offen oder nicht. */
    private function latestOf(int $number): ?Plan
    {
        $latest = null;

        foreach ($this->plans->iterationsOf($number) as $iteration) {
            if (null === $latest || $latest->edition()->iteration() < $iteration->edition()->iteration()) {
                $latest = $iteration;
            }
        }

        return $latest;
    }
}
