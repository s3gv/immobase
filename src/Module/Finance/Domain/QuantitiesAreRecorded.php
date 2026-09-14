<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use DomainException;

/**
 * Zu dieser Kostenposition sind schon Mengen je Einheit erfasst.
 *
 * Sie haengen an Einheiten eines bestimmten Objekts. Objekt oder
 * Verteilerschluessel danach zu wechseln liesse sie ins Leere zeigen: die
 * Seite zeigte sie nicht mehr, und bei „fertig verteilt" flossen ihre
 * Betraege trotzdem weiter in die Jahressumme.
 */
final class QuantitiesAreRecorded extends DomainException
{
}
