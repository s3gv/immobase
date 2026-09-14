<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

use App\Module\Billing\Domain\BillingPermissions;
use App\Module\Billing\Domain\Budget;
use App\Module\Billing\Domain\BudgetDocument;
use App\Module\Billing\Domain\BudgetRepository;
use App\Module\Billing\UserInterface\Pdf\BudgetLetter;
use App\Shared\Pdf\PdfArchive;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use ZipArchive;

/**
 * Die Budgetplaene als PDF, gebuendelt.
 *
 * Zwei Wege zum selben Blatt, und beide braucht es. Die **Vorlage** geht vor
 * der Versammlung heraus — ein Beschluss ueber eine Sonderumlage, die niemand
 * vorher gesehen hat, waere anfechtbar. Die **beschlossene Fassung** entsteht
 * aus denselben eingefrorenen Schreiben und bleibt Jahr fuer Jahr dieselbe
 * Datei.
 */
#[IsGranted(BillingPermissions::VIEW)]
final class BudgetPdfController extends AbstractController
{
    public function __construct(
        private readonly BudgetRepository $budgets,
        private readonly BudgetLetter $letter,
    ) {
    }

    /** Die beschlossene Fassung. */
    #[Route('/billing/budgetplaene/{id}/pdf', name: 'app_billing_budget_pdf', methods: ['GET'])]
    public function download(string $id): Response
    {
        $budget = $this->required($id);
        $day = $budget->stage()->day();

        if (null === $day) {
            throw $this->createNotFoundException('Aus einem Entwurf entsteht keine beschlossene Fassung.');
        }

        return $this->archive($budget, $day, isProposal: false, name: 'budgetplan');
    }

    /**
     * Die Beschlussvorlage, wie sie herausgegeben wurde.
     *
     * Aus den eingefrorenen Schreiben und nicht neu gerechnet: sonst koennte
     * jede spaetere Aenderung das Blatt veraendern, und „herausgegeben am 30.
     * Oktober" stuende ueber Zahlen, die an diesem Tag niemand gesehen hat.
     */
    #[Route('/billing/budgetplaene/{id}/vorlage', name: 'app_billing_budget_proposal_pdf', methods: ['GET'])]
    public function proposal(string $id): Response
    {
        $budget = $this->required($id);
        $day = $budget->stage()->proposedOn();

        if (!$budget->stage()->isOpen() || null === $day) {
            throw $this->createNotFoundException('Für diesen Budgetplan liegt keine Beschlussvorlage vor.');
        }

        return $this->archive($budget, $day, isProposal: true, name: 'beschlussvorlage');
    }

    private function archive(Budget $budget, DateTimeImmutable $day, bool $isProposal, string $name): Response
    {
        $response = new Response($this->bundle($budget, $day, $isProposal));
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                self::nameOf($budget, $name),
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
    private function bundle(Budget $budget, DateTimeImmutable $day, bool $isProposal): string
    {
        $letters = [];

        foreach ($budget->documents() as $document) {
            $letters[self::fileOf($document)] = $this->letter->of($budget, $document, $day, $isProposal);
        }

        return PdfArchive::of($letters, $day);
    }

    private function required(string $id): Budget
    {
        return $this->budgets->byId($id)
            ?? throw $this->createNotFoundException('Diesen Budgetplan gibt es nicht.');
    }

    private static function nameOf(Budget $budget, string $name): string
    {
        return \sprintf(
            '%s-%d-%d-%d.zip',
            $name,
            $budget->propertyNumber(),
            $budget->measure()->firstYear(),
            $budget->edition()->number(),
        );
    }

    /** Die Referenz als Dateiname — der Schraegstrich waere ein Verzeichnis. */
    private static function fileOf(BudgetDocument $document): string
    {
        return str_replace('/', '-', $document->reference()->toString()).'.pdf';
    }
}
