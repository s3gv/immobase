<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\UserInterface\Controller;

use App\Module\Auth\Contract\UserDirectory;
use App\Module\Party\Contract\PartyDirectory;
use App\Module\Portal\Application\SurveyEnquiries;
use App\Module\Portal\Domain\Enquiry;
use App\Module\Portal\Domain\EnquiryFilter;
use App\Module\Portal\Domain\PortalPermissions;
use App\Shared\Ui\Page;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Die Uebersicht der Anfragen.
 *
 * Dieselbe Gestalt wie jede andere Uebersicht — oben die Kennzahlen, darunter
 * die gefilterte Liste. Wer eine Uebersicht in ImmoBase kennt, kennt alle.
 *
 * **Jeder mit dem Recht sieht alle.** Die Zuweisung ist Arbeitsteilung und
 * keine Sperre: sie sagt, wer sich kuemmert, und steht nicht im Weg, wenn
 * jemand anders antworten muss.
 */
#[IsGranted(PortalPermissions::VIEW)]
final class EnquiryController extends AbstractController
{
    public function __construct(
        private readonly SurveyEnquiries $survey,
        private readonly PartyDirectory $parties,
        private readonly UserDirectory $users,
        private readonly EnquiryPage $page,
    ) {
    }

    #[Route('/anfragen', name: 'app_enquiry', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filter = EnquiryFilter::of(
            $request->query->getString('zustand'),
            $request->query->getString('bearbeiter'),
            $request->query->getString('q'),
        );
        $page = Page::of($request->query->getInt('page', 1), $this->survey->count($filter));
        $enquiries = $this->survey->page($filter, $page);

        return $this->render('enquiry/index.html.twig', [
            'enquiries' => $enquiries,
            'askers' => $this->askersOf($enquiries),
            'colleagues' => $this->users->colleagues(),
            'tally' => $this->survey->tally(),
            'windowDays' => SurveyEnquiries::windowDays(),
            'page' => $page,
            'filter' => $filter,
            'url' => $this->page->listUrl($request),
            'trail' => $this->page->trail(),
        ]);
    }

    /**
     * Die Namen der Fragenden — eine Abfrage fuer die ganze Seite.
     *
     * Je Zeile eine waeren bei fuenfzig Anfragen fuenfzig Abfragen fuer
     * fuenfzig Namen.
     *
     * @param list<Enquiry> $enquiries
     *
     * @return array<string, string> Kennung der Anfrage auf den Namen
     */
    private function askersOf(array $enquiries): array
    {
        $parties = $this->parties->byIds(array_values(array_unique(array_map(
            static fn (Enquiry $enquiry): string => $enquiry->partyId(),
            $enquiries,
        ))));
        $found = [];

        foreach ($enquiries as $enquiry) {
            $found[$enquiry->id()] = $parties[$enquiry->partyId()]->displayName ?? '—';
        }

        return $found;
    }
}
