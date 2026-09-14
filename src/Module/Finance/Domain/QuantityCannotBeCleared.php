<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use DomainException;

/**
 * Eine erfasste Menge wird berichtigt, nicht geleert.
 *
 * Ein leeres Feld an einer Einheit, zu der noch nichts steht, heisst „liegt
 * nicht vor". An einer erfassten Menge hiesse es, sie zu loeschen — und
 * damit liesse sich ein Jahr Feld fuer Feld leerraeumen und danach ganz
 * entfernen. Die Sperre am Jahreswert waere einen Umweg weit umgehbar.
 *
 * „Kein Verbrauch" ist die Null und keine Leere.
 */
final class QuantityCannotBeCleared extends DomainException
{
}
