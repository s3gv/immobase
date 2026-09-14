<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use DomainException;

/**
 * Die Einheit gehoert nicht zu diesem Objekt.
 *
 * Die Oberflaeche bietet nur die eigenen an — aber ein abgeschicktes
 * Formular ist Eingabe und keine Zusicherung. Ohne diese Pruefung liesse
 * sich eine fremde Einheit unterschieben, und ihr Betrag flosse unsichtbar
 * in eine Summe ein, in der er nichts zu suchen hat: die Ansicht zeigt ja
 * nur die Einheiten des eigenen Objekts.
 */
final class UnitBelongsElsewhere extends DomainException
{
}
