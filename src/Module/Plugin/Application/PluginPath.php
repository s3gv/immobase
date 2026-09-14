<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Application;

/**
 * Ein Pfad aus der Adresszeile, den der Core beim Plugin abrufen darf.
 *
 * Geprueft wird das Recht am Menuepunkt, unter den der Pfad faellt — und das
 * nur, wenn beim Plugin genau dieser Pfad ankommt. `/berichte/..%2Fintern`
 * faellt nach der Zeichenkette unter `/berichte`, der HTTP-Client macht daraus
 * aber `/intern`, und dort koennte ein strengeres Recht stehen oder gar kein
 * Menuepunkt. Ebenso `//`, das manche Router zu einem Schraegstrich
 * zusammenziehen.
 *
 * Abgelehnt wird deshalb alles, was unterwegs noch umgedeutet werden koennte:
 * Punkt-Segmente, leere Segmente, Backslash, Prozentzeichen, Frage- und
 * Doppelkreuz, Steuerzeichen.
 */
final class PluginPath
{
    private function __construct()
    {
    }

    public static function orNull(string $path): ?string
    {
        $path = '/'.ltrim($path, '/');

        if (1 === preg_match('/[\x00-\x20\x7F%\\\\?#]/', $path)) {
            return null;
        }

        $segments = explode('/', rtrim(substr($path, 1), '/'));

        foreach ($segments as $index => $segment) {
            if ('.' === $segment || '..' === $segment || ('' === $segment && [''] !== $segments && 0 !== $index)) {
                return null;
            }
        }

        return $path;
    }
}
