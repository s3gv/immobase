<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Pdf;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Der obere Teil eines Briefes: Kopf, Anschriftfeld, Informationsblock.
 *
 * Bei jedem Schreiben derselbe — bei der Abrechnung wie beim Wirtschaftsplan
 * wie bei der Mahnung. Zweimal gesetzt waere er zweimal zu pflegen, und schon
 * ein um zwei Millimeter verrutschtes Anschriftfeld passt nicht mehr ins
 * Fensterkuvert. Das merkt man erst beim Empfaenger.
 *
 * Woher die Absenderangaben kommen, weiss er nicht — das sagt ihm
 * {@see SenderOfLetters}. Nur so kann er in Shared liegen und von jedem Modul
 * benutzt werden, das Briefe schreibt.
 */
final readonly class LetterHead
{
    public function __construct(
        private SenderOfLetters $senders,
        private TranslatorInterface $translator,
    ) {
    }

    public function head(Sheet $sheet): void
    {
        $sender = $this->senders->sender();

        if (null !== $sender->logo) {
            // Der Typ muss dabeistehen: FPDF raet ihn sonst aus der Endung,
            // und eine `data:`-Adresse hat keine.
            $sheet->Image(
                'data://image/png;base64,'.base64_encode($sender->logo),
                Sheet::LEFT,
                12.0,
                60.0,
                0.0,
                'PNG',
            );
        }

        $sheet->putRight(210.0 - Sheet::RIGHT, 14.0, $sender->name, 9.0, 'B');
        $sheet->putRight(210.0 - Sheet::RIGHT, 19.0, $sender->street, 8.0);
        $sheet->putRight(210.0 - Sheet::RIGHT, 23.0, $sender->postalCode.' '.$sender->city, 8.0);

        // Die Fusszeile gehoert zum Kopf: beide sagen, von wem der Brief ist.
        // Sie wird hier gesetzt und von FPDF auf jedem Blatt gezeichnet.
        $sheet->footWith(
            $sender->oneLine(),
            $this->translator->trans('pdf.page', ['%page%' => '{p}', '%pages%' => '{nb}']),
        );
    }

    /**
     * Das Anschriftfeld — 85 Millimeter breit, ab 62,7 von oben, sechs Zeilen.
     *
     * Unmittelbar darueber steht die Absenderzeile: sie schliesst die Zusatz-
     * und Vermerkzone ab und ist der Grund, warum ein Brief mit falscher
     * Anschrift zurueckkommt statt verlorenzugehen.
     *
     * Vorher begann die Anschrift erst unterhalb der Vermerkzone und stand
     * damit siebzehn Millimeter zu tief — im Fenster war sie noch zu sehen,
     * die letzte Zeile lief aber in den Betreff.
     */
    public function address(Sheet $sheet, string $label, string $address): void
    {
        $sheet->put(Sheet::LEFT, Sheet::ADDRESS_TOP - 5.0, $this->senders->sender()->oneLine(), 7.0);

        // Zeile fuer Zeile und nicht als ein Block: der Name darf umbrechen —
        // ein Ehepaar ist ein Empfaenger, und zwei Namen passen selten in eine
        // Zeile —, Strasse und Ort duerfen es nicht.
        $at = Sheet::ADDRESS_TOP;

        foreach (PostalLines::of($label, PostalLines::fromText($address)->lines)->lines as $line) {
            $at = $sheet->putLines(Sheet::LEFT, $at, Sheet::ADDRESS_WIDTH, $line);
        }
    }

    public function info(Sheet $sheet, string $reference, string $date): void
    {
        $top = Sheet::INFO_TOP;
        $sheet->put(Sheet::INFO_LEFT, $top, $this->translator->trans('pdf.reference'), 8.0, 'B');
        $sheet->put(Sheet::INFO_LEFT, $top + 4.5, $reference, 9.0);
        $sheet->put(Sheet::INFO_LEFT, $top + 12.0, $this->translator->trans('pdf.date'), 8.0, 'B');
        $sheet->put(Sheet::INFO_LEFT, $top + 16.5, $date, 9.0);
    }
}
