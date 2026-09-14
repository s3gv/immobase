<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Pdf;

use App\Module\Billing\Domain\StatementDocument;
use App\Shared\Pdf\Amounts;
use App\Shared\Pdf\Sheet;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Die Zeilen, die eine Abrechnung mit Umsatzsteuer zur Rechnung machen.
 *
 * Die Steuer auf die Nettokosten, die Bruttosumme, die Steuer in den
 * Vorauszahlungen (§ 14 Abs. 5 UStG) und die Zahlungsfrist. Eigens, weil
 * sie nur auf einem Teil der Schreiben stehen — und das Schreiben ohne sie
 * genau so bleiben soll, wie es war.
 */
final readonly class StatementTaxRows
{
    public function __construct(
        private TranslatorInterface $translator,
        private Amounts $amounts,
    ) {
    }

    /**
     * Wer die Rechnung stellt — § 14 Abs. 4 Nr. 1 und 2 UStG.
     *
     * Der Eigentuemer, nicht die Verwaltung im Briefkopf: mit Anschrift in
     * einer Zeile und seiner Steuernummer.
     */
    public function seller(Sheet $sheet, StatementDocument $document, float $at): float
    {
        $seller = $document->letting()->seller();
        $sheet->put(Sheet::LEFT, $at, $this->translator->trans('billing.pdf.seller', [
            '%name%' => $seller->name(),
            '%address%' => str_replace("\n", ', ', $seller->address()),
        ]), 8.0);
        $sheet->put(Sheet::LEFT, $at + 4.0, $this->translator->trans('billing.pdf.tax_number', [
            '%number%' => $seller->taxNumber(),
        ]), 8.0);

        return $at + 10.0;
    }

    /** Die Steuer auf die Nettosumme und was brutto daraus wird. */
    public function onCosts(Sheet $sheet, StatementDocument $document, float $at): float
    {
        $at = $sheet->room($at, 10.0);
        $sheet->put(Sheet::LEFT, $at, $this->translator->trans('billing.line.tax', ['%rate%' => $this->percent($document)]), 9.0);
        $sheet->putRight(210.0 - Sheet::RIGHT, $at, $this->amounts->money($document->outcome()->tax()), 9.0);
        $sheet->put(Sheet::LEFT, $at + 4.5, $this->translator->trans('billing.line.costs_gross'), 9.0, 'B');
        $sheet->putRight(210.0 - Sheet::RIGHT, $at + 4.5, $this->amounts->money($document->outcome()->gross()), 9.0, 'B');

        return $at + 11.0;
    }

    /** Die Steuer, die in den gezahlten Vorauszahlungen steckt. */
    public function inAdvances(Sheet $sheet, StatementDocument $document, float $at): float
    {
        $sheet->put(Sheet::LEFT + 2.0, $at, $this->translator->trans('billing.advance.contained_tax', [
            '%rate%' => $this->percent($document),
        ]), 8.0);
        $sheet->putRight(210.0 - Sheet::RIGHT, $at, $this->amounts->money($document->outcome()->advancesTax()), 8.0);

        return $at + 11.5;
    }

    /** Eine Rechnung sagt, bis wann gezahlt wird — ein Guthaben braucht das nicht. */
    public function payable(Sheet $sheet, StatementDocument $document, float $at): void
    {
        $balance = $document->balance();

        if (!$balance->isNegative() && !$balance->isZero()) {
            $sheet->put(Sheet::LEFT, $at, $this->translator->trans('billing.result.payable'), 9.0);
        }
    }

    /** 1900 wird zu „19,00 %" — wie auf der Dauermietrechnung. */
    private function percent(StatementDocument $document): string
    {
        $bps = $document->letting()->taxation()->rateBps();

        return $this->amounts->number(\sprintf('%d.%02d', intdiv($bps, 100), $bps % 100)).' %';
    }
}
