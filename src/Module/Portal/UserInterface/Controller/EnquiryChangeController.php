<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\UserInterface\Controller;

use App\Module\Portal\Application\DecideAChange;
use App\Module\Portal\Domain\Enquiry;
use App\Module\Portal\Domain\PortalPermissions;
use App\Module\Portal\Domain\Proposal;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Ueber einen Vorschlag entscheiden: uebernehmen oder begruendet ablehnen.
 *
 * **Ablehnen ohne Begruendung geht nicht.** Eine Ablehnung ohne Grund ist
 * eine Zumutung — und die Begruendung geht als Nachricht ins Gespraech, damit
 * der Absender sie dort liest, wo er gefragt hat.
 *
 * **Einmal entscheidbar.** Ein zweites Uebernehmen schriebe den Stand von
 * damals ueber den von heute.
 */
#[IsGranted(PortalPermissions::EDIT)]
final class EnquiryChangeController extends AbstractController
{
    private const array WHERE = ['id' => '[0-9a-fA-F-]{36}'];

    public function __construct(
        private readonly RequireEnquiry $enquiry,
        private readonly DecideAChange $decisions,
        private readonly WhoIsAnswering $me,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/anfragen/{id}/uebernehmen', name: 'app_enquiry_accept', requirements: self::WHERE, methods: ['POST'])]
    public function accept(string $id, Request $request): Response
    {
        $enquiry = ($this->enquiry)($id);
        $this->guard($request);

        $objections = $this->decisions->accept(
            self::openProposalOf($enquiry),
            ($this->me)(),
            $this->translator->trans('change.accepted_message'),
        );

        // Die Einwaende kommen vom besitzenden Modul — oder daher, dass jemand
        // schneller war. Sie stehen als Meldung da und werden nicht
        // stillschweigend uebergangen: uebernommen wurde nichts.
        foreach ($objections as $objection) {
            $this->addFlash('error', $objection);
        }

        if ([] === $objections) {
            $this->addFlash('success', 'change.accepted');
        }

        return $this->backTo($enquiry);
    }

    #[Route('/anfragen/{id}/ablehnen', name: 'app_enquiry_reject', requirements: self::WHERE, methods: ['POST'])]
    public function reject(string $id, Request $request): Response
    {
        $enquiry = ($this->enquiry)($id);
        $this->guard($request);
        $reason = trim($request->request->getString('reason'));

        if ('' === $reason) {
            return $this->refused($enquiry, 'change.error.reason_required');
        }

        if (!$this->decisions->reject(self::openProposalOf($enquiry), ($this->me)(), $reason)) {
            return $this->refused($enquiry, 'change.error.decided');
        }

        $this->addFlash('success', 'change.rejected');

        return $this->backTo($enquiry);
    }

    private static function openProposalOf(Enquiry $enquiry): Proposal
    {
        return $enquiry->proposal() ?? throw new NotFoundHttpException('Zu dieser Anfrage gehört kein Vorschlag.');
    }

    private function refused(Enquiry $enquiry, string $message): Response
    {
        $this->addFlash('error', $message);

        return $this->backTo($enquiry);
    }

    private function backTo(Enquiry $enquiry): Response
    {
        return $this->redirectToRoute('app_enquiry_step', [
            'id' => $enquiry->id(),
            'step' => EnquiryFlow::CHANGE,
        ]);
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('enquiry', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }
}
