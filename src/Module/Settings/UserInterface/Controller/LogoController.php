<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Settings\UserInterface\Controller;

use App\Module\Settings\Application\ManageLogo;
use App\Module\Settings\Contract\SettingsPermissions;
use App\Module\Settings\Domain\Logo;
use App\Module\Settings\Domain\LogoRejected;
use App\Module\Settings\Domain\LogoRepository;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Das Logo hochladen, ansehen und entfernen.
 *
 * Ausgeliefert wird es hinter demselben Recht wie die Seite: es ist eine
 * Angabe der Installation und nichts, was oeffentlich herumliegen muss.
 * Billing liest die Bytes spaeter unmittelbar aus dem Repository — ein PDF
 * entsteht auf dem Server.
 */
#[IsGranted(SettingsPermissions::VIEW)]
final class LogoController extends AbstractController
{
    public function __construct(
        private readonly LogoRepository $logos,
        private readonly ManageLogo $manage,
    ) {
    }

    /**
     * Das Bild selbst.
     *
     * Mit ETag aus dem Inhalt: das Logo aendert sich fast nie, und die Seite
     * zeigt es bei jedem Aufruf. Ohne das laege es bei jedem Seitenwechsel
     * wieder auf der Leitung.
     */
    #[Route('/einstellungen/logo', name: 'app_settings_logo', methods: ['GET'])]
    public function show(Request $request): Response
    {
        $logo = $this->logos->current() ?? throw new NotFoundHttpException('Es gibt kein Logo.');
        $bytes = $logo->bytes();

        $response = new Response($bytes, Response::HTTP_OK, ['Content-Type' => Logo::CONTENT_TYPE]);
        $response->setEtag(hash('sha256', $bytes));
        $response->setPrivate();

        // Beantwortet den zweiten Aufruf mit 304 und ohne Inhalt.
        $response->isNotModified($request);

        return $response;
    }

    #[IsGranted(SettingsPermissions::EDIT)]
    #[Route('/einstellungen/logo', name: 'app_settings_logo_upload', methods: ['POST'])]
    public function upload(Request $request): Response
    {
        $this->guard($request);

        try {
            $this->manage->replaceWith(self::bytesFrom($request), new DateTimeImmutable('now'));
            $this->addFlash('success', 'settings.logo.saved');
        } catch (LogoRejected $problem) {
            $this->addFlash('error', $problem->reasonKey);
        }

        return $this->redirectToRoute('app_settings', ['abschnitt' => 'logo']);
    }

    /**
     * Entfernen laeuft unter „bearbeiten".
     *
     * Die Einstellungen kennen kein Loeschrecht, und das ist Absicht: hier
     * verschwindet nichts, was Bestand haette. Ein Logo zu entfernen heisst,
     * die Einstellung „kein Logo" zu setzen — der Briefkopf steht danach
     * ohne, und was gedruckt ist, traegt seines im PDF.
     */
    #[IsGranted(SettingsPermissions::EDIT)]
    #[Route('/einstellungen/logo/entfernen', name: 'app_settings_logo_remove', methods: ['POST'])]
    public function remove(Request $request): Response
    {
        $this->guard($request);
        $this->manage->remove();
        $this->addFlash('success', 'settings.logo.removed');

        return $this->redirectToRoute('app_settings', ['abschnitt' => 'logo']);
    }

    /**
     * Was hochgeladen wurde.
     *
     * Gelesen wird die Datei, nicht das, was der Browser ueber sie sagt: der
     * gemeldete Typ und der Dateiname stammen vom Absender. Geprueft wird
     * danach der Inhalt, siehe {@see Logo}.
     *
     * @throws LogoRejected
     */
    private static function bytesFrom(Request $request): string
    {
        $file = $request->files->get('logo');

        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw LogoRejected::unreadable();
        }

        $bytes = file_get_contents($file->getPathname());

        if (false === $bytes) {
            throw LogoRejected::unreadable();
        }

        return $bytes;
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('settings', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }
}
