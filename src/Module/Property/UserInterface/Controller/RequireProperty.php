<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\UserInterface\Controller;

use App\Module\Property\Domain\Property;
use App\Module\Property\Domain\PropertyRepository;
use App\Module\Property\Domain\Unit;
use App\Module\Property\Domain\UnitRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Holt ein Objekt oder eine Einheit — oder endet mit 404.
 *
 * Als eigener Dienst und nicht als Methode im Controller: dieselben zwei
 * Zeilen stehen sonst in jedem Aufruf, und beim zehnten fehlt die Pruefung.
 */
final readonly class RequireProperty
{
    public function __construct(
        private PropertyRepository $properties,
        private UnitRepository $units,
    ) {
    }

    public function __invoke(int $number): Property
    {
        return $this->properties->byNumber($number)
            ?? throw new NotFoundHttpException(\sprintf('Kein Objekt mit der Nummer %d.', $number));
    }

    public function unit(Property $property, int $number): Unit
    {
        return $this->units->byNumber($property, $number)
            ?? throw new NotFoundHttpException(\sprintf('Das Objekt %d hat keine Einheit %d.', $property->number(), $number));
    }
}
