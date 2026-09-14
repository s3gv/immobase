<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\UserInterface\Pdf;

use App\Module\Dunning\Domain\Notice;
use App\Module\Dunning\Domain\NoticeLine;
use App\Shared\Money\Money;
use App\Shared\Pdf\Amounts;
use App\Shared\Pdf\Sheet;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Die Aufstellung auf dem Mahnschreiben.
 *
 * Je Forderung eine Zeile mit Betreff, Faelligkeit und Betrag — der Schuldner
 * findet sie so in seinen Unterlagen wieder. Darunter die Zinsen, die
 * Mahnkosten und die Pauschale **einzeln**: wer sie in die Hauptforderung
 * rechnete, verzinste sie mit, und das darf man nicht.
 */
final readonly class NoticeTable
{
    public function __construct(
        private TranslatorInterface $translator,
        private Amounts $amounts,
    ) {
    }

    public function of(Sheet $sheet, Notice $notice, float $at): float
    {
        $at = $this->heading($sheet, $at);

        foreach ($notice->lines() as $line) {
            $at = $this->claim($sheet, $at, $line);
        }

        $at += 2.0;
        $at = $this->extra($sheet, $at, 'dunning.interest', $notice->interest());
        $at = $this->extra($sheet, $at, 'dunning.costs', $notice->charges()->costs());
        $at = $this->extra($sheet, $at, 'dunning.flat_fee', $notice->charges()->flatFee());

        return $this->sum($sheet, $at, $notice->total());
    }

    private function heading(Sheet $sheet, float $at): float
    {
        $sheet->put(Sheet::LEFT, $at, $this->translator->trans('dunning.subject'), 8.0, 'B');
        $sheet->put(Sheet::LEFT + 95.0, $at, $this->translator->trans('dunning.due_on'), 8.0, 'B');
        $sheet->putRight(210.0 - Sheet::RIGHT, $at, $this->translator->trans('dunning.open'), 8.0, 'B');

        // Unter der Kopfzeile und nicht durch sie hindurch: `put()` setzt den
        // Text in eine fuenf Millimeter hohe Zelle, seine Grundlinie liegt
        // also bei 3,5 — eine Linie bei 2,0 lief mitten durch die Woerter.
        $sheet->rule($at + 5.5);

        return $at + 8.0;
    }

    private function claim(Sheet $sheet, float $at, NoticeLine $line): float
    {
        $sheet->put(Sheet::LEFT, $at, $line->subject(), 9.0);
        $sheet->put(Sheet::LEFT + 95.0, $at, $line->dueOn()->format('d.m.Y'), 9.0);
        $sheet->putRight(210.0 - Sheet::RIGHT, $at, $this->amounts->money($line->amount()), 9.0);

        return $at + 5.0;
    }

    /** Null Euro steht nicht da — eine Zeile ueber nichts ist keine Forderung. */
    private function extra(Sheet $sheet, float $at, string $key, Money $amount): float
    {
        if ($amount->isZero()) {
            return $at;
        }

        $sheet->put(Sheet::LEFT, $at, $this->translator->trans($key), 9.0);
        $sheet->putRight(210.0 - Sheet::RIGHT, $at, $this->amounts->money($amount), 9.0);

        return $at + 5.0;
    }

    private function sum(Sheet $sheet, float $at, Money $total): float
    {
        $sheet->rule($at);
        $sheet->put(Sheet::LEFT, $at + 1.0, $this->translator->trans('dunning.total'), 10.0, 'B');
        $sheet->putRight(210.0 - Sheet::RIGHT, $at + 1.0, $this->amounts->money($total), 10.0, 'B');

        return $at + 9.0;
    }
}
