<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dashboard\UserInterface\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liefert das Stylesheet unter einem stabilen, versionierten Pfad aus.
 *
 * Teil der oeffentlichen Plugin-Grenze (siehe docs/architecture/plugin-boundary.md):
 * ein Plugin soll nativ aussehen koennen, ohne CSS zu kopieren. Der von
 * AssetMapper erzeugte Dateiname traegt eine Pruefsumme und aendert sich bei
 * jeder Aenderung — als oeffentliche Zusage taugt er nicht.
 *
 * Es ist eine Referenz zur Laufzeit, keine Uebernahme von Code: die Datei wird
 * ausgeliefert, nicht in ein Plugin hineinkopiert.
 */
final class PublicAssetController extends AbstractController
{
    private const SOURCE = 'styles/app.css';

    public function __construct(private readonly AssetMapperInterface $assets)
    {
    }

    #[Route('/plugin-api/v1/theme.css', name: 'app_plugin_theme_css', methods: ['GET'])]
    public function themeCss(Request $request): Response
    {
        $asset = $this->assets->getAsset(self::SOURCE);

        if (null === $asset) {
            throw $this->createNotFoundException('Das Stylesheet ist nicht auffindbar.');
        }

        // content statt sourcePath: die Quelldatei ist Tailwind-Eingabe und
        // beginnt mit @import "tailwindcss". Ein Plugin bekaeme damit keine
        // Gestaltung, sondern eine Anweisung, die es nicht ausfuehren kann.
        // Gefuellt ist content nur, wenn ein Compiler den Inhalt ersetzt hat —
        // genau das tut das Tailwind-Bundle.
        $css = $asset->content ?? file_get_contents($asset->sourcePath);

        if (!\is_string($css)) {
            throw $this->createNotFoundException('Das Stylesheet ist nicht lesbar.');
        }

        return $this->respond($request, $css, $asset->digest);
    }

    /**
     * Antwortet mit dem Stylesheet, oder mit 304, wenn der Abrufer es schon hat.
     *
     * Der stabile Pfad kostet, was der Pruefsummen-Dateiname geleistet hat:
     * eine geaenderte Datei traegt dieselbe Adresse wie vorher. Nur die
     * Rueckfrage holt das zurueck — und dafuer muss sie auch stattfinden.
     *
     * Deshalb "no-cache" und keine Frist: mit einer Frist fragt niemand nach,
     * solange sie laeuft, und ein Plugin liefe nach einer Aenderung am
     * Designsystem mit veralteter Gestaltung weiter. Gespeichert werden darf
     * die Antwort weiterhin; unveraendert kostet sie dann nur ein 304 ohne
     * Rumpf.
     */
    private function respond(Request $request, string $css, string $digest): Response
    {
        $response = new Response();
        $response->setEtag($digest);
        $response->headers->set('Content-Type', 'text/css; charset=UTF-8');
        $response->setPublic();
        $response->headers->addCacheControlDirective('no-cache');
        // Oeffentlich zugesagte Gestaltung ohne Daten darin — ein Plugin laeuft
        // in einem eigenen Prozess und oft unter einem eigenen Namen.
        $response->headers->set('Access-Control-Allow-Origin', '*');

        if ($response->isNotModified($request)) {
            return $response;
        }

        return $response->setContent($css);
    }
}
