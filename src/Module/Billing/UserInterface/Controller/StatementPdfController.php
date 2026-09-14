<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

use App\Module\Billing\Domain\BillingPermissions;
use App\Module\Billing\Domain\Statement;
use App\Module\Billing\Domain\StatementDocument;
use App\Module\Billing\Domain\StatementRepository;
use App\Module\Billing\UserInterface\Pdf\StatementLetter;
use App\Shared\Pdf\PdfArchive;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Die Abrechnungen als PDF, gebuendelt.
 *
 * Erzeugt wird erst beim Herunterladen und nicht bei der Freigabe: ein PDF,
 * das niemand abholt, ist Platz fuer nichts — und die Berechnung liegt
 * ohnehin fest, also entsteht dasselbe Dokument auch in einem Jahr noch.
 *
 * Ein ZIP und keine Einzeldatei: wer abrechnet, verschickt ein Objekt und
 * nicht eine Wohnung. Wer nur eine braucht, findet sie darin.
 */
#[IsGranted(BillingPermissions::VIEW)]
final class StatementPdfController extends AbstractController
{
    public function __construct(
        private readonly StatementRepository $statements,
        private readonly StatementLetter $letter,
    ) {
    }

    #[Route('/billing/abrechnungen/{id}/pdf', name: 'app_billing_statement_pdf', methods: ['GET'])]
    public function download(string $id): Response
    {
        $statement = $this->statements->byId($id)
            ?? throw $this->createNotFoundException('Diese Abrechnung gibt es nicht.');

        if ($statement->isDraft()) {
            throw $this->createNotFoundException('Aus einem Entwurf entsteht kein PDF.');
        }

        $bundle = $this->bundle($statement);
        $response = new Response($bundle);
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                self::nameOf($statement),
            ),
        );

        return $response;
    }

    /**
     * Alle gewuenschten Schreiben in einem Archiv.
     *
     * ZipArchive braucht eine Datei; sie wird angelegt, gefuellt, gelesen und
     * weggeraeumt. Ein Archiv im Speicher zusammenzusetzen waere mehr Code
     * fuer denselben Weg.
     */
    private function bundle(Statement $statement): string
    {
        $letters = [];

        foreach ($statement->documents() as $document) {
            if ($document->wantsPdf()) {
                $letters[self::fileOf($document)] = $this->letter->of($statement, $document);
            }
        }

        return PdfArchive::of($letters, $statement->release()->day() ?? new DateTimeImmutable('today'));
    }

    private static function nameOf(Statement $statement): string
    {
        return \sprintf(
            'abrechnung-%d-%d-%d.zip',
            $statement->propertyNumber(),
            $statement->fiscalYear(),
            $statement->number(),
        );
    }

    /**
     * Die Referenz als Dateiname — plus das, was sie nicht unterscheidet.
     *
     * Die Referenz nennt Art, Objekt, Einheit, Jahr, Lauf und Iteration. Sie
     * nennt **nicht** den Zeitraum — und der kann sich innerhalb eines Laufs
     * wiederholen: zwei Mieter nacheinander teilen sich Einheit und Jahr.
     * Ohne den Zusatz ueberschrieben sich die Dateien im Archiv gegenseitig;
     * vier Empfaenger ergaben zwei Dateien.
     *
     * Der Schraegstrich muss dabei weichen — er waere ein Verzeichnis.
     */
    private static function fileOf(StatementDocument $document): string
    {
        return \sprintf(
            '%s-%s.pdf',
            str_replace('/', '-', $document->reference()->toString()),
            $document->period()->from()->format('Y-m-d'),
        );
    }
}
