<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\UserInterface\Controller;

use App\Module\Portal\Application\Converse;
use App\Module\Portal\Application\MyData;
use App\Module\Portal\Application\MyEnquiries;
use App\Module\Portal\Application\PortalSettings;
use App\Module\Portal\Domain\Attachment;
use App\Module\Portal\Domain\EnquiryRepository;
use App\Module\Portal\Domain\FileVaultIsNotReady;
use App\Module\Portal\Domain\TooManyAttachments;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * Die Anfragen im Portal: Liste, neue Anfrage, Gespraech.
 *
 * **Keine Kennung wird geglaubt.** Jede Anfrage aus der Adresszeile geht
 * durch {@see MyEnquiries}, und die haelt sie gegen die Partei aus der
 * Sitzung, bevor irgendetwas geladen wird. Eine fremde Anfrage gibt es fuer
 * dieses Konto nicht — nicht „verboten", sondern nicht vorhanden.
 *
 * **Gelesen wird beim Oeffnen**, und damit faellt die Benachrichtigung weg:
 * wer liest, braucht keine Mail mehr darueber, dass es etwas zu lesen gibt.
 */
final class PortalEnquiryController extends AbstractController
{
    public function __construct(
        private readonly MyEnquiries $mine,
        private readonly MyData $data,
        private readonly Converse $converse,
        private readonly EnquiryRepository $enquiries,
        private readonly PortalPage $page,
        private readonly PortalSettings $settings,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/portal/anfragen', name: 'app_portal_enquiries', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('portal/anfragen.html.twig', [
            ...$this->frame(),
            'enquiries' => $this->mine->all(),
        ]);
    }

    #[Route('/portal/anfragen/neu', name: 'app_portal_enquiry_new', methods: ['GET'])]
    public function form(Request $request): Response
    {
        return $this->render('portal/anfrage_neu.html.twig', [
            ...$this->frame($this->translator->trans('portal.enquiry.new')),
            ...$this->upload(),
            'subject' => $request->query->getString('betreff'),
            'body' => '',
        ]);
    }

    /** Eine neue Anfrage — Betreff, Nachricht, Dateien. Drei Felder. */
    #[Route('/portal/anfragen/neu', name: 'app_portal_enquiry_ask', methods: ['POST'])]
    public function ask(Request $request): Response
    {
        $this->guard($request);
        $subject = trim($request->request->getString('subject'));
        $body = trim($request->request->getString('body'));
        $back = $this->generateUrl('app_portal_enquiry_new', ['betreff' => $subject]);

        if ('' === $subject || '' === $body) {
            $this->addFlash('error', 'portal.error.enquiry_incomplete');

            return $this->redirect($back);
        }

        try {
            $enquiry = $this->converse->ask(
                $this->mine->partyId(),
                $this->whoAmI(),
                $subject,
                $body,
                UploadedFiles::from($request),
            );
        } catch (TooManyAttachments|FileVaultIsNotReady $problem) {
            return $this->refused($problem, $back);
        }

        $this->addFlash('success', 'portal.enquiry.asked');

        return $this->redirectToRoute('app_portal_enquiry', ['id' => $enquiry->id()]);
    }

    #[Route(
        '/portal/anfragen/{id}',
        name: 'app_portal_enquiry',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    public function show(string $id): Response
    {
        $enquiry = $this->mine->mine($id);

        // Gelesen heisst: der Zyklus faengt von vorn an — ein anstehender
        // Termin verfaellt, und die naechste Nachricht beginnt einen neuen.
        $enquiry->readByParty($this->clock->now());
        $this->enquiries->save($enquiry);

        return $this->render('portal/anfrage.html.twig', [
            ...$this->frame($enquiry->subject()),
            ...$this->upload(),
            'enquiry' => $enquiry,
            'today' => $this->clock->now(),
        ]);
    }

    #[Route(
        '/portal/anfragen/{id}/antwort',
        name: 'app_portal_enquiry_reply',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function reply(string $id, Request $request): Response
    {
        $enquiry = $this->mine->mine($id);
        $this->guard($request);
        $back = $this->generateUrl('app_portal_enquiry', ['id' => $enquiry->id()]);
        $body = trim($request->request->getString('body'));
        $files = UploadedFiles::from($request);

        if ('' === $body && [] === $files) {
            $this->addFlash('error', 'portal.error.message_empty');

            return $this->redirect($back);
        }

        try {
            $this->converse->replyFromThePortal($enquiry, $this->whoAmI(), $body, $files);
        } catch (TooManyAttachments|FileVaultIsNotReady $problem) {
            return $this->refused($problem, $back);
        }

        return $this->redirect($back);
    }

    /**
     * Der Name, der an einer Nachricht aus dem Portal steht.
     *
     * Die Partei und nicht das Konto: wer fragt, ist der Mensch in den
     * Stammdaten, und unter dessen Namen kennt ihn die Verwaltung.
     */
    private function whoAmI(): string
    {
        $party = $this->data->party();

        return null === $party ? '' : $party->displayName;
    }

    /**
     * @return array<string, mixed>
     */
    private function frame(?string $title = null): array
    {
        return $this->page->frame(PortalFlow::ENQUIRIES, $this->whoAmI(), $title);
    }

    /**
     * Was ueber der Ablageflaeche steht — die Grenzen und die Frist.
     *
     * **Vor der Flaeche und nicht darunter:** wer erst danach liest, wie
     * lange seine Datei bleibt, hat sie schon abgelegt.
     *
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

    private function refused(Throwable $problem, string $back): Response
    {
        $this->addFlash('error', $problem->getMessage());

        return $this->redirect($back);
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('portal_enquiry', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }
}
