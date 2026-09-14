<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\UserInterface\Controller;

use App\Module\Party\Contract\PartyBrief;
use App\Module\Party\Contract\PartyDirectory;
use App\Module\Property\Domain\PropertyPermissions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Die Vervollstaendigung im Eigentuemer-Feld.
 *
 * Der einzige Ort im Programm, der JSON zurueckgibt. Eine ganze Seite fuer
 * jeden Tastendruck neu zu zeichnen waere die Alternative, und die faehlt sich
 * an wie ein Katalog von 1998.
 *
 * Gesucht wird ueber PartyDirectory: dieses Modul kennt vom Stammdatenmodul
 * nur seinen Contract, nicht seine Entity.
 */
#[IsGranted(PropertyPermissions::EDIT)]
final class OwnerPickerController extends AbstractController
{
    private const int RESULTS = 8;

    public function __construct(private readonly PartyDirectory $parties)
    {
    }

    #[Route('/objekte/eigentuemer/suche', name: 'app_property_owner_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $found = $this->parties->search($request->query->getString('q'), self::RESULTS);

        return $this->json([
            'results' => array_map(static fn (PartyBrief $brief): array => [
                'id' => $brief->id,
                'reference' => $brief->reference,
                'name' => $brief->displayName,
                'address' => $brief->address,
            ], $found),
        ]);
    }
}
