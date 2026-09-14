<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Pdf;

/**
 * Was ein Blatt traegt, ohne dass es jemand geschrieben haette: Falz- und
 * Lochmarken, Linien, Ueberschriften, die Fusszeile.
 *
 * Getrennt von {@see Sheet}, damit dort die Maße und der Seitenwechsel
 * stehen und hier das Beiwerk — dieselbe Teilung wie zwischen {@see Sheet}
 * und {@see WritesText}.
 *
 * Alles davon sieht in jedem Schreiben gleich aus, und das ist der Grund, es
 * an einer Stelle zu halten: vorher setzte jeder Brief seine Ueberschriften
 * selbst, und einer hatte sechs Millimeter Abstand darunter, der naechste
 * sieben.
 */
trait CarriesMarks
{
    /** Was unten links steht: der Absender, einzeilig. */
    private string $foot = '';

    /**
     * „Seite {p} von {nb}", schon uebersetzt.
     *
     * Nicht `$pages`: so heisst in FPDF die Sammlung der Seiten, und ein
     * privates Feld gleichen Namens bricht die Klasse beim Laden.
     */
    private string $pageLabel = '';

    /**
     * Was oben auf jedem Blatt steht — FPDF ruft es von selbst auf.
     *
     * Die Marken und, ab dem zweiten Blatt, die Referenz. Vorher setzte sie
     * nur {@see Sheet::room()}, also nur dort, wo ein Brief den Umbruch
     * selbst verlangte: brach FPDF von sich aus um, kam ein Blatt ohne
     * Marken und ohne Zuordnung heraus.
     */
    public function Header(): void
    {
        $this->marks();

        if ('' !== $this->continuation && 1 !== $this->PageNo()) {
            $this->put(self::LEFT, 20.0, $this->continuation, 8.0);
        }
    }

    /**
     * Falz- und Lochmarken.
     *
     * Sie stehen ganz links am Rand und sind das, was ein Blatt ueberhaupt
     * erst maschinell faltbar macht.
     */
    public function marks(): void
    {
        $this->SetDrawColor(120, 120, 120);
        $this->SetLineWidth(0.2);

        foreach ([self::FOLD_ONE, self::FOLD_TWO] as $at) {
            $this->Line(5.0, $at, 12.0, $at);
        }

        $this->Line(5.0, self::PUNCH, 15.0, self::PUNCH);
        $this->SetDrawColor(0, 0, 0);
    }

    /**
     * Was unten auf jedem Blatt steht.
     *
     * Der Absender, damit ein einzelnes Blatt zuzuordnen ist, und die
     * Seitenzahl mit ihrer Gesamtzahl — wer zwei Seiten aus dem Kuvert nimmt,
     * soll sehen, ob eine dritte fehlt.
     *
     * Uebersetzt wird draussen: dieses Blatt kennt keine Sprache. Es bekommt
     * den fertigen Satz mit einer Marke fuer die laufende Seite.
     */
    public function footWith(string $sender, string $pages): void
    {
        $this->foot = $sender;
        $this->pageLabel = $pages;
    }

    /**
     * Die Ueberschrift eines Abschnitts, mit einer Haarlinie darunter.
     *
     * An einer Stelle, damit jeder Abschnitt in jedem Schreiben gleich
     * aussieht: vorher setzte jeder Brief seine Ueberschriften selbst, und
     * einer hatte sechs Millimeter Abstand darunter, der naechste sieben.
     *
     * Gibt zurueck, wo es weitergeht.
     */
    public function heading(float $at, string $text): float
    {
        $this->put(self::LEFT, $at, $text, 10.0, 'B');

        $this->SetDrawColor(210, 210, 210);
        $this->SetLineWidth(0.2);
        $this->Line(self::LEFT, $at + 5.2, 210.0 - self::RIGHT, $at + 5.2);
        $this->SetDrawColor(0, 0, 0);

        return $at + 7.0;
    }

    public function rule(float $y): void
    {
        $this->SetDrawColor(180, 180, 180);
        $this->SetLineWidth(0.2);
        $this->Line(self::LEFT, $y, 210.0 - self::RIGHT, $y);
        $this->SetDrawColor(0, 0, 0);
    }

    /**
     * Die Fusszeile — FPDF ruft sie von selbst auf jedem Blatt auf.
     *
     * Eine Haarlinie darueber, links der Absender, rechts die Seitenzahl.
     * Beides klein und grau: es ist eine Auskunft und keine Aussage.
     */
    public function Footer(): void
    {
        if ('' === $this->foot && '' === $this->pageLabel) {
            return;
        }

        $at = self::HEIGHT + self::FOOT;
        $number = $this->PageNo();

        $this->SetDrawColor(200, 200, 200);
        $this->SetLineWidth(0.2);
        $this->Line(self::LEFT, $at, 210.0 - self::RIGHT, $at);
        $this->SetDrawColor(0, 0, 0);

        $this->SetTextColor(110, 110, 110);
        $this->put(self::LEFT, $at + 1.5, $this->foot, 7.5);
        $this->putRight(
            210.0 - self::RIGHT,
            $at + 1.5,
            str_replace('{p}', \is_int($number) ? (string) $number : '', $this->pageLabel),
            7.5,
        );
        $this->SetTextColor(0, 0, 0);
    }
}
