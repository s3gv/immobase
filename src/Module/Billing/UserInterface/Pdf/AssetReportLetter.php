<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Pdf;

use App\Module\Billing\Domain\AssetReport;
use App\Module\Billing\Domain\AssetReportDocument;
use App\Module\Billing\Domain\ReportedAssets;
use App\Shared\Pdf\Amounts;
use App\Shared\Pdf\LetterHead;
use App\Shared\Pdf\Sheet;
use DateTimeImmutable;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Der Vermoegensbericht als Brief.
 *
 * Was daraufsteht, verlangt § 28 Abs. 4 WEG: der Stand der Erhaltungsruecklage
 * und eine Aufstellung des wesentlichen Gemeinschaftsvermoegens, beides zum
 * Stichtag des abgelaufenen Wirtschaftsjahres.
 *
 * **Der Inhalt ist fuer alle derselbe.** Verteilt wird nichts — anders als bei
 * Abrechnung und Wirtschaftsplan aendert sich von Empfaenger zu Empfaenger nur
 * das Anschriftfeld. Darum nimmt das Blatt den Berichtskoerper einmal entgegen
 * und das Dokument nur fuer die Anschrift.
 *
 * Am Ende steht, was der Bericht **nicht** ist: kein Beschluss. Er wird zur
 * Kenntnis genommen, und wer eine Angabe fuer falsch haelt, kann Berichtigung
 * verlangen. Das ist keine Hoeflichkeit — es ist der Unterschied zwischen
 * einem Blatt, gegen das man vorgehen muss, und einem, das man lesen darf.
 *
 * Dieselbe Maschinerie wie bei den anderen Schreiben: FPDF, DIN 5008 Form B.
 * Kopf und Anschriftfeld setzt {@see LetterHead}.
 */
final readonly class AssetReportLetter
{
    public function __construct(
        private LetterHead $letterhead,
        private TranslatorInterface $translator,
        private Amounts $amounts,
        private AssetReportTable $table,
    ) {
    }

    public function of(
        AssetReport $report,
        AssetReportDocument $document,
        ReportedAssets $body,
        DateTimeImmutable $on,
    ): string {
        $sheet = new Sheet($on);
        $sheet->AddPage();

        $recipient = $document->recipient();
        $this->letterhead->head($sheet);
        $this->letterhead->address($sheet, $recipient->label(), $recipient->address());
        $this->letterhead->info($sheet, $document->reference()->toString(), $on->format('d.m.Y'));

        $at = $this->subject($sheet, $report, $document);
        $at = $this->table->of($sheet, $body, $at);
        $this->notes($sheet, $body, $at);

        return $sheet->bytes();
    }

    private function subject(Sheet $sheet, AssetReport $report, AssetReportDocument $document): float
    {
        $at = 103.0;
        $sheet->put(Sheet::LEFT, $at, $this->translator->trans('billing.report.pdf.subject', [
            '%year%' => $report->period()->year(),
        ]), 12.0, 'B');
        $sheet->put(Sheet::LEFT, $at + 7.0, \sprintf(
            '%s · %s',
            $document->unitLabel(),
            $this->translator->trans('billing.report.pdf.as_of', [
                '%day%' => $report->asOf()->format('d.m.Y'),
            ]),
        ), 9.0);

        return $at + 16.0;
    }

    /**
     * Was unter der Aufstellung steht.
     *
     * Zwei Saetze, und beide sagen etwas, das die Zahlen nicht sagen: dass auf
     * den zweckgebundenen Konten weniger liegt, als die Ruecklage ausweist —
     * und dass dieser Bericht nicht beschlossen wird.
     */
    private function notes(Sheet $sheet, ReportedAssets $body, float $at): void
    {
        $short = $body->missingFromTheReserve();

        if (null !== $short) {
            $at = $sheet->putLines(Sheet::LEFT, $at, 160.0, $this->translator->trans(
                'billing.report.pdf.not_covered',
                ['%amount%' => $this->amounts->money($short)],
            ), 8.0) + 3.0;
        }

        if (0 !== $body->unvalued()) {
            $at = $sheet->putLines(Sheet::LEFT, $at, 160.0, $this->translator->trans(
                'billing.report.pdf.unvalued_note',
                ['%count%' => $body->unvalued()],
            ), 8.0) + 3.0;
        }

        $sheet->putLines(Sheet::LEFT, $at, 160.0, $this->translator->trans('billing.report.pdf.no_resolution'), 8.0);
    }
}
