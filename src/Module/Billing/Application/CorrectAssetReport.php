<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\AssetReport;
use App\Module\Billing\Domain\AssetReportCannotBeCorrected;
use App\Module\Billing\Domain\AssetReportIterationIsTaken;
use App\Module\Billing\Domain\AssetReportRepository;
use App\Module\Billing\Domain\StatementStatus;

/**
 * Eine berichtigte Fassung anlegen.
 *
 * Das Gesetz nennt den Anlass selbst: der Eigentuemer hat einen
 * Berichtigungsanspruch (§ 28 Abs. 4 WEG). Korrigiert wird ein Beschluss —
 * hier gibt es keinen; berichtigt wird eine Auskunft.
 *
 * Die neue Fassung traegt dieselbe Nummer und dieselben Positionen,
 * **mitsamt Betraegen**: derselbe Stichtag, dieselben Konten, und falsch war
 * eine einzelne Angabe. Wer dafuer alles neu eintippen muesste, tippt sich
 * einen neuen Fehler.
 */
final readonly class CorrectAssetReport
{
    public function __construct(private AssetReportRepository $reports)
    {
    }

    /**
     * Berichtigt wird nur die juengste **herausgegebene** Fassung.
     *
     * Beides steht hier und nicht in der Oberflaeche: den Knopf gibt es nur
     * auf einem herausgegebenen Bericht, aber eine abgeschickte Adresse ist
     * Eingabe und keine Zusicherung. Der eindeutige Index darunter faenge den
     * Fall zwar auch — als Datenbankfehler, und der ist keine Antwort auf eine
     * Frage, die man verstehen kann.
     *
     * @throws AssetReportCannotBeCorrected
     * @throws AssetReportIterationIsTaken
     */
    public function of(AssetReport $original): AssetReport
    {
        if ($original->release()->isDraft()) {
            throw AssetReportCannotBeCorrected::itIsStillADraft();
        }

        if (!$this->isTheLatest($original)) {
            throw AssetReportCannotBeCorrected::itIsNotTheLatestVersion();
        }

        $correction = new AssetReport(
            $original->edition()->number(),
            $original->propertyId(),
            $original->propertyNumber(),
            $original->period(),
        );
        $correction->corrects($original);
        $correction->describe($original->label());

        foreach ($original->items() as $item) {
            $item->copyInto($correction);
        }

        $this->reports->save($correction);

        return $correction;
    }

    /** Eine schon angefangene Berichtigung — dann fuehrt der Knopf dorthin. */
    public function openFor(int $number): ?AssetReport
    {
        foreach ($this->reports->iterationsOf($number) as $iteration) {
            if (StatementStatus::Draft === $iteration->release()->status()) {
                return $iteration;
            }
        }

        return null;
    }

    /**
     * Laesst sich dieser Bericht berichtigen?
     *
     * Fuer die Ansicht: einen Knopf, der zuverlaessig in eine Absage fuehrt,
     * zeigt man nicht. Die Absage bleibt trotzdem stehen — {@see of()} ist die
     * Stelle, die entscheidet, und eine abgeschickte Adresse fragt nicht
     * vorher.
     */
    public function canBeCorrected(AssetReport $report): bool
    {
        return !$report->release()->isDraft() && $this->isTheLatest($report);
    }

    /** Gibt es zu diesem Vorgang schon eine spaetere Fassung? */
    private function isTheLatest(AssetReport $original): bool
    {
        foreach ($this->reports->iterationsOf($original->edition()->number()) as $iteration) {
            if ($iteration->edition()->iteration() > $original->edition()->iteration()) {
                return false;
            }
        }

        return true;
    }
}
