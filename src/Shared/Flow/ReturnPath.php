<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Flow;

use App\Shared\Http\LocalUrl;
use Symfony\Component\HttpFoundation\Request;

/**
 * Wohin ein Ablauf zurueckfuehrt, wenn ihn jemand von woanders betreten hat.
 *
 * Der Fall: beim Anlegen eines Objekts fehlt ein Eigentuemer, also legt man
 * mitten drin einen Kontakt an. Danach dort weiterzumachen, wo man war, ist
 * das Mindeste — sonst sucht man die halbe Eingabe wieder zusammen.
 *
 * Die Adresse steht im Ablaufzustand und nicht in der Adresszeile jedes
 * Schritts: sonst muesste sie jeder Schritt weiterreichen, und beim dritten
 * faellt sie heraus. Sie ist dort ein Eintrag wie ein Schritt, den kein
 * Schritt liest.
 */
final class ReturnPath
{
    private const string KEY = 'weiter';

    private function __construct()
    {
    }

    /** Nimmt `?weiter=` entgegen — aber nur einen Pfad auf diesem Server. */
    public static function remember(Request $request, FlowState $state): void
    {
        $path = LocalUrl::orNull($request->query->getString(self::KEY));

        if (null !== $path) {
            $state->remember(self::KEY, [self::KEY => $path]);
        }
    }

    /**
     * Die gemerkte Adresse, mit der neuen Kennung im Gepaeck.
     *
     * Damit muss niemand den gerade angelegten Datensatz noch einmal suchen —
     * die Seite, die zurueckbekommt, kann ihn gleich einsetzen.
     */
    public static function with(FlowState $state, string $id): ?string
    {
        $value = $state->valuesFor(self::KEY)[self::KEY] ?? null;
        $path = \is_string($value) ? LocalUrl::orNull($value) : null;

        return null === $path ? null : $path.(str_contains($path, '?') ? '&' : '?').'neu='.$id;
    }
}
