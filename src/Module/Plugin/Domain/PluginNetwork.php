<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Domain;

/**
 * Was ein Plugin-Prozess im Netz erreicht.
 *
 * Ein Plugin laeuft im Anwendungscontainer und saehe ohne Sperre, was der
 * Core sieht: die Datenbank, die Mails, andere Dienste, in einer Cloud die
 * Metadaten der Maschine. Erreichbar sind deshalb nur die Schnittstelle des
 * Cores und die Datenbank — dort meldet es sich mit seiner eigenen Rolle an.
 * Das Internet nur, wenn sein Manifest es verlangt und ein Administrator dem
 * beim Aktivieren zugestimmt hat; private Netze auch dann nicht.
 */
interface PluginNetwork
{
    /**
     * Setzt die Sperre fuer alle Plugins.
     *
     * @param list<int> $withInternet die Ports der Plugins, denen das Internet zugestanden ist
     */
    public function restrict(array $withInternet): void;
}
