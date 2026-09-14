<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\RentInvoice;
use App\Module\Billing\Domain\RentInvoiceCannotBeCorrected;
use App\Module\Billing\Domain\RentInvoiceRepository;
use App\Module\Tenancy\Contract\TenancyDirectory;
use DateTimeImmutable;

/**
 * Die beiden Wege zu einem zweiten Schreiben — und warum es zwei sind.
 *
 * * **Berichtigen**: an der Rechnung war etwas falsch. Die neue Fassung
 *   traegt denselben Zeitraum und dieselbe Fassungsnummer, eine Iteration
 *   weiter. Umsatzsteuerlich wirkt sie zurueck.
 * * **Neu ausstellen**: an der Miete hat sich etwas geaendert. Die alte
 *   Rechnung war richtig und bleibt es bis zum Vortag; die neue traegt die
 *   naechste Fassungsnummer und gilt ab ihrem Tag.
 *
 * Ein Wort fuer beides verwischte den Unterschied an der Stelle mit dem
 * groessten Schaden — der eine Weg aendert Vergangenes, der andere nicht.
 */
final readonly class ReviseRentInvoice
{
    public function __construct(
        private RentInvoiceRepository $invoices,
        private TenancyDirectory $tenancies,
    ) {
    }

    /**
     * Eine berichtigte Fassung — dieselbe Geltung, eigene Nummer.
     *
     * Berichtigt wird nur die juengste ausgestellte Fassung: wer eine
     * ueberholte berichtigte, schriebe eine Rechnung fort, die laengst
     * abgeloest ist.
     *
     * Und wie bei der Folgefassung gibt es hoechstens einen Entwurf dazu.
     * Ein zweiter traegt dieselbe Fassungs- und Iterationsnummer und liefe
     * in den eindeutigen Index der Datenbank.
     *
     * @throws RentInvoiceCannotBeCorrected
     */
    public function correct(RentInvoice $invoice): RentInvoice
    {
        if ($invoice->release()->isDraft()) {
            throw RentInvoiceCannotBeCorrected::itIsADraft();
        }

        if ($this->invoices->lastIssuedFor($invoice->tenancyId())?->id() !== $invoice->id()) {
            throw RentInvoiceCannotBeCorrected::itIsOutdated();
        }

        $open = $this->openCorrectionOf($invoice);

        if (null !== $open) {
            return $open;
        }

        $correction = new RentInvoice(
            $invoice->edition()->number(),
            $invoice->tenancyId(),
            $invoice->tenancyNumber(),
            $invoice->propertyId(),
            $invoice->validity()->from(),
        );
        $correction->corrects($invoice);
        $correction->describe($invoice->label());
        $this->invoices->save($correction);

        return $correction;
    }

    /**
     * Eine Folgefassung — ab dem Tag, an dem sich etwas aendert.
     *
     * Vorbelegt mit dem Beginn der naechsten Mietstufe: das ist der Grund,
     * aus dem sie fast immer entsteht. Wer einen anderen Tag will, traegt
     * ihn im ersten Schritt ein.
     *
     * **Gibt es schon einen offenen Entwurf, kommt der zurueck.** Zwei
     * Entwuerfe fuer dieselbe Fortsetzung waeren zwei Rechnungen ueber
     * denselben Zeitraum, sobald beide hinausgehen — und der Tag bleibt
     * dabei, wie er ist: wer ihn geaendert hat, hat das nicht ohne Grund
     * getan.
     */
    public function succeed(RentInvoice $invoice, DateTimeImmutable $from): RentInvoice
    {
        $open = $this->openSuccessorFor($invoice->tenancyId());

        if (null !== $open) {
            return $open;
        }

        $next = new RentInvoice(
            $this->invoices->nextNumberFor($invoice->tenancyId()),
            $invoice->tenancyId(),
            $invoice->tenancyNumber(),
            $invoice->propertyId(),
            $from,
        );
        $next->describe($invoice->label());
        $this->invoices->save($next);

        return $next;
    }

    /** Der Entwurf, der diese Fassung schon berichtigt. */
    public function openCorrectionOf(RentInvoice $invoice): ?RentInvoice
    {
        foreach ($this->invoices->forTenancy($invoice->tenancyId()) as $candidate) {
            if ($candidate->release()->isDraft() && $candidate->edition()->correctsId() === $invoice->id()) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Der Entwurf, der schon auf die letzte Fassung folgt.
     *
     * Eine Berichtigung zaehlt nicht dazu: sie tritt an die Stelle einer
     * ausgestellten Fassung und setzt die Kette nicht fort.
     */
    public function openSuccessorFor(string $tenancyId): ?RentInvoice
    {
        foreach ($this->invoices->forTenancy($tenancyId) as $candidate) {
            if ($candidate->release()->isDraft() && !$candidate->edition()->isCorrection()) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Der Tag, ab dem die naechste Fassung gelten sollte.
     *
     * Die erste Mietstufe nach dem Beginn der vorigen Fassung — und wenn es
     * keine gibt, der Tag nach deren Beginn. Ein Vorschlag und keine Regel:
     * im ersten Schritt steht er zur Aenderung da.
     */
    public function nextChangeAfter(RentInvoice $invoice): DateTimeImmutable
    {
        $from = $invoice->validity()->from();

        foreach ($this->tenancies->brief($invoice->tenancyId())->steps ?? [] as $step) {
            if ($step->from > $from) {
                return $step->from;
            }
        }

        return $from->modify('+1 day');
    }
}
