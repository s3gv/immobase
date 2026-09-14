<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\UserInterface\Controller;

use App\Module\Dunning\Application\CourtSummary;
use App\Module\Dunning\Domain\DunningPermissions;
use App\Module\Dunning\UserInterface\Pdf\CourtSummaryLetter;
use App\Module\Dunning\UserInterface\Pdf\NoticeLetter;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Die beiden Blaetter des Mahnwesens.
 *
 * **Das Mahnschreiben** geht an einen Schuldner — ein Empfaenger, ein Blatt,
 * darum kein Archiv. **Die Zusammenfassung** geht an niemanden: sie ist das,
 * was man dem Anwalt oder dem Amtsgericht mitgibt.
 *
 * Nur ausgestellte Schreiben: ein Entwurf traegt keine Nummer, die gilt, und
 * ein Blatt mit einer Nummer ist im Umlauf, sobald es jemand ausgedruckt hat.
 */
#[IsGranted(DunningPermissions::VIEW)]
final class NoticePdfController extends AbstractController
{
    public function __construct(
        private readonly RequireNotice $notice,
        private readonly RequireClaim $claim,
        private readonly CourtSummary $summary,
        private readonly NoticeLetter $letter,
        private readonly CourtSummaryLetter $court,
    ) {
    }

    #[Route(
        '/finanzen/mahnwesen/schreiben/{id}/pdf',
        name: 'app_dunning_notice_pdf',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    public function notice(string $id): Response
    {
        $notice = ($this->notice)($id);
        $issued = $notice->issuedOn();

        if (null === $issued) {
            throw $this->createNotFoundException('Aus einem Entwurf entsteht kein Schreiben.');
        }

        // Das Konto vom Blatt und nicht aus dem heutigen Objektstamm: nach
        // einem Bankwechsel zeigte derselbe Beleg sonst eine
        // Zahlungsanweisung, die so nie hinausging.
        return $this->fileFrom(
            $this->letter->of($notice, $notice->recipients()->payeeIban(), $issued),
            $notice->reference().'.pdf',
        );
    }

    #[Route(
        '/finanzen/mahnwesen/{id}/mahnverfahren',
        name: 'app_dunning_court_pdf',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    public function court(string $id): Response
    {
        $claim = ($this->claim)($id);
        $today = new DateTimeImmutable('today');

        return $this->fileFrom(
            $this->court->of($this->summary->of($claim, $today), $today),
            'mahnverfahren-'.$today->format('Y-m-d').'.pdf',
        );
    }

    private function fileFrom(string $bytes, string $name): Response
    {
        $response = new Response($bytes);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name),
        );

        return $response;
    }
}
