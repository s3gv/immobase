<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

use App\Module\Billing\Application\AssetItems;
use App\Module\Billing\Domain\AssetKind;
use App\Module\Billing\Domain\AssetReport;
use App\Module\Billing\Domain\AssetReportRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Was ein Berichtsschritt entgegennimmt.
 *
 * Je Schritt eine Methode, gemeinsam ist nur die Form: eine Liste von Fehlern
 * je Feld, leer heisst gespeichert.
 *
 * Zwei Schritte nehmen nichts entgegen — die Ruecklage rechnet die Anwendung
 * selbst, und die Herausgabe hat ihren eigenen Knopf. Sie fehlen hier, statt
 * leere Methoden zu haben.
 */
final readonly class AssetReportStepInput
{
    public function __construct(
        private AssetReportRepository $reports,
        private AssetItems $items,
    ) {
    }

    /**
     * @return array<string, string> Feldname auf Uebersetzungsschluessel
     */
    public function apply(string $step, Request $request, AssetReport $report): array
    {
        return match ($step) {
            AssetReportFlow::BASICS => $this->basics($request, $report),
            AssetReportFlow::ASSETS => $this->rows($request, $report),
            default => [],
        };
    }

    /**
     * Bleibt der Ablauf auf diesem Schritt stehen?
     *
     * Eine Zeile hinzufuegen oder entfernen ist keine Bewegung nach vorn: wer
     * sie anlegt, will sie ausfuellen, und wer sie entfernt, will die Liste
     * ohne sie sehen.
     */
    public static function staysHere(Request $request): bool
    {
        return $request->request->has('add') || $request->request->has('remove');
    }

    /** @return array<string, string> */
    private function basics(Request $request, AssetReport $report): array
    {
        $report->describe($request->request->getString('label'));
        $this->reports->save($report);

        return [];
    }

    /**
     * Die Zeilen — uebernehmen, und erst danach anlegen oder entfernen.
     *
     * In dieser Reihenfolge, weil das Formular mit abgeschickt wird: wer eine
     * Zeile hinzufuegt, hat vielleicht gerade eine Zahl geaendert, und die
     * soll nicht verlorengehen.
     *
     * @return array<string, string>
     */
    private function rows(Request $request, AssetReport $report): array
    {
        $read = AssetItemRows::from($request, 'rows');

        if ([] !== $read['errors']) {
            return $read['errors'];
        }

        $this->items->keep($report, $read['rows']);

        $added = $request->request->getString('add');

        if ('' !== $added) {
            $this->items->add($report, AssetKind::tryFrom($added) ?? AssetKind::Bank);
        }

        $removed = $request->request->getString('remove');

        if ('' !== $removed) {
            $this->items->remove($report, $removed);
        }

        return [];
    }
}
