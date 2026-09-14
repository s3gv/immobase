<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\FiscalPeriod;
use App\Module\Property\Contract\PropertyDirectory;
use DateTimeImmutable;

/**
 * Wann das Wirtschaftsjahr eines Objekts beginnt.
 *
 * Die Regel steht am Objekt — Tag und Monat des Beginns — und gilt fuer jedes
 * Jahr. Hier wird sie mit der Jahreszahl zusammengesetzt, **einmal**: beim
 * Anlegen eines Laufs. Danach traegt der Lauf seinen Zeitraum selbst, denn
 * die Regel am Objekt darf sich aendern und der abgerechnete Zeitraum nicht.
 */
final readonly class StatementPeriod
{
    public function __construct(private PropertyDirectory $properties)
    {
    }

    public function of(string $propertyId, int $fiscalYear): FiscalPeriod
    {
        $property = $this->properties->byIds([$propertyId])[$propertyId] ?? null;

        return FiscalPeriod::beginningOn(new DateTimeImmutable(\sprintf(
            '%04d-%02d-%02d',
            $fiscalYear,
            null === $property ? 1 : $property->fiscalYearMonth,
            null === $property ? 1 : $property->fiscalYearDay,
        )));
    }
}
