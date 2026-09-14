<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use App\Shared\Ui\Page;

/**
 * Zugriff auf die Bewegungen der Erhaltungsruecklage.
 */
interface ReserveMovementRepository
{
    public function save(ReserveMovement $movement): void;

    public function byId(string $id): ?ReserveMovement;

    /**
     * Die Bewegungen dieser Objekte, je Objekt als Stand.
     *
     * Gefragt wird fuer eine ganze Seite auf einmal, nicht je Zeile.
     *
     * @param list<string> $propertyIds
     *
     * @return array<string, ReserveBalance>
     */
    public function forProperties(array $propertyIds): array;

    public function countAll(): int;

    /**
     * Alle Bewegungen, seitenweise und nach Datum.
     *
     * Fuer die Schnittstelle: eine Ruecklagenentwicklung entsteht aus den
     * Bewegungen, und die holt sich ein Plugin in Portionen ab.
     *
     * @return list<ReserveMovement>
     */
    public function pageOf(Page $page): array;
}
