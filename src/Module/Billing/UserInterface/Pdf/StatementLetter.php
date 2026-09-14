<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Pdf;

use App\Module\Billing\Domain\Distribution;
use App\Module\Billing\Domain\ReportedReserve;
use App\Module\Billing\Domain\Statement;
use App\Module\Billing\Domain\StatementDocument;
use App\Module\Billing\Domain\StatementIsNotReleased;
use App\Module\Billing\Domain\StatementKind;
use App\Shared\Money\Money;
use App\Shared\Pdf\Amounts;
use App\Shared\Pdf\LetterHead;
use App\Shared\Pdf\Sheet;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Eine Abrechnung als Brief.
 *
 * Was daraufsteht, verlangt die Rechtsprechung: die Zusammenstellung der
 * Gesamtkosten, die Angabe **und Erlaeuterung** der Verteilerschluessel, die
 * Berechnung des Anteils und der Abzug der geleisteten Vorauszahlungen.
 * Massstab ist, ob ein durchschnittlicher Mieter seinen Anteil nachrechnen
 * kann — deshalb steht der Rechenweg da und nicht nur das Ergebnis.
 *
 * Kopf, Anschriftfeld und Informationsblock setzt {@see LetterHead}: sie sind
 * bei jedem Schreiben dieses Moduls dieselben.
 *
 * **Mit Umsatzsteuer vermietet** stehen die Anteile netto da, darunter die
 * Steuer und die Bruttosumme; bei den Vorauszahlungen die Steuer, die in
 * ihnen steckt (§ 14 Abs. 5 UStG). Ohne Umsatzsteuer bleibt das Blatt, wie es
 * war — Byte fuer Byte.
 */
