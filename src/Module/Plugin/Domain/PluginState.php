<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Domain;

/**
 * Die Zustaende einer Installation.
 *
 * „Gefunden" fehlt hier mit Absicht: was nur im Verzeichnis liegt, steht
 * nirgends in der Datenbank und ist fuer die Anwendung nicht vorhanden.
 */
enum PluginState: string
{
    case Active = 'active';

    /**
     * Abgeschaltet, aber nicht weg.
     *
     * Keine Menuepunkte, keine Kacheln, keine Zustellungen, Token gesperrt —
     * Schema und Daten bleiben. Ohne diesen Zustand waere „kurz abschalten"
     * dasselbe wie „alles wegwerfen".
     */
    case Suspended = 'suspended';

    public function labelKey(): string
    {
        return 'plugin.state.'.$this->value;
    }
}
