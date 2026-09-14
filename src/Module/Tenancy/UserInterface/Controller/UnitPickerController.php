<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\UserInterface\Controller;

use App\Module\Property\Contract\UnitBrief;
use App\Module\Property\Contract\UnitDirectory;
use App\Module\Tenancy\Domain\TenancyPermissions;
use App\Module\Tenancy\Domain\TenancyRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Die Vervollstaendigung im Einheiten-Feld.
 *
 * Vermietete Einheiten stehen mit in den Treffern, aber gekennzeichnet und
 * nicht waehlbar: sie wegzulassen hiesse, jemanden suchen zu lassen, was er
 * gerade sieht — „warum finde ich WE 3 nicht?" ist eine schlechtere Frage als
 * „warum ist WE 3 grau?".
 */
#[IsGranted(TenancyPermissions::EDIT)]
final class UnitPickerController extends AbstractController
{
    private const int LIMIT = 10;

    public function __construct(
        private readonly UnitDirectory $units,
        private readonly TenancyRepository $tenancies,
    ) {
    }

    #[Route('/miete/einheiten/suche', name: 'app_tenancy_unit_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $found = $this->units->search($request->query->getString('q'), self::LIMIT);
        $let = $this->tenancies->forUnits(array_map(
            static fn (UnitBrief $unit): string => $unit->id,
            $found,
        ));

        return new JsonResponse(['results' => array_map(
            static fn (UnitBrief $unit): array => [
                'id' => $unit->id,
                'name' => $unit->label,
                'property' => $unit->propertyNumber.' · '.$unit->propertyName,
                'address' => $unit->address,
                'let' => self::isLet($let[$unit->id] ?? []),
            ],
            $found,
        )]);
    }

    /**
     * @param list<\App\Module\Tenancy\Domain\Tenancy> $tenancies
     */
    private static function isLet(array $tenancies): bool
    {
        foreach ($tenancies as $tenancy) {
            if ($tenancy->status()->isActive()) {
                return true;
            }
        }

        return false;
    }
}
