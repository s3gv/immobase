<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Domain;

use DateTimeImmutable;

interface DeliveryRepository
{
    /**
     * @param list<Delivery> $deliveries
     */
    public function append(array $deliveries): void;

    /**
     * Was jetzt faellig ist.
     *
     * @return list<Delivery>
     */
    public function due(DateTimeImmutable $now, int $limit): array;

    /** Zugestellt und damit erledigt. */
    public function forget(string $id): void;

    /** Noch einmal, spaeter. */
    public function retryLater(string $id, DateTimeImmutable $at, string $error): void;

    /** Aufgegeben: die Zeile bleibt mit ihrem Vermerk, bis sie verfaellt. */
    public function giveUp(string $id, string $error): void;

    /** Was zu einem Plugin gehoert, geht mit ihm. */
    public function forgetPlugin(string $plugin): void;

    /** Aufgegebene Zustellungen, die aelter sind als die Frist. */
    public function sweep(DateTimeImmutable $before): int;
}
