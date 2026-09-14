<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

use App\Module\Billing\Application\ComposeAssetReport;
use App\Module\Billing\Domain\AssetReport;
use App\Module\Billing\Domain\AssetReportDocument;
use App\Module\Billing\Domain\AssetReportRepository;
use App\Module\Billing\Domain\BillingPermissions;
use App\Module\Billing\UserInterface\Pdf\AssetReportLetter;
use App\Shared\Pdf\PdfArchive;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use ZipArchive;

/**
 * Der Vermoegensbericht als PDF, gebuendelt.
 *
 * Nur aus einem herausgegebenen Bericht: vorher gibt es keinen Tag, an dem er
 * vorlag, und ohne Tag traegt dasselbe Blatt morgen ein anderes Datum.
 *
 * Der Inhalt ist fuer alle Empfaenger derselbe; verschieden ist das
 * Anschriftfeld. Trotzdem ein Schreiben je Einheit und nicht ein Aushang:
 * jeder Eigentuemer kann den Bericht verlangen (§ 28 Abs. 4 WEG), und was man
 * verlangen kann, bekommt man adressiert.
 */
#[IsGranted(BillingPermissions::VIEW)]
final class AssetReportPdfController extends AbstractController
{
    public function __construct(
        private readonly AssetReportRepository $reports,
        private readonly ComposeAssetReport $compose,
        private readonly AssetReportLetter $letter,
    ) {
    }

    #[Route('/billing/vermoegensberichte/{id}/pdf', name: 'app_billing_report_pdf', methods: ['GET'])]
    public function download(string $id): Response
    {
        $report = $this->reports->byId($id)
            ?? throw $this->createNotFoundException('Diesen Vermögensbericht gibt es nicht.');
        $day = $report->release()->day();

        if (null === $day) {
            throw $this->createNotFoundException('Aus einem Entwurf entsteht kein Vermögensbericht.');
        }

        $response = new Response($this->bundle($report, $day));
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                self::nameOf($report),
            ),
        );

        return $response;
    }

    /**
     * Alle Schreiben in einem Archiv.
     *
     * ZipArchive braucht eine Datei; sie wird angelegt, gefuellt, gelesen und
     * weggeraeumt.
     */
    private function bundle(AssetReport $report, DateTimeImmutable $day): string
    {
        $body = $this->compose->of($report);
        $letters = [];

        foreach ($report->documents() as $document) {
            $letters[self::fileOf($document)] = $this->letter->of($report, $document, $body, $day);
        }

        return PdfArchive::of($letters, $day);
    }

    private static function nameOf(AssetReport $report): string
    {
        return \sprintf(
            'vermoegensbericht-%d-%d-%d.zip',
            $report->propertyNumber(),
            $report->period()->year(),
            $report->edition()->number(),
        );
    }

    /** Die Referenz als Dateiname — der Schraegstrich muss weichen, er waere ein Verzeichnis. */
    private static function fileOf(AssetReportDocument $document): string
    {
        return str_replace('/', '-', $document->reference()->toString()).'.pdf';
    }
}
