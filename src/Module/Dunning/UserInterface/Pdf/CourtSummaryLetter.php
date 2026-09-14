<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\UserInterface\Pdf;

use App\Module\Dunning\Domain\Claim;
use App\Module\Dunning\Domain\InterestSchedule;
use App\Module\Dunning\Domain\InterestSegment;
use App\Module\Dunning\Domain\Notice;
use App\Shared\Money\Money;
use App\Shared\Pdf\Amounts;
use App\Shared\Pdf\LetterHead;
use App\Shared\Pdf\PostalLines;
use App\Shared\Pdf\Sheet;
use DateTimeImmutable;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Die Zusammenfassung fuer das gerichtliche Mahnverfahren.
 *
 * **Kein Brief an den Schuldner**, sondern das Blatt fuer den Anwalt oder die
 * Akte. Es traegt die Felder, nach denen der Antrag auf Erlass eines
 * Mahnbescheids fragt: Glaeubiger und Schuldner mit Anschrift, je
 * Hauptforderung Betrag, Faelligkeit und Verzugsbeginn, die vollstaendige
 * Zinsstaffel, die Nebenforderungen einzeln und die Mahnhistorie.
 *
 * Kein Anschriftfeld und keine Anrede: dieses Blatt geht an niemanden.
 */
final readonly class CourtSummaryLetter
{
    public function __construct(
        private LetterHead $letterhead,
        private TranslatorInterface $translator,
        private Amounts $amounts,
    ) {
    }

    /**
     * @param array{
     *     creditor: array{name: string, address: string},
     *     debtor: array{name: string, address: string},
     *     claims: list<array{claim: Claim, interest: InterestSchedule}>,
     *     notices: list<Notice>,
     *     principal: Money,
     *     interest: Money,
     *     extras: Money,
     * } $summary
     */
    public function of(array $summary, DateTimeImmutable $on): string
    {
        $sheet = new Sheet($on);
        $sheet->AddPage();

        $this->letterhead->head($sheet);
        $sheet->put(Sheet::LEFT, 60.0, $this->translator->trans('dunning.pdf.court_heading'), 13.0, 'B');
        $at = $sheet->putLines(Sheet::LEFT, 68.0, 160.0, $this->translator->trans('dunning.pdf.court_intro'), 8.0) + 4.0;

        $at = $this->parties($sheet, $summary, $at);
        $at = $this->principal($sheet, $summary['claims'], $at + 4.0);
        $at = $this->interest($sheet, $summary['claims'], $at + 4.0);
        $at = $this->totals($sheet, $summary, $at + 2.0);
        $this->history($sheet, $summary['notices'], $at + 4.0);

        return $sheet->bytes();
    }

    /**
     * @param array{creditor: array{name: string, address: string}, debtor: array{name: string, address: string}, ...} $summary
     */
    private function parties(Sheet $sheet, array $summary, float $at): float
    {
        // Die beiden Anschriften bekommen jede ihre eigene Beschriftung. Ein
        // Blatt, auf dem zweimal „Anschrift" steht, laesst offen, welche
        // davon wem gehoert — und dieses Blatt geht ans Amtsgericht.
        $at = $this->row($sheet, $at, 'dunning.creditor', $summary['creditor']['name']);
        $at = $this->row($sheet, $at, 'dunning.creditor_address', $summary['creditor']['address']);
        $at = $this->row($sheet, $at, 'dunning.debtor', $summary['debtor']['name']);

        return $this->row($sheet, $at, 'dunning.debtor_address', $summary['debtor']['address']);
    }

    /**
     * @param list<array{claim: Claim, interest: InterestSchedule}> $rows
     */
    private function principal(Sheet $sheet, array $rows, float $at): float
    {
        $at = $this->heading($sheet, 'dunning.pdf.court_principal', $at);

        foreach ($rows as $row) {
            $claim = $row['claim'];
            $sheet->put(Sheet::LEFT, $at, $claim->subject(), 9.0);
            $sheet->put(Sheet::LEFT + 75.0, $at, $claim->arrears()->dueOn()->format('d.m.Y'), 8.0);
            $sheet->put(Sheet::LEFT + 100.0, $at, $claim->arrears()->beginsOn()->format('d.m.Y'), 8.0);
            $sheet->putRight(210.0 - Sheet::RIGHT, $at, $this->amounts->money($claim->open()), 9.0);
            $at += 5.0;
        }

        return $at;
    }

    /**
     * @param list<array{claim: Claim, interest: InterestSchedule}> $rows
     */
    private function interest(Sheet $sheet, array $rows, float $at): float
    {
        $at = $this->heading($sheet, 'dunning.pdf.court_interest', $at);

        // Je Hauptforderung ein eigener Block mit eigener Summe: eine
        // Zinsstaffel, die nicht sagt, worauf sie laeuft, laesst sich im
        // Antrag keiner Forderung zuordnen — und dann ist sie wertlos.
        foreach ($rows as $row) {
            $at = $this->interestOf($sheet, $row['claim']->subject(), $row['interest'], $at);
        }

        return $at;
    }

    /** Ein Block: der Betreff, seine Abschnitte, seine Summe. */
    private function interestOf(Sheet $sheet, string $subject, InterestSchedule $schedule, float $at): float
    {
        $at = $sheet->room($at, 10.0);
        $sheet->put(Sheet::LEFT, $at, $subject, 8.5, 'B');
        $at += 4.5;

        foreach ($schedule->segments as $segment) {
            $at = $sheet->room($at, 6.0);
            $sheet->put(Sheet::LEFT + 4.0, $at, $this->segment($segment), 8.0);
            $sheet->putRight(210.0 - Sheet::RIGHT, $at, $this->amounts->money($segment->interest), 8.0);
            $at += 4.5;
        }

        $sheet->putRight(210.0 - Sheet::RIGHT, $at, $this->amounts->money($schedule->total()), 8.0, 'B');

        return $at + 6.0;
    }

    private function segment(InterestSegment $segment): string
    {
        return $this->translator->trans('dunning.pdf.segment', [
            '%from%' => $segment->from->format('d.m.Y'),
            '%until%' => $segment->until->format('d.m.Y'),
            '%days%' => $segment->days,
            '%rate%' => $this->percent($segment->rateBps()),
            '%amount%' => $this->amounts->money($segment->amount),
        ]);
    }

    /**
     * @param array{principal: Money, interest: Money, extras: Money, ...} $summary
     */
    private function totals(Sheet $sheet, array $summary, float $at): float
    {
        $at = $this->money($sheet, $at, 'dunning.open', $summary['principal']);
        $at = $this->money($sheet, $at, 'dunning.interest', $summary['interest']);
        $at = $this->money($sheet, $at, 'dunning.pdf.court_extras', $summary['extras']);
        $sheet->rule($at);
        $total = $summary['principal']->plus($summary['interest'])->plus($summary['extras']);
        $sheet->put(Sheet::LEFT, $at + 1.0, $this->translator->trans('dunning.pdf.court_total'), 10.0, 'B');
        $sheet->putRight(210.0 - Sheet::RIGHT, $at + 1.0, $this->amounts->money($total), 10.0, 'B');

        return $at + 9.0;
    }

    /**
     * @param list<Notice> $notices
     */
    private function history(Sheet $sheet, array $notices, float $at): void
    {
        $at = $this->heading($sheet, 'dunning.pdf.court_history', $at);

        foreach ($notices as $notice) {
            $at = $sheet->room($at, 6.0);
            $sheet->put(Sheet::LEFT, $at, $this->translator->trans($notice->level()->labelKey()), 9.0);
            $sheet->put(Sheet::LEFT + 60.0, $at, $notice->reference(), 8.0);
            $sheet->put(Sheet::LEFT + 100.0, $at, $notice->issuedOn()?->format('d.m.Y') ?? '', 8.0);
            $sheet->putRight(210.0 - Sheet::RIGHT, $at, $notice->payBy()->format('d.m.Y'), 8.0);
            $at += 5.0;
        }
    }

    private function heading(Sheet $sheet, string $key, float $at): float
    {
        $at = $sheet->room($at, 12.0);
        $sheet->put(Sheet::LEFT, $at, $this->translator->trans($key), 10.0, 'B');

        return $at + 6.0;
    }

    /**
     * Eine Zeile des Auszugs.
     *
     * Anschriften stehen hier **in einer Zeile**: sie sind eine Angabe unter
     * vielen und kein Anschriftfeld, und drei Zeilen braechen die Reihe.
     */
    private function row(Sheet $sheet, float $at, string $key, string $value): float
    {
        $inline = PostalLines::fromText($value)->inline();

        $sheet->put(Sheet::LEFT, $at, $this->translator->trans($key), 9.0);
        $sheet->put(Sheet::LEFT + 45.0, $at, '' === $inline ? '—' : $inline, 9.0);

        return $at + 5.0;
    }

    private function money(Sheet $sheet, float $at, string $key, Money $amount): float
    {
        $sheet->put(Sheet::LEFT, $at, $this->translator->trans($key), 9.0);
        $sheet->putRight(210.0 - Sheet::RIGHT, $at, $this->amounts->money($amount), 9.0);

        return $at + 5.0;
    }

    /** 652 wird zu „6,52 %" — dieselbe Umrechnung wie der Twig-Filter. */
    private function percent(int $basisPoints): string
    {
        return $this->amounts->number(\sprintf('%d.%02d', intdiv($basisPoints, 100), abs($basisPoints) % 100)).' %';
    }
}
