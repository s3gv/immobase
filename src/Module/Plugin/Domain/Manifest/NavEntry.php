<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Domain\Manifest;

/**
 * Ein Menuepunkt, den ein Plugin beisteuert.
 *
 * Der Pfad ist der Weg beim Plugin, nicht beim Core: der Core haengt ihn an
 * seine eigene Proxy-Adresse. Ein Manifest kann damit nirgends hin verlinken,
 * wo es nicht selbst antwortet.
 */
final readonly class NavEntry
{
    /**
     * Die Symbole, die ein Plugin waehlen darf.
     *
     * Eine feste Liste und kein freier Dateiname: die Vorlage bindet das
     * Symbol aus dem Verzeichnis ein, und ein Name aus einer fremden Datei
     * waere ein Weg ins Dateisystem.
     *
     * @var non-empty-list<string>
     */
    public const array ICONS = [
        'puzzle', 'archive', 'calculator', 'calendar', 'chart', 'clock',
        'euro', 'house', 'key', 'mail', 'shield', 'sliders', 'users',
    ];

    /**
     * @param array<string, string> $labels
     */
    public function __construct(
        public string $path,
        public array $labels,
        public string $permission,
        public string $icon = 'puzzle',
    ) {
    }
}