final readonly class StatementLetter
{
    public function __construct(
        private LetterHead $letterhead,
        private TranslatorInterface $translator,
        private Amounts $amounts,
        private Shares $shares,
        private StatementTaxRows $taxRows,
    ) {
    }

    public function of(Statement $statement, StatementDocument $document): string
    {
        $reference = $document->reference();
        $released = $document->releasedOn() ?? throw new StatementIsNotReleased();
        $sheet = new Sheet($released);
        $sheet->AddPage();

        $this->letterhead->head($sheet);
        $this->letterhead->address(
            $sheet,
            $document->recipient()->label(),
            $document->recipient()->address(),
        );
        $this->letterhead->info($sheet, $reference->toString(), $released->format('d.m.Y'));
        $sheet->continueWith($reference->toString());

        $at = $this->subject($sheet, $document);
        $at = $document->letting()->isTaxed() ? $this->taxRows->seller($sheet, $document, $at - 4.0) : $at;
        $at = $this->costs($sheet, $document, $at);
        $at = $this->advances($sheet, $document, $at);
        $at = $this->reserve($sheet, $statement, $document, $at);
        $this->result($sheet, $document, $at);

        return $sheet->bytes();
    }

    private function subject(Sheet $sheet, StatementDocument $document): float
    {
        $at = 103.0;
        $sheet->put(Sheet::LEFT, $at, $this->translator->trans($document->kind()->labelKey()), 12.0, 'B');
        $sheet->put(Sheet::LEFT, $at + 7.0, \sprintf(
            '%s · %s – %s',
            $document->unitLabel(),
            $document->period()->from()->format('d.m.Y'),
            $document->period()->to()->format('d.m.Y'),
        ), 9.0);

        return $at + 16.0;
    }

    private function costs(Sheet $sheet, StatementDocument $document, float $at): float
    {
        $at = $sheet->heading($at, $this->translator->trans('billing.pdf.costs'));

        $taxed = $document->letting()->isTaxed();

        foreach ($document->lines() as $line) {
            $at = $sheet->room($at, 14.0);
            $sheet->put(Sheet::LEFT, $at, $line->costKind(), 9.0);
            $sheet->putRight(210.0 - Sheet::RIGHT, $at, $this->amount($taxed ? $line->net() : $line->amount()), 9.0);
            $at = $sheet->putLines(
                Sheet::LEFT + 2.0,
                $at + 4.5,
                120.0,
                $this->explain($line->distribution(), $taxed ? $line->totalNet() : $line->total()),
                7.5,
            ) + 1.0;
        }

        $at = $sheet->room($at, 10.0);
        $sheet->rule($at);
        $sheet->put(Sheet::LEFT, $at + 1.0, $this->translator->trans($taxed ? 'billing.line.costs_net' : 'billing.line.costs'), 9.0, 'B');
        $sheet->putRight(210.0 - Sheet::RIGHT, $at + 1.0, $this->amount($document->outcome()->costs()), 9.0, 'B');

        return $taxed ? $this->taxRows->onCosts($sheet, $document, $at + 6.0) : $at + 12.0;
    }

    private function advances(Sheet $sheet, StatementDocument $document, float $at): float
    {
        $at = $sheet->room($at, 18.0);
        $at = $sheet->heading($at, $this->translator->trans('billing.advance.heading'));

        // Der Tag allein genuegt nicht mehr: seit der Sonderumlage stehen zwei
        // Zahlungen am selben Tag untereinander, und ohne ihre Art waeren es
        // zwei Betraege ohne Grund.
        foreach ($document->payments() as $payment) {
            $at = $sheet->room($at, 4.5);
            $sheet->put(Sheet::LEFT, $at, $payment->dueOn()->format('d.m.Y'), 9.0);
            $sheet->put(Sheet::LEFT + 24.0, $at, $this->translator->trans($payment->kindKey()), 9.0);
            $sheet->putRight(210.0 - Sheet::RIGHT, $at, $this->amount($payment->received()), 9.0);
            $at += 4.5;
        }

        $at = $sheet->room($at, 10.0);
        $sheet->rule($at + 1.0);
        $sheet->put(Sheet::LEFT, $at + 2.0, $this->translator->trans('billing.advance.received'), 9.0, 'B');
        $sheet->putRight(210.0 - Sheet::RIGHT, $at + 2.0, $this->amount($document->outcome()->advances()), 9.0, 'B');

        return $document->letting()->isTaxed() ? $this->taxRows->inAdvances($sheet, $document, $at + 6.5) : $at + 14.0;
    }

    /**
     * Die Entwicklung der Erhaltungsruecklage im abgerechneten Jahr.
     *
     * Nur auf der Hausgeldabrechnung: die Ruecklage gehoert der Gemeinschaft
     * und geht den Mieter nichts an. Und nur, wenn sich etwas bewegt hat —
     * sechs Nullen sind keine Auskunft.
     */
    private function reserve(Sheet $sheet, Statement $statement, StatementDocument $document, float $at): float
    {
        $reserve = $statement->reserve();

        if (StatementKind::HouseMoney !== $document->kind() || $reserve->stoodStill()) {
            return $at;
        }

        $at = $sheet->room($at, 40.0);
        $sheet->put(Sheet::LEFT, $at, $this->translator->trans('billing.reserve.heading'), 10.0, 'B');
        $at += 7.0;

        foreach (self::reserveRows($reserve) as $key => $amount) {
            $sheet->put(Sheet::LEFT, $at, $this->translator->trans($key), 9.0);
            $sheet->putRight(210.0 - Sheet::RIGHT, $at, $this->amount($amount), 9.0);
            $at += 4.5;
        }

        return $at + 6.0;
    }

    /**
     * @return array<string, Money>
     */
    private static function reserveRows(ReportedReserve $reserve): array
    {
        return [
            'billing.reserve.opening' => $reserve->opening(),
            'billing.reserve.contributions' => $reserve->contributions(),
            'billing.reserve.levies' => $reserve->specialLevies(),
            'billing.reserve.interest' => $reserve->interest(),
            'billing.reserve.withdrawals' => $reserve->withdrawals(),
            'billing.reserve.closing' => $reserve->closing(),
        ];
    }

    /** Das Ergebnis bleibt beisammen — eine Zahl ohne ihre Beschriftung ist keine. */
    private function result(Sheet $sheet, StatementDocument $document, float $at): void
    {
        $settled = $document->outcome()->settled();
        $at = $sheet->room($at, null === $settled ? 8.0 : 14.0);

        if (null !== $settled) {
            $sheet->put(Sheet::LEFT, $at, $this->translator->trans('billing.result.settled'), 9.0);
            $sheet->putRight(210.0 - Sheet::RIGHT, $at, $this->amount($settled), 9.0);
            $at += 6.0;
        }

        $balance = $document->balance();
        $label = $balance->isNegative() ? 'billing.result.credit' : 'billing.result.due';

        $sheet->put(Sheet::LEFT, $at, $this->translator->trans($label), 11.0, 'B');
        $sheet->putRight(210.0 - Sheet::RIGHT, $at, $this->amount($balance), 11.0, 'B');

        // Mit Umsatzsteuer ist das Blatt eine Rechnung.
        if ($document->letting()->isTaxed()) {
            $this->taxRows->payable($sheet, $document, $at + 6.0);
        }
    }

    private function explain(Distribution $distribution, Money $total): string
    {
        return \sprintf(
            '%s %s — %s',
            $this->translator->trans('billing.pdf.of'),
            $this->amount($total),
            $this->shares->of($distribution),
        );
    }

    private function amount(Money $money): string
    {
        return $this->amounts->money($money);
    }
}
