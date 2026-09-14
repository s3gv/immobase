<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\UserInterface\Controller;

use App\Module\Dunning\Application\ComposeNotice;
use App\Module\Dunning\Application\StartClaim;
use App\Module\Dunning\Application\SurveyOverdue;
use App\Module\Dunning\Domain\ClaimRepository;
use App\Module\Dunning\Domain\CreditorIdentity;
use App\Module\Dunning\Domain\CreditorsDoNotMatch;
use App\Module\Dunning\Domain\DunningPermissions;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Wo ein Mahnschreiben anfaengt.
 *
 * Nicht mit einem leeren Formular, sondern **an einer Forderung** — entweder
 * an einer, die schon verfolgt wird, oder an einer ueberfaelligen Zahlung,
 * die dabei erst zur Forderung wird. Wer auf „Mahnen" klickt, will mahnen und
 * nicht suchen.
 *
 * Eigener Controller neben dem Ablauf: das Anfangen ist eine andere Frage als
 * das Ausfuellen, und es hat zwei Wege.
 */
#[IsGranted(DunningPermissions::EDIT)]
final class NoticeStartController extends AbstractController
{
    public function __construct(
        private readonly RequireClaim $claim,
        private readonly ComposeNotice $compose,
        private readonly ClaimRepository $claims,
        private readonly StartClaim $start,
        private readonly SurveyOverdue $overdue,
    ) {
    }

    /** Mahnen ab einer Forderung, die schon verfolgt wird. */
    #[Route(
        '/finanzen/mahnwesen/{id}/mahnen',
        name: 'app_dunning_notice_start',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function start(string $id, Request $request): Response
    {
        $claim = ($this->claim)($id);
        $this->guard($request);

        return $this->draftFor($claim->debtor()->partyId(), $claim->source()->creditorIdentity());
    }

    /**
     * Mahnen ab einer ueberfaelligen Zahlung.
     *
     * Hier entsteht die Forderung — und mit ihr alle anderen ueberfaelligen
     * desselben Schuldners beim selben Glaeubiger im selben Objekt. Eine
     * Frist, ein Schreiben: wer nur eine davon aufnaehme, schriebe morgen
     * noch einmal.
     */
    #[Route('/finanzen/mahnwesen/mahnen/{paymentId}', name: 'app_dunning_notice_from_payment', methods: ['POST'])]
    public function fromPayment(string $paymentId, Request $request): Response
    {
        $this->guard($request);
        $group = $this->start->theGroupAround($this->overdue->on(new DateTimeImmutable('today')), $paymentId);

        return null === $group
            ? $this->redirectToRoute('app_dunning')
            : $this->draftFor($group['debtorPartyId'], $group['creditor']);
    }

    /**
     * Den Entwurf zu dieser Paarung — oder einen neuen.
     *
     * Vorbelegt mit allen offenen Forderungen desselben Glaeubigers — und der
     * ist vollstaendig gemeint: zwei Glaeubiger sind zwei Schreiben.
     */
    private function draftFor(string $debtorPartyId, CreditorIdentity $creditor): Response
    {
        $claims = $this->claims->openFor($debtorPartyId, $creditor);

        try {
            $notice = $this->compose->draftFor($debtorPartyId, $creditor, $claims, new DateTimeImmutable('today'));
        } catch (CreditorsDoNotMatch $problem) {
            $this->addFlash('error', $problem->getMessage());

            return $this->redirectToRoute('app_dunning');
        }

        return $this->redirectToRoute('app_dunning_notice_edit', [
            'id' => $notice->id(),
            'step' => NoticeFlow::CLAIMS,
        ]);
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('dunning', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }
}
