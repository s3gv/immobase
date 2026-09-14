<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\UserInterface\Controller;

use App\Module\Auth\Contract\UserDirectory;
use App\Module\Party\Contract\PartyDirectory;
use App\Module\Portal\Application\Converse;
use App\Module\Portal\Application\PortalSettings;
use App\Module\Portal\Domain\Attachment;
use App\Module\Portal\Domain\Enquiry;
use App\Module\Portal\Domain\EnquiryRepository;
use App\Module\Portal\Domain\FileVaultIsNotReady;
use App\Module\Portal\Domain\PortalPermissions;
use App\Module\Portal\Domain\TooManyAttachments;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Eine Anfrage bearbeiten: Gespraech, Anhaenge, Aenderung.
 *
 * **Oeffnen heisst gelesen.** Der Zaehler am Menuepunkt zaehlt, was noch
 * niemand angesehen hat — wer die Anfrage aufmacht, hat sie angesehen.
 *
 * Antworten, Zuweisen und Erledigen sind eigene Routen mit eigenem Recht:
 * ansehen darf mehr, wer eingreift, muss es duerfen.
 */
#[IsGranted(PortalPermissions::VIEW)]
final class EnquiryFlowController extends AbstractController
{
    public function __construct(
        private readonly RequireEnquiry $enquiry,
        private readonly Converse $converse,
        private readonly EnquiryRepository $enquiries,
        private readonly EnquiryPage $page,
        private readonly UserDirectory $users,
        private readonly PortalSettings $settings,
        private readonly PartyDirectory $parties,
        private readonly WhoIsAnswering $me,
        private readonly ChangeReview $review,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(
        '/anfragen/{id}/bearbeiten/{step}',
        name: 'app_enquiry_step',
        requirements: ['id' => '[0-9a-fA-F-]{36}', 'step' => 'gespraech|anhaenge|aenderung'],
        methods: ['GET'],
    )]
    public function step(string $id, string $step): Response
    {
        $enquiry = ($this->enquiry)($id);
        $step = EnquiryFlow::known($step);

        $enquiry->readByStaff($this->clock->now());
        $this->enquiries->save($enquiry);

        return $this->render('enquiry/'.$step.'.html.twig', [
            ...$this->page->frame($enquiry, $step, null !== $enquiry->proposal()),
            ...$this->upload(),
            ...$this->review->of($enquiry),
            'enquiry' => $enquiry,
            'asker' => $this->askerOf($enquiry),
            'colleagues' => $this->users->colleagues(),
            'today' => $this->clock->now(),
        ]);
    }

    /**
     * Antworten — und dabei zugewiesen werden, wenn es noch niemand ist.
     *
     * Ohne das stuende am Monatsende die Haelfte ohne Bearbeiter da, obwohl
     * jede bearbeitet wurde.
     */
    #[IsGranted(PortalPermissions::EDIT)]
    #[Route(
        '/anfragen/{id}/antwort',
        name: 'app_enquiry_answer',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function answer(string $id, Request $request): Response
    {
        $enquiry = ($this->enquiry)($id);
        $this->guard($request);
        $body = trim($request->request->getString('body'));
        $files = UploadedFiles::from($request);

        if ('' === $body && [] === $files) {
            $this->addFlash('error', 'portal.error.message_empty');

            return $this->backTo($enquiry);
        }

        try {
            $this->converse->answer($enquiry, ($this->me)(), $body, $files);
        } catch (TooManyAttachments|FileVaultIsNotReady $problem) {
            $this->addFlash('error', $problem->getMessage());
        }

        return $this->backTo($enquiry);
    }

    /** Zuweisen darf jeder mit dem Recht, auch sich selbst. Leer loest die Zuweisung. */
    #[IsGranted(PortalPermissions::EDIT)]
    #[Route(
        '/anfragen/{id}/zuweisen',
        name: 'app_enquiry_assign',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function assign(string $id, Request $request): Response
    {
        $enquiry = ($this->enquiry)($id);
        $this->guard($request);
        $chosen = $request->request->getString('assignee');

        // Eine Kennung, die zu keinem Konto gehoert, loest die Zuweisung
        // statt sie zu setzen: was nicht in der Auswahl stand, ist Eingabe
        // und kein Bearbeiter.
        $known = \array_key_exists($chosen, $this->users->colleagues());
        $enquiry->assignTo($known ? $chosen : null);
        $this->enquiries->save($enquiry);

        // Zwei Meldungen, weil es zwei Dinge sind: „ist zugewiesen" nach dem
        // Lösen der Zuweisung wäre schlicht falsch.
        $this->addFlash('success', $known ? 'enquiry.assigned' : 'enquiry.unassigned');

        return $this->backTo($enquiry);
    }

    /**
     * Erledigt — und das haelt nicht.
     *
     * Schreibt der Fragende wieder, ist die Anfrage offen. Etwas anderes
     * waere eine Tuer, die man von innen zumacht.
     */
    #[IsGranted(PortalPermissions::EDIT)]
    #[Route(
        '/anfragen/{id}/erledigt',
        name: 'app_enquiry_close',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function close(string $id, Request $request): Response
    {
        $enquiry = ($this->enquiry)($id);
        $this->guard($request);
        $enquiry->close();
        $this->enquiries->save($enquiry);
        $this->addFlash('success', 'enquiry.closed');

        return $this->backTo($enquiry);
    }

    private function backTo(Enquiry $enquiry): Response
    {
        return $this->redirectToRoute('app_enquiry_step', [
            'id' => $enquiry->id(),
            'step' => EnquiryFlow::CONVERSATION,
        ]);
    }

    /** Der Name des Fragenden — er steht ueber dem Gespraech. */
    private function askerOf(Enquiry $enquiry): string
    {
        return $this->parties->byIds([$enquiry->partyId()])[$enquiry->partyId()]->displayName ?? '—';
    }

    /**
     * @return array<string, int>
     */
    private function upload(): array
    {
        return [
            'retentionDays' => $this->settings->retentionDays(),
            'maxFiles' => Attachment::MAX_PER_MESSAGE,
            'maxMegabytes' => intdiv(Attachment::MAX_BYTES, 1024 * 1024),
        ];
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('enquiry', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }
}
