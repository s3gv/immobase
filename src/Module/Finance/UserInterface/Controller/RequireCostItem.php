<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\UserInterface\Controller;

use App\Module\Finance\Domain\CostItem;
use App\Module\Finance\Domain\CostItemRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Holt eine Kostenposition — oder endet mit 404.
 *
 * Als eigener Dienst und nicht als Methode im Controller: dieselben zwei
 * Zeilen stehen sonst in jedem Aufruf, und beim zehnten fehlt die Pruefung.
 */
final readonly class RequireCostItem
{
    public function __construct(private CostItemRepository $items)
    {
    }

    public function __invoke(int $number): CostItem
    {
        return $this->items->byNumber($number)
            ?? throw new NotFoundHttpException(\sprintf('Keine Kostenposition mit der Nummer %d.', $number));
    }
}
