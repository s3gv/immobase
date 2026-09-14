<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Ui;

/**
 * Das Vokabular der Standardaktionen.
 *
 * Bearbeiten sieht ueberall gleich aus und heisst ueberall gleich. Wer eine
 * Liste baut, waehlt aus diesem Vokabular, statt sich ein Symbol auszudenken —
 * sonst hat jede Seite ihr eigenes Zeichen fuer dieselbe Sache, und Nutzer
 * muessen jede Seite neu lernen.
 *
 * Faellt eine Aktion nicht darunter, gehoert sie als beschrifteter Knopf auf
 * die Seite, nicht als geraten zu deutendes Symbol.
 */
enum Action: string
{
    case Open = 'open';
    case Edit = 'edit';
    case Delete = 'delete';
    case Save = 'save';
    case Add = 'add';
    case Next = 'next';
    case Previous = 'previous';
    case Download = 'download';

    /**
     * Dateiname aus assets/images/icons/ ohne Endung.
     */
    public function icon(): string
    {
        // Eine Zuordnung und kein `match`: acht Aktionen sind acht Zeilen
        // Daten, und die Liste waechst mit jedem neuen Vokabeleintrag.
        $icons = [
            self::Open->value => 'arrow-right',
            self::Edit->value => 'pencil',
            self::Delete->value => 'trash',
            self::Save->value => 'save',
            self::Add->value => 'plus',
            self::Next->value => 'chevron-right',
            self::Previous->value => 'chevron-left',
            self::Download->value => 'download',
        ];

        return $icons[$this->value] ?? 'arrow-right';
    }

    public function labelKey(): string
    {
        return 'action.'.$this->value;
    }

    /**
     * Loeschen wird rot dargestellt und ist die einzige Aktion, die etwas
     * unwiederbringlich entfernt.
     */
    public function isDestructive(): bool
    {
        return self::Delete === $this;
    }
}
