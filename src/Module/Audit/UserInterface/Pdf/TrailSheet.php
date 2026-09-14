<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Audit\UserInterface\Pdf;

use App\Module\Audit\Contract\AuditLine;
use App\Shared\Pdf\LetterHead;
use App\Shared\Pdf\Sheet;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Der Abzug des Protokolls — eine formlose Tabelle.
 *
 * **Kein Brief.** Es gibt keinen Empfaenger, also kein Anschriftfeld und
 * keine Anrede; der Kopf sagt nur, aus welcher Verwaltung das Blatt kommt und
 * wann es gezogen wurde. Die Fusszeile zaehlt die Seiten, wie bei jedem
 * anderen Blatt auch.
 *
 * Der Zweck ist das Aufbewahren: das Protokoll haelt achtundvierzig Stunden,
 * und was laenger bleiben soll, bleibt als dieses Blatt.
 */
final readonly class TrailSheet
{
    /** Spaltenanfaenge in Millimetern, von links. */
    private const float WHEN = Sheet::LEFT;
    private const float WHO = 60.0;
    private const float WHAT = 110.0;
    private const float RECORD = 140.0;

    private const float LINE = 5.0;

    public function __construct(
        private LetterHead $letterhead,
        private TranslatorInterface $translator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<AuditLine> $lines
     */
    public function __invoke(array $lines): string
    {
        $now = $this->clock->now();
        $sheet = new Sheet($now);
        $sheet->AddPage();
        $this->letterhead->head($sheet);

        $at = $sheet->heading(Sheet::HEAD_HEIGHT, $this->translator->trans('audit.heading'));
        $sheet->put(Sheet::LEFT, $at, $this->translator->trans('audit.pdf.drawn', [
            '%at%' => $now->format('d.m.Y H:i'),
            '%count%' => \count($lines),
        ]), 8.0);

        $at = $this->columns($sheet, $at + 8.0);

        foreach ($lines as $line) {
            $at = $this->row($sheet, $sheet->room($at, self::LINE * 2), $line);
        }

        return $sheet->bytes();
    }

    /**
     * Die Spaltenueberschriften — auf jeder Seite neu.
     *
     * Eine Tabelle, deren zweite Seite ohne Ueberschriften anfaengt, ist eine
     * Spaltenwand.
     */
    private function columns(Sheet $sheet, float $at): float
    {
        // Paare und keine Zuordnung: Millimeter sind Kommazahlen, und die
        // taugen nicht als Schluessel eines Feldes.
        foreach ([
            [self::WHEN, 'audit.field.when'],
            [self::WHO, 'audit.field.who'],
            [self::WHAT, 'audit.field.what'],
            [self::RECORD, 'audit.field.record'],
        ] as [$x, $key]) {
            $sheet->put($x, $at, $this->translator->trans($key), 8.0, 'B');
        }

        $sheet->rule($at + 5.5);

        return $at + 8.0;
    }

    private function row(Sheet $sheet, float $at, AuditLine $line): float
    {
        $entry = $line->entry;

        $sheet->put(self::WHEN, $at, $entry->at()->format('d.m.Y H:i'), 8.0);
        $sheet->put(self::WHO, $at, $entry->actor(), 8.0);
        $sheet->put(self::WHAT, $at, $this->translator->trans($entry->action()->labelKey()), 8.0);
        $sheet->put(self::RECORD, $at, $entry->record(), 8.0);

        // Bezeichnung und Kennung stehen kleiner darunter: sie sind die
        // Auskunft darueber, welcher Datensatz gemeint war, und sie sind zu
        // lang fuer eine Spalte.
        $second = trim($entry->label().('' === $entry->recordId() ? '' : ' · '.$entry->recordId()));

        if ('' !== $second) {
            $sheet->put(self::WHO, $at + self::LINE - 1.0, $second, 7.0);
        }

        return $at + self::LINE * 2;
    }
}
