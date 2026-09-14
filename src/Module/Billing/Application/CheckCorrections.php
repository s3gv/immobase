<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\ProposedDocument;
use App\Module\Billing\Domain\Statement;
use App\Module\Billing\Domain\StatementDocument;
use App\Module\Billing\Domain\StatementRepository;

/**
 * Welche Abrechnungen eine Korrektur brauchen.
 *
 * Nachgerechnet wird der heutige Stand und mit dem eingefrorenen verglichen —
 * **je Empfaenger und nur das Ergebnis**. Eine umbenannte Kostenart oder eine
 * geaenderte Notiz meldet sich nicht; eine Meldung bedeutet immer, dass hier
 * wirklich etwas raus muss.
 *
 * Gerechnet wird beim Betrachten und nicht bei jeder Aenderung. Die Zahl der
 * Dokumente je Objekt und Jahr ist klein, und ein Ereignisweg ueber vier
 * Module waere viermal die Gelegenheit, einen Ausloeser zu vergessen.
 */
final readonly class CheckCorrections
{
    public function __construct(
        private StatementRepository $statements,
        private ReleaseStatement $release,
    ) {
    }

    /**
     * @return array<string, int> Kennung der Abrechnung auf die Zahl der betroffenen Empfaenger
     */
    public function pending(): array
    {
        $found = [];

        foreach (self::latestOnly($this->statements->released()) as $statement) {
            $affected = \count($this->changed($statement));

            if ($affected > 0) {
                $found[$statement->id()] = $affected;
            }
        }

        return $found;
    }

    /**
     * Die Empfaenger, deren Ergebnis heute anders ausfiele.
     *
     * @return list<ProposedDocument>
     */
    public function changed(Statement $statement): array
    {
        if ($statement->isDraft()) {
            return [];
        }

        $frozen = [];

        foreach ($statement->documents() as $document) {
            $frozen[self::keyOf($document)] = $document;
        }

        $changed = [];

        foreach ($this->release->proposalFor($statement)->documents as $today) {
            $before = $frozen[$today->key()] ?? null;

            if (null === $before || !$before->outcome()->result()->equals($today->balance())) {
                $changed[] = $today;
            }
        }

        return $changed;
    }

    /**
     * Von jeder Abrechnung nur die juengste Iteration.
     *
     * Eine korrigierte Abrechnung meldet sich sonst weiter — sie ist ja
     * unveraendert falsch. Beantwortet ist sie trotzdem: von ihrer Korrektur.
     *
     * @param list<Statement> $released
     *
     * @return list<Statement>
     */
    private static function latestOnly(array $released): array
    {
        $latest = [];

        foreach ($released as $statement) {
            $known = $latest[$statement->number()] ?? null;

            if (null === $known || $known->iteration() < $statement->iteration()) {
                $latest[$statement->number()] = $statement;
            }
        }

        return array_values($latest);
    }

    /** Derselbe Schluessel wie am Vorschlag: Einheit, Art und Zeitraum. */
    private static function keyOf(StatementDocument $document): string
    {
        return $document->unitId().'|'.$document->kind()->value.'|'.$document->periodFrom()->format('Y-m-d');
    }
}
