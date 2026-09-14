<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\UserInterface\Controller;

use App\Module\Auth\Contract\PortalAccounts;
use App\Module\Party\Application\CollectPartyBelongings;
use App\Module\Party\Application\CountPartyLinks;
use App\Module\Party\Domain\Party;
use App\Module\Party\Domain\PartyFilter;
use App\Module\Party\Domain\PartyPermissions;
use App\Module\Party\Domain\PartyRepository;
use App\Module\Party\Domain\PartyRole;
use App\Shared\Http\FormInput;
use App\Shared\Ui\Page;
use App\Shared\Ui\Past;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Die Stammdaten: Mieter, Eigentuemer und sonstige Kontakte.
 */
#[IsGranted(PartyPermissions::VIEW)]
final class PartyController extends AbstractController
{
    public function __construct(
        private readonly PartyRepository $parties,
        private readonly RequireParty $party,
        private readonly CountPartyLinks $links,
        private readonly CollectPartyBelongings $belongings,
        private readonly PortalAccounts $accounts,
        private readonly PartyPage $page,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/stammdaten', name: 'app_party', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $past = Past::from($request);
        $filter = PartyFilter::of(
            $request->query->getString('role'),
            $request->query->getString('q'),
            $past->shown,
        );

        $page = Page::of(FormInput::queryIntOrNull($request, 'page') ?? 1, $this->parties->countMatching($filter));

        $parties = $this->parties->matching($filter, $page);

        return $this->render('party/index.html.twig', [
            'parties' => $parties,
            'links' => $this->linksFor($parties),
            'page' => $page,
            'filter' => $filter,
            'roles' => PartyRole::cases(),
            'past' => $past,
            'url' => $this->listUrl($request),
            'trail' => [
                ['label' => 'ImmoBase', 'url' => $this->generateUrl('app_dashboard')],
                ['label' => $this->translator->trans('party.heading'), 'url' => null],
            ],
        ]);
    }

    #[Route('/stammdaten/{reference}', name: 'app_party_show', requirements: ['reference' => '\d+'], methods: ['GET'])]
    public function show(int $reference, Request $request): Response
    {
        $party = ($this->party)($reference);
        $section = PartyPage::known($request->query->getString('abschnitt'));

        return $this->render('party/detail/'.$section.'.html.twig', [
            ...$this->page->sections($party, $section),
            'party' => $party,
            'links' => $this->links->forParty($party->id()),
            'portalAccount' => $this->accounts->forParty($party->id()),
            'belongings' => $this->belongings->forParty($party->id()),
            'trail' => [
                ['label' => 'ImmoBase', 'url' => $this->generateUrl('app_dashboard')],
                ['label' => $this->translator->trans('party.heading'), 'url' => $this->generateUrl('app_party')],
                ['label' => $party->displayName(), 'url' => null],
            ],
        ]);
    }

    #[IsGranted(PartyPermissions::DELETE)]
    #[Route('/stammdaten/{reference}/loeschen', name: 'app_party_delete', requirements: ['reference' => '\d+'], methods: ['POST'])]
    public function delete(int $reference, Request $request): Response
    {
        $party = ($this->party)($reference);

        if (!$this->isCsrfTokenValid('party_delete_'.$reference, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }

        // Auch wenn die Oberfläche den Knopf abschaltet: die Prüfung gehört
        // hierher. Ein abgeschalteter Knopf hält niemanden auf, der die
        // Adresse kennt.
        if ($this->links->anyFor($party->id())) {
            return $this->stillLinked($reference);
        }

        if (!$this->removed($party)) {
            return $this->stillLinked($reference);
        }

        $this->addFlash('success', 'party.deleted');

        return $this->redirectToRoute('app_party');
    }

    // Archivieren ist bearbeiten, nicht loeschen: es entfernt nichts, es
    // raeumt aus dem Weg.
    #[IsGranted(PartyPermissions::EDIT)]
    #[Route('/stammdaten/{reference}/archivieren', name: 'app_party_archive', requirements: ['reference' => '\\d+'], methods: ['POST'])]
    public function archive(int $reference, Request $request): Response
    {
        $party = ($this->party)($reference);

        if (!$this->isCsrfTokenValid('party_archive_'.$reference, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }

        $archived = !$party->isArchived();
        $archived ? $party->archive() : $party->reactivate();
        $this->parties->save($party);
        $this->addFlash('success', $archived ? 'party.archived' : 'party.reactivated');

        return $this->redirectToRoute('app_party_show', ['reference' => $reference]);
    }

    /**
     * Zwischen der Pruefung und dem Loeschen passt eine gleichzeitige
     * Zuordnung: property_unit_owner.party_id verweist auf party, und die
     * Datenbank faengt sie ab. Hier wird daraus dieselbe Absage wie oben,
     * statt eines Fehlers mitten auf der Seite.
     */
    /**
     * Erst das Zugehoerige, dann die Partei — und beides zusammen oder gar
     * nicht.
     *
     * Braeche der zweite Schritt ab, nachdem der erste lief, stuende eine
     * Partei ohne ihre Gespraeche da, und niemand wuesste, dass es sie gab.
     */
    private function removed(Party $party): bool
    {
        try {
            $this->parties->atomically(function () use ($party): void {
                $this->belongings->discardFor($party->id());
                $this->parties->remove($party);
            });
        } catch (ForeignKeyConstraintViolationException) {
            return false;
        }

        return true;
    }

    private function stillLinked(int $reference): Response
    {
        $this->addFlash('error', 'party.linked');

        return $this->redirectToRoute('app_party_show', ['reference' => $reference]);
    }

    /**
     * Verknuepfungen fuer eine ganze Seite auf einmal — eine Abfrage je Zeile
     * waere genau das, was die Schnittstelle vermeiden soll.
     *
     * @param list<Party> $parties
     *
     * @return array<string, list<string>>
     */
    private function linksFor(array $parties): array
    {
        return $this->links->forParties(array_map(
            static fn (Party $party): string => $party->id(),
            $parties,
        ));
    }

    /**
     * Die Adresse der Liste mit __PAGE__ fuer die Seitenzahl.
     *
     * Die Filter bleiben dabei erhalten: wer auf Seite zwei blaettert, will
     * dieselbe Liste weiterlesen und nicht eine andere.
     */
    private function listUrl(Request $request): string
    {
        $parameters = array_filter([
            'role' => $request->query->getString('role'),
            'q' => $request->query->getString('q'),
            'status' => $request->query->getString('status'),
        ], static fn (string $value): bool => '' !== $value);

        $parameters['page'] = '__PAGE__';

        return $this->generateUrl('app_party').'?'.http_build_query($parameters);
    }
}
