<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\UserInterface\Controller;

use App\Module\Portal\Application\PortalIdentity;
use App\Module\Portal\Domain\AttachmentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Eine Datei aus dem eigenen Gespraech herunterladen.
 *
 * **Nie ueber einen oeffentlichen Pfad.** Es gibt keinen Pfad: die Bytes
 * stehen in der Zeile. Was hier geprueft wird, ist die Zugehoerigkeit — der
 * Anhang haengt an einer Nachricht, die Nachricht an einer Anfrage, und die
 * Anfrage muss der Partei aus der Sitzung gehoeren. Erst danach wird
 * entsiegelt.
 *
 * Eigene Klasse und nicht eine Methode mehr bei den Anfragen: dort geht es um
 * Gespraeche, hier um eine Datei, und die beiden brauchen verschiedene
 * Dienste.
 */
final class PortalAttachmentController extends AbstractController
{
    public function __construct(
        private readonly AttachmentRepository $attachments,
        private readonly PortalIdentity $who,
        private readonly ServeAttachment $serve,
    ) {
    }

    #[Route(
        '/portal/anhang/{id}',
        name: 'app_portal_attachment',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    public function download(string $id): Response
    {
        $attachment = $this->attachments->byId($id);

        // Nicht vorhanden und nicht meins sind hier dieselbe Antwort: wer
        // Kennungen durchprobiert, soll aus der Antwort nicht schliessen
        // koennen, welche es gibt.
        if (null === $attachment || !$this->who->owns($attachment->message()->enquiry()->partyId())) {
            throw new NotFoundHttpException('Diese Datei gibt es nicht.');
        }

        return ($this->serve)($attachment);
    }
}
