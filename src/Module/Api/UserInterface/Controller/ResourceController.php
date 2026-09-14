<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Api\UserInterface\Controller;

use App\Module\Api\Application\ResourceCatalogue;
use App\Module\Plugin\Application\IdentifyPlugin;
use App\Module\Plugin\Contract\PluginIdentity;
use App\Shared\Api\ApiQuery;
use App\Shared\Api\PublishesResource;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Der eine Eingang zu allen Ressourcen.
 *
 * **Eine Stelle und nicht eine je Modul.** Die Pruefung — gibt es ein Token,
 * gehoert es zu einem aktiven Plugin, steht das Recht dieser Ressource in
 * dem, was jemand bestaetigt hat — passiert hier und nirgends sonst. Ein
 * Controller je Ressource waere ein Dutzend Gelegenheiten, sie zu vergessen,
 * und die dreizehnte kaeme ungeprueft davon.
 *
 * Ein Test geht den ganzen Katalog durch und verlangt von jeder Ressource
 * dieselbe Absage. Damit ist auch die geschuetzt, die es morgen erst gibt.
 *
 * **Keine Sitzung, kein CSRF, keine Weiterleitung auf `/login`.** Ein Plugin
 * ist kein Browser; eine Anmeldeseite als Antwort waere fuer es dasselbe wie
 * Schweigen.
 */
final class ResourceController
{
    public function __construct(
        private readonly ResourceCatalogue $catalogue,
        private readonly IdentifyPlugin $identify,
    ) {
    }

    #[Route('/api/v1/{resource}', name: 'api_v1_list', methods: ['GET'], requirements: ['resource' => '[a-z][a-z-]*'])]
    public function list(string $resource, Request $request): JsonResponse
    {
        $found = $this->allowed($resource, $request);

        if (!$found instanceof PublishesResource) {
            return $found;
        }

        $page = $found->page(new ApiQuery(max(1, $request->query->getInt('page', 1))));

        return new JsonResponse([
            'data' => $page->data,
            'page' => $page->page,
            'pages' => $page->pages,
            'total' => $page->total,
        ]);
    }

    #[Route('/api/v1/{resource}/{id}', name: 'api_v1_one', methods: ['GET'], requirements: ['resource' => '[a-z][a-z-]*', 'id' => '[^/]+'])]
    public function one(string $resource, string $id, Request $request): JsonResponse
    {
        $found = $this->allowed($resource, $request);

        if (!$found instanceof PublishesResource) {
            return $found;
        }

        $record = $found->one($id);

        return null === $record
            ? self::fault(Response::HTTP_NOT_FOUND, 'not_found', 'Diesen Datensatz gibt es nicht.')
            : new JsonResponse(['data' => $record]);
    }

    /**
     * Die Ressource — oder die Absage, die stattdessen hinausgeht.
     *
     * **Unbekannt und unerlaubt sind zweierlei.** Wer das Recht nicht hat,
     * bekommt 403 und nicht 404: was es gibt, steht ohnehin in der
     * Dokumentation, und eine verschwiegene Ressource waere fuer den, der sie
     * zu Recht sucht, nur ein Raetsel.
     */
    private function allowed(string $resource, Request $request): PublishesResource|JsonResponse
    {
        $identity = ($this->identify)(self::tokenFrom($request));

        if (!$identity instanceof PluginIdentity) {
            return self::fault(Response::HTTP_UNAUTHORIZED, 'unauthorized', 'Kein gültiges Token.');
        }

        $found = $this->catalogue->named($resource);

        if (!$found instanceof PublishesResource) {
            return self::fault(Response::HTTP_NOT_FOUND, 'unknown_resource', 'Diese Ressource gibt es nicht.');
        }

        return $identity->mayRead($found->permission())
            ? $found
            : self::fault(Response::HTTP_FORBIDDEN, 'forbidden', 'Dafür fehlt diesem Plugin die Freigabe.');
    }

    private static function tokenFrom(Request $request): string
    {
        $header = $request->headers->get('Authorization', '');

        return str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';
    }

    private static function fault(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
