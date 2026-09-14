<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\Application;

use App\Module\Auth\Contract\AuthenticatedUser;
use App\Module\Portal\Domain\Proposal;
use App\Module\Portal\Domain\ProposalDecision;
use App\Module\Portal\Domain\ProposalRepository;
use App\Shared\Change\RecordThatMayChange;
use Symfony\Component\Clock\ClockInterface;

/**
 * Uebernehmen oder ablehnen — und beides sagt es dem, der gefragt hat.
 *
 * **Alles oder nichts.** Abhaken, uebernehmen und Bescheid geben laufen in
 * einer Transaktion, und der Uebergang von „offen" macht die Datenbank
 * bedingt: zwei gleichzeitige Klicks auf „Uebernehmen" faenden beide einen
 * offenen Vorschlag vor, kaemen beide durch die Pruefung und schrieben beide
 * — einmal die Aenderung und einmal die Bestaetigung, zweimal.
 *
 * **Uebernommen heisst: es steht jetzt da.** Verschwindet der Datensatz
 * zwischen Pruefung und Uebernahme, kehrt `apply()` still zurueck; ohne die
 * Nachschau stuende der Vorschlag als uebernommen da, ohne dass sich etwas
 * geaendert haette. Sie faellt dann zurueck, statt das zu behaupten.
 *
 * **Ablehnen braucht eine Begruendung.** Eine Ablehnung ohne Grund ist eine
 * Zumutung — und der Text geht als Nachricht ins Gespraech, damit der
 * Absender ihn dort liest, wo er gefragt hat.
 */
final readonly class DecideAChange
{
    public function __construct(
        private ChangeableRecords $records,
        private Converse $converse,
        private ProposalRepository $proposals,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Uebernehmen — oder die Einwaende, wenn etwas dagegen spricht.
     *
     * @return array<string, string> leer heisst: uebernommen
     */
    public function accept(Proposal $proposal, AuthenticatedUser $staff, string $note): array
    {
        $record = $this->records->of($proposal->recordKind());

        if (null === $record) {
            return ['' => 'change.error.gone'];
        }

        // Die Einwaende entstehen in der Transaktion und werden hier gebraucht,
        // nachdem sie zurueckgenommen wurde — darum als Verweis nach draussen
        // und nicht als Rueckgabewert.
        $objections = [];

        $done = $this->proposals->atomically(
            function () use ($proposal, $record, $staff, $note, &$objections): bool {
                $objections = $this->take($proposal, $record, $staff, $note);

                return [] === $objections;
            },
        );

        return $done ? [] : $objections;
    }

    /**
     * Ablehnen — false heisst: jemand anders war schneller.
     */
    public function reject(Proposal $proposal, AuthenticatedUser $staff, string $reason): bool
    {
        return $this->proposals->atomically(function () use ($proposal, $staff, $reason): bool {
            if (!$this->claimed($proposal, ProposalDecision::Rejected, $staff)) {
                return false;
            }

            $this->converse->answer($proposal->enquiry(), $staff, $reason);

            return true;
        });
    }

    /**
     * Was heute dasteht — Feldschluessel auf Wert.
     *
     * Gebraucht fuer die dritte Spalte: hat jemand im Verwalterbereich
     * dasselbe Feld angefasst, waehrend der Vorschlag lag, soll das
     * dastehen, bevor es ueberschrieben wird. Stillschweigend zu
     * ueberschreiben waere der Fehler, den niemand bemerkt, bis eine Rechnung
     * an die falsche Adresse geht.
     *
     * @return array<string, string>
     */
    public function todaysValues(Proposal $proposal): array
    {
        $today = [];

        foreach ($this->records->of($proposal->recordKind())?->fieldsOf($proposal->recordId()) ?? [] as $field) {
            $today[$field->key] = $field->value;
        }

        return $today;
    }

    /**
     * Die Felder, die inzwischen anders dastehen als beim Vorschlagen.
     *
     * @return array<string, string>
     */
    public function changedMeanwhile(Proposal $proposal): array
    {
        $today = $this->todaysValues($proposal);
        $drifted = [];

        foreach ($proposal->fields() as $field) {
            $now = $today[$field->key()] ?? null;

            if (null !== $now && trim($now) !== trim($field->was())) {
                $drifted[$field->key()] = $now;
            }
        }

        return $drifted;
    }

    /**
     * Der Vorgang innerhalb der Transaktion.
     *
     * Die Reihenfolge ist die halbe Zusicherung: erst den Vorschlag fuer sich
     * beanspruchen, dann fragen, dann schreiben, dann nachsehen, dann Bescheid
     * geben.
     *
     * @return array<string, string> leer heisst: uebernommen
     */
    private function take(
        Proposal $proposal,
        RecordThatMayChange $record,
        AuthenticatedUser $staff,
        string $note,
    ): array {
        if (!$this->claimed($proposal, ProposalDecision::Accepted, $staff)) {
            return ['' => 'change.error.decided'];
        }

        $objections = $record->objectionsTo($proposal->recordId(), $proposal->wanted());

        if ([] !== $objections) {
            return $objections;
        }

        $record->apply($proposal->recordId(), $proposal->wanted());

        if (!self::stillThere($record, $proposal)) {
            return ['' => 'change.error.not_applied'];
        }

        $this->converse->answer($proposal->enquiry(), $staff, $note);

        return [];
    }

    /** Den Vorschlag fuer sich beanspruchen — oder feststellen, dass jemand anders schneller war. */
    private function claimed(Proposal $proposal, ProposalDecision $decision, AuthenticatedUser $staff): bool
    {
        return $this->proposals->decideOnce($proposal, $decision, $staff->id, $this->clock->now());
    }

    /**
     * Gibt es den Datensatz nach dem Uebernehmen noch?
     *
     * `apply()` darf still zurueckkehren, wenn es ihn nicht mehr gibt — und
     * ohne diese Nachschau stuende der Vorschlag als uebernommen da, ohne
     * dass sich etwas geaendert haette. Die Transaktion nimmt ihn dann wieder
     * mit.
     *
     * Verglichen werden ausdruecklich **nicht** die Werte: ein Modul darf
     * beim Uebernehmen glaetten — aus „3,5" wird „3.50", aus einer Zeile mit
     * Leerzeichen eine ohne —, und ein Vergleich auf Gleichheit erklaerte
     * jede solche Uebernahme fuer misslungen.
     */
    private static function stillThere(RecordThatMayChange $record, Proposal $proposal): bool
    {
        return [] !== $record->fieldsOf($proposal->recordId());
    }
}
