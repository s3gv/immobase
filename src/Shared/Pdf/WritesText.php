<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Pdf;

/**
 * Text auf ein Blatt setzen — die drei Arten, die es dafuer braucht.
 *
 * Getrennt von {@see Sheet}: dort steht, wie ein Blatt aufgebaut ist — Maße, Marken,
 * Seitenwechsel —, hier steht, wie ein Wort daraufkommt.
 *
 * **Kein Blocksatz.** FPDF steht bei `MultiCell` per Vorgabe auf `J` und
 * zerrt dann die Woerter auseinander, bis die Zeile voll ist. Ein
 * Geschaeftsbrief nach DIN 5008 ist linksbuendig.
 */
trait WritesText
{
    /**
     * Eine Zeile Text an einer Stelle.
     *
     * Die Umwandlung nach CP1252 gehoert hierher und nicht an jeden Aufruf:
     * die Kernschriften von FPDF koennen kein UTF-8, und ein „ä", das als
     * zwei Zeichen ankommt, faellt erst im gedruckten Brief auf.
     */
    public function put(float $x, float $y, string $text, float $size = 10.0, string $style = ''): void
    {
        $this->SetFont('Helvetica', $style, $size);
        $this->SetXY($x, $y);
        $this->Cell(0, 5.0, self::encode($text));
    }

    /** Rechtsbuendig, fuer Betraege. */
    public function putRight(float $right, float $y, string $text, float $size = 10.0, string $style = ''): void
    {
        $this->SetFont('Helvetica', $style, $size);
        $this->SetXY($right - 40.0, $y);
        $this->Cell(40.0, 5.0, self::encode($text), 0, 0, 'R');
    }

    /**
     * Mehrere Zeilen ab einer Stelle; gibt die neue Hoehe zurueck.
     *
     * **Linksbuendig und nicht im Blocksatz.** FPDF steht bei `MultiCell` per
     * Vorgabe auf `J` und zerrt dann die Woerter auseinander, bis die Zeile
     * voll ist — im Anschriftfeld sah „Tobias Wagner und Katrin Schneider"
     * aus, als haette es jemand gesperrt gesetzt. Ein Geschaeftsbrief nach
     * DIN 5008 ist linksbuendig.
     */
    public function putLines(float $x, float $y, float $width, string $text, float $size = 10.0): float
    {
        $this->SetFont('Helvetica', '', $size);
        $this->SetXY($x, $y);
        $this->MultiCell($width, 4.5, self::encode($text), 0, 'L');

        $at = $this->GetY();

        return \is_float($at) || \is_int($at) ? (float) $at : 0.0;
    }

    private static function encode(string $text): string
    {
        $converted = iconv('UTF-8', 'CP1252//TRANSLIT', $text);

        return false === $converted ? $text : $converted;
    }
}
