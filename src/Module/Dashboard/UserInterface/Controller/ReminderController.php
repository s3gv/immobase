<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dashboard\UserInterface\Controller;

use App\Module\Dashboard\Application\MyReminders;
use App\Shared\Http\LocalUrl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Erinnerungen anlegen und wegnehmen.
 *
 * **Ohne eigenes Recht.** Eine Erinnerung ist die Notiz eines Menschen an
 * sich selbst — wie „Mein Konto". Ein Recht darauf waere eines, das jedes
 * Konto haette, und ein Recht, das alle haben, ist keines.
 *
 * Zurueck geht es dorthin, wo der Knopf stand: die Klappe haengt in der
 * Kopfzeile und damit auf jeder Seite.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ReminderController extends AbstractController
{
    public function __construct(private readonly MyReminders $reminders)
    {
    }

    #[Route('/erinnerungen', name: 'app_reminder_add', methods: ['POST'])]
    public function add(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('reminder', $request->request->getString('_token'))) {
            $this->addFlash('error', 'reminder.error.token');

            return $this->back($request);
        }

        $objection = $this->reminders->add(
            $request->request->getString('subject'),
            $request->request->getString('note'),
            $request->request->getString('day'),
            $request->request->getString('time'),
        );

        $this->addFlash(null === $objection ? 'success' : 'error', $objection ?? 'reminder.added');

        return $this->back($request);
    }

    #[Route('/erinnerungen/{id}/loeschen', name: 'app_reminder_remove', methods: ['POST'])]
    public function remove(string $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('reminder', $request->request->getString('_token'))) {
            $this->addFlash('error', 'reminder.error.token');

            return $this->back($request);
        }

        // Einmal fragen und die Antwort merken: zweimal aufgerufen loescht der
        // erste, und der zweite meldete „gibt es nicht".
        $gone = $this->reminders->remove($id);

        $this->addFlash($gone ? 'success' : 'error', $gone ? 'reminder.removed' : 'reminder.error.gone');

        return $this->back($request);
    }

    /**
     * Zurueck auf die Seite, von der der Knopf kam — sonst auf die Uebersicht.
     *
     * Geprueft wird die Herkunft nicht: gesendet wird nur der Pfad des
     * eigenen Formulars, und alles andere faellt auf die Uebersicht zurueck.
     */
    private function back(Request $request): Response
    {
        return $this->redirect(LocalUrl::orNull($request->request->getString('from')) ?? $this->generateUrl('app_dashboard'));
    }
}
