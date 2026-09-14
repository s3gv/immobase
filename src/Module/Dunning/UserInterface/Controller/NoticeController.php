<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\UserInterface\Controller;

use App\Module\Dunning\Domain\DunningPermissions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Ein ausgestelltes Mahnschreiben ansehen.
 *
 * Im Rahmen des Ablaufs, auf dem letzten Schritt: wer es gerade noch geprueft
 * hat, findet dieselbe Seite wieder — nur ohne Knoepfe.
 */
#[IsGranted(DunningPermissions::VIEW)]
final class NoticeController extends AbstractController
{
    public function __construct(
        private readonly RequireNotice $notice,
        private readonly NoticeFlowPage $flow,
        private readonly NoticeView $view,
        private readonly DunningPage $page,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        '/finanzen/mahnwesen/schreiben/{id}',
        name: 'app_dunning_notice_show',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    public function show(string $id): Response
    {
        $notice = ($this->notice)($id);

        // Der Schritt muss mit: ohne ihn erzeugt die Adresse wieder diese
        // Seite — sie ist die Vorgabe der Ablauf-Route —, und der Browser
        // liefe im Kreis.
        if ($notice->isDraft()) {
            return $this->redirectToRoute('app_dunning_notice_edit', [
                'id' => $notice->id(),
                'step' => NoticeFlow::CLAIMS,
            ]);
        }

        return $this->render('dunning/notice.html.twig', [
            ...$this->flow->frame($notice, NoticeFlow::ISSUE, editable: false),
            ...$this->view->data($notice),
            'title' => $this->translator->trans($notice->level()->labelKey()),
            'explanation' => $this->translator->trans('dunning.issue_hint'),
            'trail' => $this->page->trail(),
        ]);
    }
}
