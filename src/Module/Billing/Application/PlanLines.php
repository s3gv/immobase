<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\Distribution;
use App\Module\Billing\Domain\Plan;
use App\Module\Billing\Domain\PlannedDocument;
use App\Module\Billing\Domain\PlannedLine;
use App\Module\Billing\Domain\PlanPosition;
use App\Module\Billing\Domain\ProposedLine;
use App\Module\Property\Contract\UnitBrief;
use App\Shared\Money\Money;

/**
 * Der Einzelwirtschaftsplan einer Einheit, Zeile fuer Zeile.
 *
 * In der Reihenfolge des Gesamtplans und mit jeder Zeile, die etwas
 * ausweist — auch denen, die null planen. Eine Dachreparatur, die dieses Jahr
 * nicht wiederkommt, steht mit ihrem Vorjahreswert und einer Null daneben da;
 * wer sie vermisst, soll sie finden und nicht raten, ob sie vergessen wurde.
 *
 * Was gar nicht verteilt wurde, fehlt: eine Einheit ohne Flaeche traegt an
 * einer Flaechenposition nichts, und eine Zeile ueber null Euro mit einem
 * Anteil von null waere eine Behauptung ueber eine Rechnung, die nie
 * stattfand.
 */
final readonly class PlanLines
{
    public function __construct(private WhatWasPlanned $planned)
    {
    }

    /**
     * @param array<string, ProposedLine>           $amounts Kennung der Position auf ihren Anteil
     * @param array{label: string, address: string} $whom
     */
    public function documentFor(Plan $plan, UnitBrief $unit, array $amounts, array $whom): PlannedDocument
    {
        $lines = [];

        foreach (self::reserveLast($plan) as $position) {
            $line = $this->lineFor($position, $amounts[$position->id()] ?? null);

            if (null !== $line) {
                $lines[] = $line;
            }
        }

        return new PlannedDocument(
            $unit->id,
            $unit->number,
            $unit->label,
            $whom['label'],
            $whom['address'],
            $lines,
            $plan->terms()->interval(),
        );
    }

    /**
     * Die Zeilen in der Reihenfolge des Blattes: Kosten, dann die Ruecklage.
     *
     * Sie steht hinten, weil sie hinten hingehoert — ueber sie wird eigens
     * beschlossen, und auf dem Blatt hat sie ihre eigene Zwischensumme.
     * Angelegt wurde sie beim Vorbelegen, also stuende sie sonst dort, wo
     * gerade Platz war.
     *
     * @return list<PlanPosition>
     */
    private static function reserveLast(Plan $plan): array
    {
        $costs = [];
        $reserve = [];

        foreach ($plan->positions() as $position) {
            if ($position->isReserve()) {
                $reserve[] = $position;
            } else {
                $costs[] = $position;
            }
        }

        return [...$costs, ...$reserve];
    }

    private function lineFor(PlanPosition $position, ?ProposedLine $share): ?PlannedLine
    {
        if ($position->planned()->isZero()) {
            return $this->nothingPlanned($position);
        }

        return null === $share ? null : new PlannedLine(
            $position->lineKind(),
            $position->costKindLabel(),
            $this->planned->explain($share->distribution, $position->key()->kind()),
            $position->previous(),
            $share->total,
            $share->amount,
            $position->reason(),
        );
    }

    /**
     * Eine Zeile, die nichts verteilt.
     *
     * Der Verteilerschluessel steht trotzdem dabei — er ist beschlossen und
     * gilt, sobald die Position wieder etwas kostet. Anteil und Ganzes sind
     * null, weil nichts geteilt wurde; das Blatt zeigt sie darum nicht.
     */
    private function nothingPlanned(PlanPosition $position): PlannedLine
    {
        return new PlannedLine(
            $position->lineKind(),
            $position->costKindLabel(),
            Distribution::by(
                $position->key()->label(),
                $this->planned->explanationOf($position->key()->kind()),
                '0',
                '0',
            ),
            $position->previous(),
            Money::zero(),
            Money::zero(),
            $position->reason(),
        );
    }
}
