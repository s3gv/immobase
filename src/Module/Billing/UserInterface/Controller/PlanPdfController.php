<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

use App\Module\Billing\Domain\BillingPermissions;
use App\Module\Billing\Domain\Plan;
use App\Module\Billing\Domain\PlanDocument;
use App\Module\Billing\Domain\PlannedDocument;
use App\Module\Billing\Domain\PlanReference;
use App\Module\Billing\Domain\PlanRepository;
use App\Module\Billing\UserInterface\Pdf\PlanLetter;
use App\Shared\Pdf\PdfArchive;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use ZipArchive;

/**
 * Die Einzelwirtschaftsplaene als PDF, gebuendelt.
 *
 * Zwei Wege zum selben Blatt, und beide braucht es. Die **Vorlage** geht vor
 * der Versammlung heraus und rechnet aus dem heutigen Stand — ein Plan, der
 * erst mit dem Beschluss auf Papier erschiene, koennte gar nicht beschlossen
 * werden. Die **beschlossene Fassung** entsteht aus den eingefrorenen
 * Schreiben und bleibt Jahr fuer Jahr dieselbe Datei.
 *
 * Ein ZIP und keine Einzeldatei: wer plant, verschickt ein Objekt und nicht
 * eine Wohnung.
 */
#[IsGranted(BillingPermissions::VIEW)]
final class PlanPdfController extends AbstractController
{
    public function __construct(
        private readonly PlanRepository $plans,
        private readonly PlanLetter $letter,
    ) {
    }

    /** Die beschlossene Fassung. */
    #[Route('/billing/wirtschaftsplaene/{id}/pdf', name: 'app_billing_plan_pdf', methods: ['GET'])]
    public function download(string $id): Response
    {
        $plan = $this->required($id);

        if ($plan->stage()->isOpen()) {
            throw $this->createNotFoundException('Aus einem Entwurf entsteht keine beschlossene Fassung.');
        }

        $day = $plan->stage()->day() ?? new DateTimeImmutable('today');

        return $this->archive($plan, self::lettersOf($plan), $day, isProposal: false, name: 'wirtschaftsplan');
    }

    /**
     * Die Beschlussvorlage, wie sie herausgegeben wurde.
     *
     * Aus den eingefrorenen Schreiben und **nicht** neu gerechnet. Sonst
     * koennte jede spaetere Aenderung an der Grundlage das Blatt veraendern —
     * ein berichtigter Miteigentumsanteil, ein nachgetragener Verbrauch, eine
     * neue Anschrift —, und „herausgegeben am 30. Oktober" stuende ueber
     * Zahlen, die an diesem Tag niemand gesehen hat.
     *
     * Damit ist auch sie byteweise wiederholbar: derselbe Tag, dieselben
     * Zahlen, dieselbe Datei.
     */
    #[Route('/billing/wirtschaftsplaene/{id}/vorlage', name: 'app_billing_plan_proposal_pdf', methods: ['GET'])]
    public function proposal(string $id): Response
    {
        $plan = $this->required($id);
        $day = $plan->stage()->proposedOn();

        if (!$plan->stage()->isOpen() || null === $day) {
            throw $this->createNotFoundException('Für diesen Wirtschaftsplan liegt keine Beschlussvorlage vor.');
        }

        return $this->archive($plan, self::lettersOf($plan), $day, isProposal: true, name: 'beschlussvorlage');
    }

    /**
     * Die eingefrorenen Schreiben, in der Form, aus der ein Blatt entsteht.
     *
     * @return list<PlannedDocument>
     */
    private static function lettersOf(Plan $plan): array
    {
        return array_map(
            static fn (PlanDocument $document): PlannedDocument => $document->asPlanned(),
            $plan->documents(),
        );
    }

    /**
     * @param list<PlannedDocument> $letters
     */
    private function archive(
        Plan $plan,
        array $letters,
        DateTimeImmutable $day,
        bool $isProposal,
        string $name,
    ): Response {
        $response = new Response($this->bundle($plan, $letters, $day, $isProposal));
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                self::nameOf($plan, $name),
            ),
        );

        return $response;
    }

    /**
     * Alle Schreiben in einem Archiv.
     *
     * ZipArchive braucht eine Datei; sie wird angelegt, gefuellt, gelesen und
     * weggeraeumt.
     *
     * @param list<PlannedDocument> $letters
     */
    private function bundle(Plan $plan, array $letters, DateTimeImmutable $day, bool $isProposal): string
    {
        $files = [];

        foreach ($letters as $letter) {
            $files[self::fileOf($plan, $letter)] = $this->letter->of($plan, $letter, $day, $isProposal);
        }

        return PdfArchive::of($files, $day);
    }

    private function required(string $id): Plan
    {
        return $this->plans->byId($id)
            ?? throw $this->createNotFoundException('Diesen Wirtschaftsplan gibt es nicht.');
    }

    private static function nameOf(Plan $plan, string $name): string
    {
        return \sprintf(
            '%s-%d-%d-%d.zip',
            $name,
            $plan->propertyNumber(),
            $plan->period()->year(),
            $plan->edition()->number(),
        );
    }

    /**
     * Die Referenz als Dateiname.
     *
     * Anders als bei der Abrechnung genuegt sie: je Einheit gibt es genau ein
     * Schreiben, und die Referenz nennt die Einheit. Der Schraegstrich muss
     * weichen — er waere ein Verzeichnis.
     */
    private static function fileOf(Plan $plan, PlannedDocument $document): string
    {
        return str_replace('/', '-', PlanReference::of($plan, $document->unitNumber)->toString()).'.pdf';
    }
}
