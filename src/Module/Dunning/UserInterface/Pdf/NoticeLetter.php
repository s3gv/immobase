<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\UserInterface\Pdf;

use App\Module\Dunning\Domain\DunningLevel;
use App\Module\Dunning\Domain\Notice;
use App\Shared\Pdf\LetterHead;
use App\Shared\Pdf\PostalLines;
use App\Shared\Pdf\Sheet;
use DateTimeImmutable;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Das Mahnschreiben als Brief.
 *
 * **Der Glaeubiger steht ausdruecklich darauf.** Der Briefkopf traegt die
 * Verwaltung, weil der Brief von dort kommt — gefordert wird aber im Namen
 * der Gemeinschaft oder des Vermieters, und ein Schuldner, der das nicht
 * liest, zahlt an den Falschen.
 *
 * Die Zahlungserinnerung ist im Ton eine andere als die letzte Mahnung: sie
 * raeumt ein, dass sich die Zahlung gekreuzt haben kann. Das ist keine
 * Hoeflichkeit, sondern Genauigkeit — eine Buchung braucht zwei Tage, und
 * wer in dieser Zeit gemahnt wird, hat nichts falsch gemacht.
 *
 * Dieselbe Maschinerie wie bei den uebrigen Schreiben: FPDF, DIN 5008 Form B.
 */
final readonly class NoticeLetter
{
    public function __construct(
        private LetterHead $letterhead,
        private TranslatorInterface $translator,
        private NoticeTable $table,
    ) {
    }

    public function of(Notice $notice, string $iban, DateTimeImmutable $on): string
    {
        $sheet = new Sheet($on);
        $sheet->AddPage();

        $recipients = $notice->recipients();
        $this->letterhead->head($sheet);
        $this->letterhead->address($sheet, $recipients->debtorName(), $recipients->debtorAddress());
        $this->letterhead->info($sheet, $notice->reference(), $on->format('d.m.Y'));

        $at = $this->subject($sheet, $notice);
        $at = $this->table->of($sheet, $notice, $at + 4.0);
        $this->notes($sheet, $notice, $iban, $at);

        return $sheet->bytes();
    }

    private function subject(Sheet $sheet, Notice $notice): float
    {
        $at = 103.0;
        $recipients = $notice->recipients();

        $sheet->put(Sheet::LEFT, $at, $this->translator->trans(self::subjectKey($notice->level())), 12.0, 'B');
        // Der Glaeubiger steht hier **im Text** und nicht im Anschriftfeld —
        // seine Anschrift darum in einer Zeile.
        $at = $sheet->putLines(Sheet::LEFT, $at + 7.0, 160.0, $this->translator->trans('dunning.pdf.creditor_line', [
            '%name%' => $recipients->creditorName(),
            '%address%' => PostalLines::fromText($recipients->creditorAddress())->inline(),
        ]), 8.0);

        // Neutral und nicht aus dem Namen geraten: aus „Tobias Wagner"
        // folgt keine Anrede, und eine falsche steht auf einem Schreiben,
        // das jemand aufhebt.
        $sheet->put(Sheet::LEFT, $at + 6.0, $this->translator->trans('dunning.pdf.salutation'), 9.0);

        return $sheet->putLines(
            Sheet::LEFT,
            $at + 13.0,
            160.0,
            $this->translator->trans(self::introKey($notice->level())),
            9.0,
        ) + 2.0;
    }

    private function notes(Sheet $sheet, Notice $notice, string $iban, float $at): void
    {
        $at = $sheet->putLines(Sheet::LEFT, $at, 160.0, $this->translator->trans('dunning.pdf.pay_by', [
            '%date%' => $notice->payBy()->format('d.m.Y'),
            '%iban%' => $iban,
        ]), 9.0) + 3.0;

        $at = $sheet->putLines(Sheet::LEFT, $at, 160.0, $this->translator->trans('dunning.pdf.interest_note'), 8.0) + 2.0;

        if ($notice->level()->isLast()) {
            $at = $sheet->putLines(Sheet::LEFT, $at, 160.0, $this->translator->trans('dunning.pdf.court_note'), 8.0) + 2.0;
        }

        if ('' !== $notice->note()) {
            $sheet->putLines(Sheet::LEFT, $at + 2.0, 160.0, $notice->note(), 9.0);
        }
    }

    private static function subjectKey(DunningLevel $level): string
    {
        return 'dunning.pdf.subject_'.$level->value;
    }

    private static function introKey(DunningLevel $level): string
    {
        return 'dunning.pdf.intro_'.$level->value;
    }
}
