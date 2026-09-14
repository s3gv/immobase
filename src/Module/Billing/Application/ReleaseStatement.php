<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\Letting;
use App\Module\Billing\Domain\Proposal;
use App\Module\Billing\Domain\ProposedDocument;
use App\Module\Billing\Domain\Statement;
use App\Module\Billing\Domain\StatementAdvance;
use App\Module\Billing\Domain\StatementDocument;
use App\Module\Billing\Domain\StatementIsIncomplete;
use App\Module\Billing\Domain\StatementIsReleased;
use App\Module\Billing\Domain\StatementLine;
use App\Module\Billing\Domain\StatementRepository;
use DateTimeImmutable;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Aus einem Vorschlag wird Post.
 *
 * Die Freigabe schreibt genau das fort, was in der Vorschau stand — dieselbe
 * Berechnung, dasselbe Ergebnis. Ab da ist alles daran eingefroren: Name,
 * Anschrift, Betraege, Beschriftungen. Aendert sich spaeter eine
 * Kostenposition, aendert das an diesem Schreiben nichts; es meldet sich nur
 * die Korrektur.
 *
 * Der Freigabetag ist auch das Briefdatum. Er wird hier gesetzt und nie
 * wieder — ein PDF, das morgen erneut erzeugt wird, traegt denselben Tag.
 */
final readonly class ReleaseStatement
{
    public function __construct(
        private StatementRepository $statements,
        private ComposeStatement $compose,
        private DraftSelection $selection,
        private CorrectStatement $corrections,
        private StatementInvoicing $invoicing,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @throws StatementIsReleased
     * @throws StatementIsIncomplete
     */
    public function release(Statement $statement, DateTimeImmutable $on): void
    {
        if (!$statement->isDraft()) {
            throw StatementIsReleased::already();
        }

        $proposal = $this->proposalFor($statement);

        if (!$proposal->isComplete()) {
            throw StatementIsIncomplete::figuresAreMissing();
        }

        if ($proposal->isEmpty()) {
            throw StatementIsIncomplete::thereIsNothingToSend();
        }

        $this->freeze($statement, $proposal, $this->lettingsOf($statement, $proposal));
        $statement->releaseOn($on);
        $this->statements->save($statement);
    }

    /**
     * Was heute herauskaeme — einschliesslich der Empfaenger, die es nicht
     * mehr gibt.
     *
     * Ein Vorschlag, der nur die heutigen Empfaenger kennt, laesst ein
     * bereits zugestelltes Schreiben unausgeglichen stehen. Siehe
     * {@see CorrectStatement::balanced()}.
     */
    public function proposalFor(Statement $statement): Proposal
    {
        $chosen = $this->selection->of($statement);

        return $this->corrections->balanced(
            $statement,
            $this->compose->of($statement, $chosen['costs'], $chosen['payments']),
        );
    }

    /**
     * Mit Umsatzsteuer: wer die Rechnung stellt und was ihre E-Rechnung braucht.
     *
     * Vor dem Einfrieren und fuer alle Schreiben auf einmal — fehlt einem
     * etwas, geht keines hinaus.
     *
     * @return array<string, Letting> Schluessel des Schreibens auf sein Mietverhaeltnis
     *
     * @throws StatementIsIncomplete
     */
    private function lettingsOf(Statement $statement, Proposal $proposal): array
    {
        $lettings = [];

        foreach ($proposal->documents as $proposed) {
            $letting = $this->invoicing->of($statement, $proposed);
            $missing = StatementInvoicing::gapsOf($letting);

            if ([] !== $missing) {
                throw StatementIsIncomplete::invoiceDataIsMissing($proposed->unitLabel.' · '.$proposed->recipientLabel, array_map(fn (string $key): string => $this->translator->trans($key), $missing));
            }

            $lettings[$proposed->key()] = $letting;
        }

        return $lettings;
    }

    /**
     * @param array<string, Letting> $lettings
     */
    private function freeze(Statement $statement, Proposal $proposal, array $lettings): void
    {
        $withoutPdf = $this->statements->withoutPdf($statement->id());
        $statement->clearDocuments();
        $statement->reserveStood($proposal->reserve());

        foreach ($proposal->documents as $proposed) {
            $document = self::documentOf($statement, $proposed, $lettings[$proposed->key()] ?? $proposed->letting);
            $document->wantPdf(!\in_array($proposed->key(), $withoutPdf, true));

            if ($statement->isCorrection()) {
                $document->settle($this->corrections->alreadySettled($statement, $proposed->key()));
            }
        }
    }

    private static function documentOf(Statement $statement, ProposedDocument $proposed, Letting $letting): StatementDocument
    {
        $document = new StatementDocument(
            $statement,
            $proposed->kind,
            $proposed->unitId,
            $proposed->unitNumber,
            $proposed->unitLabel,
            $proposed->from,
            $proposed->to,
            $proposed->recipientLabel,
            $proposed->recipientAddress,
            $letting,
        );

        self::fill($document, $proposed);
        $document->total($proposed->costs(), $proposed->paid(), $proposed->tax(), $proposed->advancesTax());

        return $document;
    }

    /** Die Zeilen haengen sich beim Anlegen selbst an das Dokument. */
    private static function fill(StatementDocument $document, ProposedDocument $proposed): void
    {
        foreach ($proposed->lines as $at => $line) {
            $frozen = new StatementLine(
                $document,
                $at + 1,
                $line->costKind,
                $line->distribution,
                $line->total,
                $line->amount,
            );
            $frozen->containing($line->totalInputTax, $line->inputTax);
        }

        foreach ($proposed->advances as $advance) {
            new StatementAdvance(
                $document,
                $advance->dueOn,
                $advance->expected,
                $advance->received,
                $advance->kind,
            );
        }
    }
}
