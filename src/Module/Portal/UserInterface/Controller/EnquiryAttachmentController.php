<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\UserInterface\Controller;

use App\Module\Portal\Domain\AttachmentRepository;
use App\Module\Portal\Domain\PortalPermissions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Eine Datei aus einer Anfrage herunterladen — Verwalterseite.
 *
 * Dieselbe Auslieferung wie im Portal und dieselbe Frist: eine abgelaufene
 * Datei bekommt auch die Verwaltung nicht mehr. Die Zusage gilt in beide
 * Richtungen, sonst waere sie keine.
 *
 * Was sich unterscheidet, ist die Pruefung davor: hier entscheidet das Recht
 * an den Anfragen, im Portal die Partei aus der Sitzung.
 */
#[IsGranted(PortalPermissions::VIEW)]
final class EnquiryAttachmentController extends AbstractController
{
    public function __construct(
        private readonly AttachmentRepository $attachments,
        private readonly ServeAttachment $serve,
    ) {
    }

    #[Route(
        '/anfragen/anhang/{id}',
        name: 'app_enquiry_attachment',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    public function download(string $id): Response
    {
        $attachment = $this->attachments->byId($id)
            ?? throw new NotFoundHttpException('Diese Datei gibt es nicht.');

        return ($this->serve)($attachment);
    }
}
