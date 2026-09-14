<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\UserInterface\Controller;

use App\Module\Dunning\Application\ComposeNotice;
use App\Module\Dunning\Application\IssueNotice;
use App\Module\Dunning\Domain\DunningPermissions;
use App\Module\Dunning\Domain\LevelOutOfOrder;
use App\Module\Dunning\Domain\Notice;
use App\Module\Dunning\Domain\NoticeIsIncomplete;
use App\Module\Dunning\Domain\NoticeIsIssued;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Der Ablauf: ein Mahnschreiben zusammenstellen und ausstellen.
 *
 * Angefangen wird nicht mit einem leeren Formular, sondern **an einer
 * Forderung** — entweder an einer, die schon verfolgt wird, oder an einer
 * ueberfaelligen Zahlung, die dabei erst zur Forderung wird. Wer auf „Mahnen"
 * klickt, will mahnen und nicht suchen.
 */
#[IsGranted(DunningPermissions::EDIT)]
final class NoticeFlowController extends AbstractController
{
    public function __construct(
        private readonly RequireNotice $notice,
        private readonly ComposeNotice $compose,
        private readonly NoticeStepInput $input,
        private readonly NoticeFlowPage $page,
        private readonly NoticeView $view,
        private readonly IssueNotice $issue,
    ) {
    }

    // Zwei Dinge stehen hier ausdruecklich da:
    //
    // Die Schritte als Aufzaehlung und nicht als `[a-z]+` — sonst schluckt
    // diese Route auch „ausstellen" und „verwerfen", und der Knopf landete
    // stumm wieder auf dem ersten Schritt.
    //
    // Und **keine Vorgabe** fuer den Schritt: der Adressgenerator laesst
    // einen Wert weg, der gleich der Vorgabe ist, und die Adresse ohne
    // Schritt gehoert der Ansicht. Ein Entwurf schickte den Browser dann im
    // Kreis.
    #[Route(
        '/finanzen/mahnwesen/schreiben/{id}/{step}',
        name: 'app_dunning_notice_edit',
        requirements: ['id' => '[0-9a-fA-F-]{36}', 'step' => 'forderungen|schreiben|ausstellung'],
        methods: ['GET', 'POST'],
    )]
    public function edit(string $id, string $step, Request $request): Response
    {
        $notice = ($this->notice)($id);

        if (!$notice->isDraft()) {
            return $this->redirectToRoute('app_dunning_notice_show', ['id' => $notice->id()]);
        }

        $current = NoticeFlow::known($step);

        if (!$request->isMethod('POST')) {
            return $this->recomputed($notice, $current, $request);
        }

        $this->guard($request);
        $errors = $this->input->apply($current, $request, $notice);

        return [] === $errors
            ? $this->onwards($notice, $current, $request)
            : $this->show($notice, $current, $request, $errors);
    }

    /**
     * Ausstellen — unumkehrbar.
     *
     * Danach liegt das Schreiben beim Schuldner und die Frist laeuft.
     */
    #[Route(
        '/finanzen/mahnwesen/schreiben/{id}/ausstellen',
        name: 'app_dunning_notice_issue',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function issue(string $id, Request $request): Response
    {
        $notice = ($this->notice)($id);
        $this->guard($request);

        try {
            $this->issue->issue($notice, new DateTimeImmutable('today'));
            $this->addFlash('success', 'dunning.issued');
        } catch (NoticeIsIssued|NoticeIsIncomplete|LevelOutOfOrder $problem) {
            $this->addFlash('error', $problem->getMessage());
        }

        return $this->redirectToRoute('app_dunning_notice_show', ['id' => $notice->id()]);
    }

    /** Ein Entwurf ist nie hinausgegangen — er verschwindet. */
    #[Route(
        '/finanzen/mahnwesen/schreiben/{id}/verwerfen',
        name: 'app_dunning_notice_discard',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function discard(string $id, Request $request): Response
    {
        $notice = ($this->notice)($id);
        $this->guard($request);

        if ($notice->isDraft()) {
            $this->compose->discard($notice);
        }

        return $this->redirectToRoute('app_dunning');
    }

    /**
     * Beim Ansehen neu rechnen.
     *
     * Die Zinsen wachsen taeglich, und der offene Betrag kommt aus den
     * Finanzen. Ein Entwurf, den man nach einer Woche wieder oeffnet, zeigt
     * darum die Zahlen von heute — nicht die von damals.
     */
    private function recomputed(Notice $notice, string $step, Request $request): Response
    {
        $this->compose->refresh($notice, new DateTimeImmutable('today'));

        return $this->show($notice, $step, $request);
    }

    private function onwards(Notice $notice, string $step, Request $request): Response
    {
        if ('back' === $request->request->getString('direction')) {
            return $this->toStep($notice, NoticeFlow::previous($step) ?? NoticeFlow::CLAIMS);
        }

        $next = NoticeFlow::next($step);

        if (null !== $next) {
            return $this->toStep($notice, $next);
        }

        $this->addFlash('success', 'dunning.saved');

        return $this->redirectToRoute('app_dunning_notice_show', ['id' => $notice->id()]);
    }

    private function toStep(Notice $notice, string $step): Response
    {
        return $this->redirectToRoute('app_dunning_notice_edit', ['id' => $notice->id(), 'step' => $step]);
    }

    /**
     * @param array<string, string> $errors
     */
    private function show(Notice $notice, string $step, Request $request, array $errors = []): Response
    {
        return $this->render('dunning/notice/'.$step.'.html.twig', [
            ...$this->page->frame($notice, $step),
            ...$this->view->data($notice),
            'alsoOpen' => $this->view->alsoOpen($notice),
            'errors' => $errors,
            'submitted' => [] === $errors ? [] : $request->request->all(),
        ]);
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('dunning', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }
}
