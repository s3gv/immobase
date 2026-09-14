<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\RentInvoice;
use App\Module\Billing\Domain\RentInvoiceIsIncomplete;
use App\Module\Billing\Domain\RentInvoiceIsIssued;
use App\Module\Billing\Domain\RentInvoiceIsOutOfOrder;
use App\Module\Billing\Domain\RentInvoiceRepository;
use DateTimeImmutable;

/**
 * Die Rechnung geht hinaus — und wird dabei eingefroren.
 *
 * **Das Einfrieren ist der ganze Punkt.** „Ausgestellt am 30. Juni" ist eine
 * Auskunft darueber, *was* an diesem Tag vorlag. Rechnete das Blatt beim
 * Herunterladen jedes Mal neu, koennte jede spaetere Aenderung es veraendern
 * — eine Mieterhoehung, ein Umzug, eine berichtigte Anschrift. Der Mieter
 * haelt dann ein Papier in der Hand, das mit dem, was wir zeigen, nicht mehr
 * uebereinstimmt, und beide ziehen daraus Vorsteuer.
 *
 * **Und die Vorgaengerin wird geschlossen.** Eine Folgefassung gilt ab ihrem
 * Tag, also endet die vorige am Vortag. Damit gehoert jeder Tag genau einer
 * Fassung, und der Leistungszeitraum auf beiden Blaettern stimmt.
 *
 * Beides zusammen haelt aber nur, wenn die Reihenfolge stimmt und beide
 * Aenderungen zusammen gespeichert werden. Darum die Pruefung vorweg und die
 * Transaktion darum herum — {@see RentInvoiceIsOutOfOrder} sagt, was an einer
 * Reihenfolge schiefgehen kann.
 */
final readonly class IssueRentInvoice
{
    public function __construct(
        private RentInvoiceRepository $invoices,
        private ComposeRentInvoice $compose,
    ) {
    }

    /**
     * @throws RentInvoiceIsIssued
     * @throws RentInvoiceIsIncomplete
     * @throws RentInvoiceIsOutOfOrder
     */
    public function issue(RentInvoice $invoice, DateTimeImmutable $on): void
    {
        if (!$invoice->release()->isDraft()) {
            throw RentInvoiceIsIssued::already();
        }

        $proposal = $this->compose->of($invoice);

        if ([] !== RentInvoiceGaps::of($invoice, $proposal, $this->compose->tenancyOf($invoice))) {
            throw RentInvoiceIsIncomplete::somethingIsMissing();
        }

        $previous = $this->invoices->lastIssuedFor($invoice->tenancyId());
        self::mustFollow($invoice, $previous);

        $this->invoices->atomically(function () use ($invoice, $previous, $on, $proposal): void {
            $this->closeThePrevious($invoice, $previous);
            $invoice->issueOn($on, $proposal);
            $this->invoices->save($invoice);
        });
    }

    /**
     * Diese Fassung muss unmittelbar auf die zuletzt ausgestellte folgen.
     *
     * Ohne die Pruefung liesse sich die dritte Fassung vor der zweiten
     * ausstellen. Die zweite schloesse danach nichts mehr — die dritte ist
     * schon die juengste —, und beide blieben offen. Dasselbe passiert einer
     * rueckdatierten Fassung: sie schloesse die vorige auf einen Tag, an dem
     * sie selbst laengst gilt.
     *
     * Eine Berichtigung zaehlt anders. Sie tritt an die Stelle der Fassung,
     * die sie berichtigt, und traegt deren Nummer und deren Zeitraum — aber
     * nur, solange jene noch die zuletzt ausgestellte ist.
     * {@see ReviseRentInvoice::correct()} hat das beim Anlegen geprueft, und
     * der Entwurf kann danach lange liegen: geht dazwischen eine
     * Folgefassung hinaus, waere die Berichtigung wieder ab dem alten Tag
     * offen und laege ueber der neuen. Darum hier noch einmal, und diesmal
     * am Ende der Kette.
     *
     * @throws RentInvoiceIsOutOfOrder
     */
    private static function mustFollow(RentInvoice $invoice, ?RentInvoice $previous): void
    {
        if (null === $previous) {
            return;
        }

        if ($invoice->edition()->isCorrection()) {
            if ($invoice->edition()->correctsId() !== $previous->id()) {
                throw RentInvoiceIsOutOfOrder::theCorrectedVersionIsOutdated();
            }

            return;
        }

        if ($invoice->edition()->number() !== $previous->edition()->number() + 1) {
            throw RentInvoiceIsOutOfOrder::aVersionIsMissing();
        }

        if ($invoice->validity()->from() <= $previous->validity()->from()) {
            throw RentInvoiceIsOutOfOrder::itDoesNotBeginLater();
        }
    }

    /**
     * Die zuletzt ausgestellte Fassung endet am Tag vor dieser.
     *
     * Wer die Vorgaengerin ist, wird **abgeleitet**: die ausgestellte Fassung
     * desselben Vertrags mit der hoechsten Nummer. Eine Berichtigung ist
     * keine Folgefassung — sie traegt dieselbe Nummer und schliesst darum
     * nichts.
     */
    private function closeThePrevious(RentInvoice $invoice, ?RentInvoice $previous): void
    {
        if (null === $previous || $invoice->edition()->isCorrection()) {
            return;
        }

        $previous->endsOn($invoice->validity()->from()->modify('-1 day'));
        $this->invoices->save($previous);
    }
}
