<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Pdf;

use DateTimeImmutable;
use FPDF;

/**
 * Ein Blatt nach DIN 5008, Form B.
 *
 * Die Maße stehen hier und nirgends sonst — ein Briefbogen, dessen
 * Anschriftfeld um zwei Millimeter verrutscht, passt nicht mehr ins
 * Fensterkuvert, und das merkt man erst beim Empfaenger.
 *
 * **Kein HTML-zu-PDF.** Dompdf, TCPDF und mPDF sind LGPL beziehungsweise GPL
 * und nach `docs/licensing/dependency-policy.md` im Kern ausgeschlossen. FPDF
 * ist MIT, reines PHP und braucht keinen zweiten Dienst — dafuer wird das
 * Blatt selbst gesetzt. Bei festem Layout ist das ohnehin der ehrlichere Weg.
 *
 * **Deterministisch.** Dasselbe Dokument zweimal erzeugt ergibt dieselben
 * Bytes. Dafuer wird das Erstellungsdatum festgeschrieben: FPDF setzt sonst
 * `time()`, und schon die zweite Sekunde ergaebe eine andere Datei.
 */
class Sheet extends FPDF
{
    use CarriesMarks;
    use WritesText;

    /** Der Briefkopf endet hier; darunter beginnt das Anschriftfeld. */
    public const float HEAD_HEIGHT = 45.0;

    /**
     * Die Anschriftzone beginnt hier — darin zuerst die Zusatz- und
     * Vermerkzone mit der Absenderzeile.
     */
    public const float NOTES_TOP = 45.0;

    /** Zusatz- und Vermerkzone: fuenf Zeilen, danach faengt die Anschrift an. */
    public const float ADDRESS_NOTES = 17.7;

    /**
     * Oberkante des Anschriftfeldes, Form B: 45,0 + 17,7.
     *
     * Darunter sechs Zeilen zu 4,5 Millimetern — zusammen 27, und bei 90,0
     * endet das Fenster. Wer hier tiefer anfaengt, schiebt die letzte Zeile
     * aus dem Kuvert und merkt es erst beim Empfaenger.
     */
    public const float ADDRESS_TOP = self::NOTES_TOP + self::ADDRESS_NOTES;

    public const float ADDRESS_LINE = 4.5;

    public const float ADDRESS_WIDTH = 85.0;

    public const float LEFT = 25.0;
    public const float RIGHT = 20.0;

    /** A4 hoch. Steht hier, weil FPDF seine eigene Hoehe als `mixed` fuehrt. */
    public const float HEIGHT = 297.0;

    /** Der Informationsblock steht rechts neben dem Anschriftfeld. */
    public const float INFO_LEFT = 125.0;
    public const float INFO_TOP = 50.0;

    public const float FOLD_ONE = 105.0;
    public const float FOLD_TWO = 210.0;
    public const float PUNCH = 148.5;

    /** Die Fusszeile beginnt hier — 15 Millimeter ueber dem Blattrand. */
    public const float FOOT = -15.0;

    private int $createdAt;

    /** Die Zeile, die eine Folgeseite als Teil dieses Briefes ausweist. */
    private string $continuation = '';

    public function __construct(DateTimeImmutable $on)
    {
        parent::__construct('P', 'mm', 'A4');
        $this->createdAt = $on->getTimestamp();
        // Ohne das bliebe „{nb}" als Zeichenfolge stehen, und auf jedem Brief
        // staende „Seite 1 von {nb}".
        $this->AliasNbPages();
        $this->SetAutoPageBreak(true, 25.0);
        $this->SetMargins(self::LEFT, 20.0, self::RIGHT);
        $this->SetTitle('');
        $this->SetAuthor('');
        $this->SetCreator('');
    }

    /**
     * Was auf einer Folgeseite oben steht — ohne das waere sie ein loses Blatt.
     *
     * Die Referenz und nichts weiter: wer zwei Seiten aus dem Kuvert nimmt und
     * eine davon verliert, soll die andere wieder zuordnen koennen.
     */
    public function continueWith(string $reference): void
    {
        $this->continuation = $reference;
    }

    /**
     * Platz fuer einen Block, der zusammenbleiben muss.
     *
     * FPDF bricht von selbst um, sobald eine Zelle unter den Rand geraet — und
     * zwar mitten im Block: die Beschriftung stand dann auf der einen Seite
     * und ihr Betrag auf der naechsten. Wer hier vorher fragt, bekommt
     * entweder dieselbe Hoehe zurueck oder den oberen Rand eines neuen
     * Blattes.
     */
    public function room(float $at, float $height): float
    {
        if ($at + $height <= $this->PageBreakTrigger) {
            return $at;
        }

        // Marken und Fortsetzungszeile setzt Header() — bei diesem Umbruch
        // wie bei dem, den FPDF von selbst macht.
        $this->AddPage();

        return 32.0;
    }

    public function bytes(): string
    {
        $bytes = $this->Output('S');

        return \is_string($bytes) ? $bytes : '';
    }

    /**
     * FPDF setzt das Erstellungsdatum auf `time()`; hier wird es
     * festgeschrieben, damit zweimal erzeugen zweimal dasselbe ergibt.
     */
    protected function _putinfo(): void
    {
        $this->CreationDate = $this->createdAt;

        parent::_putinfo();
    }
}
