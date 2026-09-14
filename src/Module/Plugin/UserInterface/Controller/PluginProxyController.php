<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\UserInterface\Controller;

use App\Module\Plugin\Application\ActiveManifests;
use App\Module\Plugin\Application\AsksThePlugin;
use App\Module\Plugin\Application\PluginPath;
use App\Module\Plugin\Domain\Manifest\Manifest;
use App\Module\Plugin\Domain\Manifest\NavEntry;
use App\Module\Plugin\Domain\PluginRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Die Seite eines Plugins, in unserer Shell.
 *
 * **Das Recht steht am Menuepunkt.** Geprueft wird nicht „darf jemand
 * Plugins sehen", sondern das Recht, das im Manifest an genau diesem Eintrag
 * haengt — sonst oeffnete der Zugang zu einem Plugin den zu allen.
 *
 * **Ein Pfad ohne Menuepunkt gibt es nicht.** Ein Plugin kann damit keine
 * Seite ausliefern, die niemand bestaetigt hat, und der Core ruft keine
 * Adresse ab, die aus der Adresszeile kommt.
 *
 * Nicht aktiviert heisst `404` und nicht `403`: eine Seite, die es nicht
 * gibt, gibt es nicht — das ist keine Frage von Rechten.
 */
final class PluginProxyController extends AbstractController
{
    public function __construct(
        private readonly ActiveManifests $manifests,
        private readonly PluginRepository $plugins,
        private readonly AsksThePlugin $asks,
    ) {
    }

    #[Route('/plugins/{plugin}/{path}', name: 'app_plugin_page', methods: ['GET'], requirements: ['plugin' => '[a-z][a-z0-9_]*', 'path' => '.*'])]
    public function page(string $plugin, string $path, Request $request): Response
    {
        $manifest = $this->manifests->of($plugin)
            ?? throw $this->createNotFoundException(\sprintf('Kein aktives Plugin namens „%s".', $plugin));

        $path = PluginPath::orNull($path)
            ?? throw $this->createNotFoundException('Dieser Pfad wird nicht an ein Plugin weitergegeben.');
        $entry = $this->entryFor($manifest, $path);

        if (null === $entry) {
            throw $this->createNotFoundException('Diese Seite steht in keinem Menüeintrag des Plugins.');
        }

        $this->denyAccessUnlessGranted($entry->permission);

        $installed = $this->plugins->byName($plugin)
            ?? throw $this->createNotFoundException(\sprintf('Kein aktives Plugin namens „%s".', $plugin));

        $fragment = $this->asks->fragment($installed, $path, $request);

        return $this->render('plugin/page.html.twig', [
            'plugin' => $plugin,
            'title' => $entry->labels[$request->getLocale()] ?? $entry->labels['en'] ?? $plugin,
            'fragment' => $fragment,
        ], new Response(null, null === $fragment ? Response::HTTP_BAD_GATEWAY : Response::HTTP_OK));
    }

    /**
     * Der Menueeintrag, unter den dieser Pfad faellt.
     *
     * Ein Eintrag deckt seinen Pfad und alles darunter ab: „/kosten" deckt
     * „/kosten/objekt/4711". Sonst haette ein Plugin nur so viele Seiten wie
     * Menuepunkte, und jede Unterseite waere eine Aenderung am Manifest.
     *
     * Der laengste Treffer gewinnt. Traegt ein Plugin unter „/kosten" noch
     * „/kosten/intern" mit einem anderen Recht ein, gilt dort das andere —
     * die Alternative waere, dass das kuerzere Recht das genauere aushebelt.
     */
    private function entryFor(Manifest $manifest, string $path): ?NavEntry
    {
        $match = null;

        foreach ($manifest->nav as $entry) {
            if ($entry->path !== $path && !str_starts_with($path, rtrim($entry->path, '/').'/')) {
                continue;
            }

            if (null === $match || \strlen($entry->path) > \strlen($match->path)) {
                $match = $entry;
            }
        }

        return $match;
    }
}
