<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\Proposal;
use App\Module\Billing\Domain\ProposedDocument;
use App\Module\Billing\Domain\Statement;
use App\Module\Billing\Domain\StatementDocument;
use App\Module\Billing\Domain\StatementIsReleased;
use App\Module\Billing\Domain\StatementIterationIsTaken;
use App\Module\Billing\Domain\StatementRepository;
use App\Shared\Money\Money;

/**
 * Eine Korrektur ist ein Klick.
 *
 * Werte und Empfaenger sind bekannt — es gibt nichts mehr auszuwaehlen. Der
 * Knopf legt den Lauf an, uebernimmt dieselben Quellen und fuehrt direkt in
 * die Vorschau.
 *
 * Die Korrektur behaelt Nummer, Objekt, Jahr und Einheit; nur die Iteration
 * zaehlt hoch. Damit steht auf beiden Schreiben erkennbar dieselbe
 * Abrechnung.
 */
final readonly class CorrectStatement
{
    public function __construct(
        private StatementRepository $statements,
        private DraftSelection $selection,
    ) {
    }

    /**
     * Die Korrektur der juengsten Iteration dieser Abrechnungsnummer.
     *
     * Nicht die des uebergebenen Laufs: der Knopf kann von einer laengst
     * ueberholten Iteration kommen — aus einem offenen Tab, aus dem
     * Zurueckknopf des Browsers. Korrigiert wird immer der aktuelle Stand,
     * sonst entstuende dieselbe Iteration zweimal.
     *
     * @throws StatementIsReleased       wenn es nichts zu korrigieren gibt
     * @throws StatementIterationIsTaken wenn jemand schneller war
     */
    public function of(Statement $original): Statement
    {
        $latest = $this->latestOf($original->number()) ?? $original;

        if ($latest->isDraft()) {
            throw StatementIsReleased::already();
        }

        // Der Zeitraum kommt vom Original und nicht aus dem Objekt: eine
        // Korrektur, die einen anderen Zeitraum abrechnet, korrigiert nichts.
        $correction = new Statement(
            $latest->number(),
            $latest->propertyId(),
            $latest->propertyNumber(),
            $latest->period(),
        );
        $correction->corrects($latest);
        $correction->describe($latest->label(), $latest->kinds());
        $this->statements->save($correction);

        $chosen = $this->selection->of($latest);
        $this->selection->keep($correction, $chosen['costs'], $chosen['payments']);

        return $correction;
    }

    /**
     * Der offene Korrekturentwurf dieser Nummer — wenn es einen gibt.
     *
     * Je Abrechnungsnummer hoechstens einer. Zwei gleichzeitig offene
     * Korrekturen waeren zwei Antworten auf dieselbe Frage, und die zweite
     * liefe beim Freigeben in den eindeutigen Index.
     */
    public function openFor(int $number): ?Statement
    {
        $latest = $this->latestOf($number);

        return null !== $latest && $latest->isDraft() ? $latest : null;
    }

    /**
     * Der Vorschlag, ergaenzt um die weggefallenen Empfaenger.
     *
     * Ein Empfaenger kann verschwinden: der Mietbeginn wird auf den 1.
     * Februar berichtigt, und das Schreiben ab dem 1. Januar hat es nie
     * gegeben. Der heutige Vorschlag kennt nur noch den neuen Zeitraum — das
     * alte Schreiben stuende ohne Ausgleich in der Welt, und der neue Betrag
     * kaeme in voller Hoehe obendrauf.
     *
     * Fuer jedes bereits abgerechnete Schreiben, zu dem es heute keines mehr
     * gibt, entsteht darum ein Ausgleich: heutiger Betrag null, bereits
     * abgerechnet der alte. Was herauskommt, ist die Gutschrift ueber genau
     * das, was zu Unrecht gefordert wurde.
     */
    public function balanced(Statement $statement, Proposal $proposal): Proposal
    {
        $today = [];

        foreach ($proposal->documents as $document) {
            $today[$document->key()] = true;
        }

        $settlements = [];

        foreach ($this->billedBefore($statement) as $key => $document) {
            if (!isset($today[$key])) {
                $settlements[] = self::nothingMoreFor($document);
            }
        }

        return [] === $settlements
            ? $proposal
            : new Proposal([...$proposal->documents, ...$settlements], $proposal->missing);
    }

    /**
     * Was einem Empfaenger bereits berechnet wurde.
     *
     * Die Summe aller bisherigen Iterationen: das Original und jede Korrektur
     * davor. Eine Korrektur weist nur noch aus, was daran fehlt.
     */
    public function alreadySettled(Statement $correction, string $recipientKey): Money
    {
        $settled = Money::zero();

        foreach ($this->statements->iterationsOf($correction->number()) as $iteration) {
            if ($iteration->id() === $correction->id() || $iteration->isDraft()) {
                continue;
            }

            foreach ($iteration->documents() as $document) {
                if (self::keyOf($document) === $recipientKey) {
                    $settled = $settled->plus($document->balance());
                }
            }
        }

        return $settled;
    }

    /** Die juengste Iteration einer Abrechnungsnummer, offen oder nicht. */
    private function latestOf(int $number): ?Statement
    {
        $latest = null;

        foreach ($this->statements->iterationsOf($number) as $iteration) {
            if (null === $latest || $latest->iteration() < $iteration->iteration()) {
                $latest = $iteration;
            }
        }

        return $latest;
    }

    /**
     * Jeder Empfaenger, der in einer freigegebenen Iteration schon Post bekam.
     *
     * Der Entwurf selbst zaehlt nicht mit — er ist ja der, der gerade
     * entsteht. Bei mehreren Iterationen gewinnt die juengste: ihre Anschrift
     * ist die zuletzt bekannte.
     *
     * @return array<string, StatementDocument>
     */
    private function billedBefore(Statement $statement): array
    {
        $billed = [];

        foreach ($this->statements->iterationsOf($statement->number()) as $iteration) {
            if ($iteration->isDraft()) {
                continue;
            }

            foreach ($iteration->documents() as $document) {
                $billed[self::keyOf($document)] = $document;
            }
        }

        return $billed;
    }

    /** Dasselbe Schreiben, ohne eine einzige Zeile. */
    private static function nothingMoreFor(StatementDocument $document): ProposedDocument
    {
        return new ProposedDocument(
            kind: $document->kind(),
            unitId: $document->unitId(),
            unitNumber: $document->unitNumber(),
            unitLabel: $document->unitLabel(),
            from: $document->periodFrom(),
            to: $document->period()->to(),
            recipientLabel: $document->recipient()->label(),
            recipientAddress: $document->recipient()->address(),
            lines: [],
            advances: [],
            letting: $document->letting(),
        );
    }

    private static function keyOf(StatementDocument $document): string
    {
        return $document->unitId().'|'.$document->kind()->value.'|'.$document->periodFrom()->format('Y-m-d');
    }
}
