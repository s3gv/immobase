<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Pdf;

use App\Module\Billing\Domain\AssetItem;
use App\Module\Billing\Domain\AssetKind;
use App\Module\Billing\Domain\ReportedAssets;
use App\Module\Billing\Domain\ReportedClaim;
use App\Module\Billing\Domain\ReportedDebt;
use App\Module\Billing\Domain\ReportedReserve;
use App\Shared\Money\Money;
use App\Shared\Pdf\Amounts;
use App\Shared\Pdf\Sheet;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Die beiden Pflichtbausteine des Vermoegensberichts auf einem Blatt.
 *
 * Erst der Stand der Erhaltungsruecklage mit seiner Entwicklung, dann die
 * Aufstellung des uebrigen Vermoegens — in der Reihenfolge, in der § 28 Abs. 4
 * WEG sie nennt.
 *
 * **Die Ruecklage steht oben und wird unten nicht addiert.** Ihr Geld liegt auf
 * einem der Konten; wer sie noch einmal dazurechnete, zaehlte dasselbe Geld
 * zweimal. Was stattdessen danebensteht, ist die Deckung.
 */
final readonly class AssetReportTable
{
    public function __construct(
        private TranslatorInterface $translator,
        private Amounts $amounts,
    ) {
    }

    public function of(Sheet $sheet, ReportedAssets $body, float $at): float
    {
        $at = $this->reserve($sheet, $body->reserve, $at);
        $at = $this->items($sheet, 'billing.report.pdf.bank', $body->of(AssetKind::Bank), $at + 4.0);
        $at = $this->claims($sheet, $body->claims, $at);
        $at = $this->items($sheet, 'billing.report.pdf.liabilities', $body->of(AssetKind::Liability), $at);
        $at = $this->debts($sheet, $body->debts, $at);
        $at = $this->items($sheet, 'billing.report.pdf.holdings', $body->of(AssetKind::Holding), $at);

        return $this->sum($sheet, $at + 2.0, 'billing.report.pdf.total', $body->total()) + 4.0;
    }

    /** Anfangsbestand, was dazukam, was abging — und was am Stichtag dastand. */
    private function reserve(Sheet $sheet, ReportedReserve $reserve, float $at): float
    {
        $at = $this->heading($sheet, 'billing.report.pdf.reserve', $at);

        $at = $this->row($sheet, $at, $this->translator->trans('billing.report.reserve.opening'), $reserve->opening());
        $at = $this->row($sheet, $at, $this->translator->trans('billing.report.reserve.contributions'), $reserve->contributions());
        $at = $this->row($sheet, $at, $this->translator->trans('billing.report.reserve.levies'), $reserve->specialLevies());
        $at = $this->row($sheet, $at, $this->translator->trans('billing.report.reserve.interest'), $reserve->interest());
        $at = $this->row($sheet, $at, $this->translator->trans('billing.report.reserve.withdrawals'), $reserve->withdrawals());

        return $this->sum($sheet, $at, 'billing.report.reserve.closing', $reserve->closing());
    }

    /**
     * Ein Block erfasster Positionen.
     *
     * Ein leerer Block faellt weg: eine Ueberschrift ohne Zeilen sagt nichts,
     * und „keine Verbindlichkeiten" steht in der Summe.
     *
     * @param list<AssetItem> $items
     */
    private function items(Sheet $sheet, string $heading, array $items, float $at): float
    {
        if ([] === $items) {
            return $at;
        }

        $at = $this->heading($sheet, $heading, $at);

        foreach ($items as $item) {
            $at = $this->row($sheet, $at, $item->label(), $item->amount(), $item->note());
        }

        return $at + 2.0;
    }

    /**
     * Die offenen Hausgelder — mit Nummer, ohne Namen.
     *
     * @param list<ReportedClaim> $claims
     */
    private function claims(Sheet $sheet, array $claims, float $at): float
    {
        if ([] === $claims) {
            return $at;
        }

        $at = $this->heading($sheet, 'billing.report.pdf.claims', $at);

        foreach ($claims as $claim) {
            $at = $this->row(
                $sheet,
                $at,
                $this->translator->trans('billing.report.pdf.unit', ['%number%' => $claim->unitNumber]),
                $claim->amount,
                $this->translator->trans('billing.report.pdf.since', ['%year%' => $claim->since]),
            );
        }

        return $at + 2.0;
    }

    /**
     * Die Darlehen mit ihrer Restschuld am Stichtag.
     *
     * Ein eigener Block neben den erfassten Verbindlichkeiten: die eine Zahl
     * kommt aus dem Tilgungsplan, die andere hat jemand eingetragen, und wer
     * das mischt, sucht spaeter die Quelle einer Zahl.
     *
     * @param list<ReportedDebt> $debts
     */
    private function debts(Sheet $sheet, array $debts, float $at): float
    {
        if ([] === $debts) {
            return $at;
        }

        $at = $this->heading($sheet, 'billing.report.pdf.loans', $at);

        foreach ($debts as $debt) {
            $at = $this->row($sheet, $at, $debt->label, $debt->outstanding);
        }

        return $at + 2.0;
    }

    private function heading(Sheet $sheet, string $key, float $at): float
    {
        return $sheet->heading($at, $this->translator->trans($key));
    }

    /**
     * Eine Zeile: Bezeichnung links, Betrag rechts.
     *
     * Ohne Betrag steht rechts ein Wort und keine Null — ein Gegenstand, den
     * niemand bewertet hat, ist nicht nichts wert.
     */
    private function row(Sheet $sheet, float $at, string $label, ?Money $amount, string $note = ''): float
    {
        $sheet->put(Sheet::LEFT, $at, $label, 9.0);
        $sheet->putRight(210.0 - Sheet::RIGHT, $at, null === $amount
            ? $this->translator->trans('billing.report.pdf.unvalued')
            : $this->amounts->money($amount), 9.0);

        if ('' === $note) {
            return $at + 5.0;
        }

        return $sheet->putLines(Sheet::LEFT + 2.0, $at + 4.0, 120.0, $note, 7.5) + 0.5;
    }

    private function sum(Sheet $sheet, float $at, string $label, Money $amount): float
    {
        $sheet->rule($at);
        $sheet->put(Sheet::LEFT, $at + 1.0, $this->translator->trans($label), 9.0, 'B');
        $sheet->putRight(210.0 - Sheet::RIGHT, $at + 1.0, $this->amounts->money($amount), 9.0, 'B');

        return $at + 7.0;
    }
}
