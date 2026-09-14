<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Event;

/**
 * Etwas, das fachlich passiert ist.
 *
 * Domain-Events liegen im Contract-Verzeichnis ihres Moduls, weil sie Teil
 * seiner oeffentlichen Flaeche sind — andere Module hoeren darauf.
 *
 * Sie tragen ausschliesslich Primitive und Identifikatoren, niemals Entities.
 * Eine Entity im Event waere ein Schlupfloch, durch das ein fremdes Modul an
 * die Domaene des sendenden Moduls kaeme.
 */
interface DomainEvent
{
}
