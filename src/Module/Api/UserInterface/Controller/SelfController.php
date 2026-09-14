<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Api\UserInterface\Controller;

use App\Module\Plugin\Application\IdentifyPlugin;
use App\Module\Plugin\Application\SaveTiles;
use App\Module\Plugin\Application\StorageFor;
use App\Module\Plugin\Contract\PluginIdentity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Was ein Plugin ueber sich selbst erfaehrt.
 *
 * Nur ueber sich: der Name, die bestaetigten Lesebereiche und die
 * Zugangsdaten zu seinem eigenen Schema. Ueber ein anderes Plugin erfaehrt es
 * hier nichts — und ueber den Core auch nichts, was nicht ohnehin in der
 * Dokumentation steht.
 *
 * **Vor den Ressourcen einsortiert.** Sonst lase die allgemeine Route
 * `/api/v1/plugins/self` als Ressource „plugins" mit der Kennung „self" — und
 * die gibt es nicht.
 */
final class SelfController
{
    public function __construct(
        private readonly IdentifyPlugin $identify,
        private readonly StorageFor $storage,
        private readonly SaveTiles $tiles,
    ) {
    }

    #[Route('/api/v1/plugins/self', name: 'api_v1_self', methods: ['GET'], priority: 10)]
    public function self(Request $request): JsonResponse
    {
        $identity = $this->identify($request);

        if (!$identity instanceof PluginIdentity) {
            return self::unauthorized();
        }

        return new JsonResponse(['data' => ['name' => $identity->name, 'reads' => $identity->reads]]);
    }

    /**
     * Die eigene Datenbankverbindung.
     *
     * Sie fuehrt ausschliesslich in das eigene Schema: die Rolle darf
     * anderswo nichts, und das ist der Grund, warum Fachdaten ueber diese
     * Schnittstelle gehen und nicht ueber die Tabellen des Cores.
     */
    #[Route('/api/v1/plugins/self/storage', name: 'api_v1_self_storage', methods: ['GET'], priority: 10)]
    public function storage(Request $request): JsonResponse
    {
        $identity = $this->identify($request);

        if (!$identity instanceof PluginIdentity) {
            return self::unauthorized();
        }

        $dsn = ($this->storage)($identity->name);

        return null === $dsn
            ? new JsonResponse(['error' => ['code' => 'no_storage', 'message' => 'Kein Speicher vorhanden.']], Response::HTTP_NOT_FOUND)
            : new JsonResponse(['data' => ['dsn' => $dsn, 'schema' => 'plugin_'.$identity->name]]);
    }

    /**
     * Die Kacheln, die das Plugin auf die Uebersicht stellt.
     *
     * Alle auf einmal und ersetzend: eine Kachel, die nicht mehr mitkommt,
     * soll verschwinden und nicht als letzter bekannter Stand stehen
     * bleiben. Geprueft wird trotzdem jede einzelne — was nicht passt, faellt
     * raus, und die Antwort sagt, wie viele es geworden sind.
     */
    #[Route('/api/v1/plugins/self/tiles', name: 'api_v1_self_tiles', methods: ['PUT'], priority: 10)]
    public function saveTiles(Request $request): JsonResponse
    {
        $identity = $this->identify($request);

        if (!$identity instanceof PluginIdentity) {
            return self::unauthorized();
        }

        $sent = json_decode($request->getContent(), true);

        if (!\is_array($sent)) {
            return new JsonResponse(
                ['error' => ['code' => 'bad_request', 'message' => 'Erwartet wird eine Liste von Kacheln.']],
                Response::HTTP_BAD_REQUEST,
            );
        }

        /** @var list<array<string, mixed>> $tiles */
        $tiles = array_values(array_filter($sent, is_array(...)));
        ($this->tiles)($identity->name, $tiles);

        return new JsonResponse(['data' => ['tiles' => \count($tiles)]]);
    }

    private function identify(Request $request): ?PluginIdentity
    {
        $header = $request->headers->get('Authorization', '');

        return ($this->identify)(str_starts_with($header, 'Bearer ') ? substr($header, 7) : '');
    }

    private static function unauthorized(): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'unauthorized', 'message' => 'Kein gültiges Token.']],
            Response::HTTP_UNAUTHORIZED,
        );
    }
}
