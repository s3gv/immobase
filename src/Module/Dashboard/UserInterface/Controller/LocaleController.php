<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dashboard\UserInterface\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sprache der Oberflaeche umschalten.
 *
 * Die Wahl liegt in der Sitzung, nicht am Benutzerkonto: solange es keine
 * Benutzereinstellungen gibt, waere eine Spalte in der Tabelle Aufwand ohne
 * Gegenwert. Wandert die Wahl spaeter ans Konto, aendert sich nur diese Klasse.
 */
final class LocaleController extends AbstractController
{
    #[Route('/locale/{locale}', name: 'app_locale', requirements: ['locale' => 'de|en'], methods: ['GET'])]
    public function switch(string $locale, Request $request): Response
    {
        $request->getSession()->set('_locale', $locale);

        $target = self::localTarget($request);

        return null === $target
            ? $this->redirectToRoute('app_dashboard')
            : $this->redirect($target);
    }

    /**
     * Der Pfad, von dem der Aufruf kam — oder null.
     *
     * Es wird nur der Pfad samt Abfrage zurueckgegeben, nie die vollstaendige
     * Adresse aus dem Referer. Selbst wenn die Herkunftspruefung eine Luecke
     * haette, kann das Ziel damit nicht ausserhalb dieser Anwendung liegen.
     */
    private static function localTarget(Request $request): ?string
    {
        $referer = $request->headers->get('referer');

        if (null === $referer) {
            return null;
        }

        $parts = parse_url($referer);

        if (!\is_array($parts) || !self::isSameOrigin($parts, $request)) {
            return null;
        }

        $path = \is_string($parts['path'] ?? null) ? $parts['path'] : '/';

        if (!str_starts_with($path, '/')) {
            return null;
        }

        return $path.(\is_string($parts['query'] ?? null) ? '?'.$parts['query'] : '');
    }

    /**
     * Schema, Host und Port einzeln vergleichen.
     *
     * Ein blosses str_starts_with auf die eigene Adresse reicht nicht: fuer
     * "https://immobase.example" beginnt auch "https://immobase.example.evil/"
     * damit, und die Weiterleitung ginge zu einer fremden Domain.
     *
     * @param array<string, mixed> $parts
     */
    private static function isSameOrigin(array $parts, Request $request): bool
    {
        $port = $parts['port'] ?? $request->getPort();

        return ($parts['scheme'] ?? null) === $request->getScheme()
            && ($parts['host'] ?? null) === $request->getHost()
            && \is_int($port) && $port === $request->getPort();
    }
}
